<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppDeliveryStatus;
use App\WhatsAppEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Meta's delivery webhook (PH-WA-8, REQ-WA-32, REQ-WA-52 to REQ-WA-58).
 *
 * - GET: the verify token is compared with the saved one in constant time.
 * - POST: the signature is checked on the raw body, against the App Secret saved for
 *   each environment, before anything in the request is parsed or read. Only then is
 *   the body decoded, and each change is taken only when its phone_number_id is the
 *   one of an environment whose secret signed the request (REQ-WA-54).
 * - A status is applied with one conditional UPDATE, only to a message sent from that
 *   very environment (decision 8.4): sent, then delivered, then read, never backwards;
 *   failed only from no status or sent, and final (decision 8.1). Repeated and late
 *   statuses change nothing.
 * - An applied failed invalidates the OTP code of that message when it is still not
 *   used, not invalidated and not expired, in the same transaction (REQ-WA-32).
 * - Incoming messages and any other field are ignored, never stored (REQ-WA-57).
 * - Nothing of the body, no phone number, no error text and no secret is ever logged
 *   or stored: the status, its time and Meta's numeric error code only.
 */
class WhatsAppWebhook
{
    /** Largest POST body accepted, checked before the signature (decision 8.3). */
    public const MAX_BYTES = 262144;

    /** Why a code was invalidated by a failed delivery. */
    public const INVALIDATION_REASON = 'delivery_failed';

    /** The statuses a new one may follow (decision 8.1); null is no status yet. */
    private const FOLLOWS = [
        'sent' => [null],
        'delivered' => [null, 'sent'],
        'read' => [null, 'sent', 'delivered'],
        'failed' => [null, 'sent'],
    ];

    /**
     * Whether a verification request carries the saved verify token (REQ-WA-52).
     */
    public function verifies(mixed $mode, #[SensitiveParameter] mixed $token): bool
    {
        $saved = WhatsAppSetting::query()->first()?->webhook_verify_token;

        return $mode === 'subscribe'
            && is_string($token) && $token !== ''
            && is_string($saved) && $saved !== ''
            && hash_equals($saved, $token);
    }

    /**
     * The environments whose saved App Secret signed this raw body (REQ-WA-53, REQ-WA-54).
     * Nothing from the request is used but the body and the signature header.
     *
     * @return list<WhatsAppEnvironment>
     */
    public function signedBy(string $raw, mixed $header): array
    {
        if (! is_string($header) || ! preg_match('/\Asha256=([0-9a-fA-F]{64})\z/', $header, $matches)) {
            return [];
        }

        $setting = WhatsAppSetting::query()->first();
        $signature = strtolower($matches[1]);
        $signed = [];

        foreach (WhatsAppEnvironment::cases() as $environment) {
            $secret = $setting?->field($environment, 'app_secret');

            if (is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
                $signed[] = $environment;
            }
        }

        return $signed;
    }

    /**
     * Apply the statuses of a signed request.
     *
     * @param  array<mixed>  $payload
     * @param  list<WhatsAppEnvironment>  $signed  The environments whose secret signed it.
     * @return array{applied: int, ignored: int}
     */
    public function process(array $payload, array $signed): array
    {
        $setting = WhatsAppSetting::query()->first();
        $numbers = [];

        foreach ($signed as $environment) {
            $id = $setting?->field($environment, 'phone_number_id');

            if (is_string($id) && $id !== '') {
                $numbers[$environment->value] = $id;
            }
        }

        $counts = ['applied' => 0, 'ignored' => 0];

        foreach ($this->list($payload['entry'] ?? null) as $entry) {
            foreach ($this->list(is_array($entry) ? ($entry['changes'] ?? null) : null) as $change) {
                $value = is_array($change) && ($change['field'] ?? null) === 'messages' && is_array($change['value'] ?? null) ? $change['value'] : null;
                $number = $value['metadata']['phone_number_id'] ?? null;
                $environment = is_scalar($number) ? array_search((string) $number, $numbers, true) : false;

                if ($environment === false) {
                    // Another field, or a number that is not the one of the environment that signed.
                    $counts['ignored']++;

                    continue;
                }

                // Incoming messages ($value['messages']) are never read (REQ-WA-57).
                foreach ($this->list($value['statuses'] ?? null) as $status) {
                    $counts[is_array($status) && $this->apply(WhatsAppEnvironment::from($environment), $status) ? 'applied' : 'ignored']++;
                }
            }
        }

        return $counts;
    }

    /**
     * @param  array<mixed>  $status
     */
    private function apply(WhatsAppEnvironment $environment, array $status): bool
    {
        $wamid = $status['id'] ?? null;
        $state = WhatsAppDeliveryStatus::tryFrom(is_string($status['status'] ?? null) ? $status['status'] : '');

        if (! is_string($wamid) || $wamid === '' || strlen($wamid) > 128 || $state === null) {
            return false;
        }

        $at = $this->time($status['timestamp'] ?? null)->format('Y-m-d H:i:s');
        $follows = self::FOLLOWS[$state->value];

        return DB::transaction(function () use ($environment, $wamid, $state, $status, $at, $follows) {
            $values = ['delivery_status' => $state->value, $state->value.'_at' => $at, 'updated_at' => now()];

            if ($state === WhatsAppDeliveryStatus::Failed) {
                $code = $status['errors'][0]['code'] ?? null;
                $values['delivery_error_code'] = is_scalar($code) && (string) $code !== '' ? substr((string) $code, 0, 32) : null;
            }

            $applied = DB::table('whatsapp_message_logs')
                ->where('wamid', $wamid)
                ->where('environment', $environment->value)
                ->where(fn ($where) => $where->whereNull('delivery_status')->orWhereIn('delivery_status', array_values(array_filter($follows))))
                ->update($values) === 1;

            if ($applied && $state === WhatsAppDeliveryStatus::Failed) {
                $this->invalidateCode($wamid);
            }

            return $applied;
        });
    }

    /**
     * REQ-WA-32, REQ-WA-33: the code of a message that failed is no longer usable, if it
     * still was. A code already used, invalidated or expired is left as it is.
     */
    private function invalidateCode(string $wamid): void
    {
        $code = DB::table('whatsapp_message_logs')->where('wamid', $wamid)->value('one_time_code_id');

        if ($code === null) {
            return;
        }

        $now = now();

        DB::table('one_time_codes')
            ->where('id', $code)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', $now)
            ->update(['invalidated_at' => $now, 'invalidation_reason' => self::INVALIDATION_REASON, 'updated_at' => $now]);
    }

    /**
     * Meta's time for the status when it is a valid Unix time, otherwise the time it arrived.
     */
    private function time(mixed $timestamp): CarbonImmutable
    {
        $valid = (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) && (int) $timestamp > 0 && (int) $timestamp <= 4102444800;

        return $valid ? CarbonImmutable::createFromTimestamp((int) $timestamp) : CarbonImmutable::now();
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }
}
