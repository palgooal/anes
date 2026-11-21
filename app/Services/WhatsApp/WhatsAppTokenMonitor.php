<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Follows the access tokens saved in the settings row (REQ-WA-21, REQ-WA-22,
 * AC-WA-15, AC-WA-40, PH-WA-7): a daily debug_token check of the test and production
 * tokens, the rejection of a saved token by Meta while sending, and the alerts the
 * panel shows. The .env token is never checked (decision 3).
 *
 * - One classification for every answer (decision 2): "valid" or "invalid" are
 *   confirmed results; a network failure, a timeout, HTTP 429 or 5xx, or any other
 *   error is "check_failed" and never means that the token was revoked. Only
 *   OAuth error 190 in a 4xx answer is "invalid" (to be confirmed: PND-WA-04).
 * - The last confirmed state is kept apart from a failed attempt.
 * - Results are ordered by the time their attempt started, written in one
 *   conditional UPDATE: a result is applied only to the very token it was about
 *   (its stored ciphertext), and only if no result of an attempt that started at
 *   the same time or later is already stored. So an older or slower check can never
 *   overwrite a newer one, a failed check never hides a newer confirmed one, and a
 *   result about a replaced token is dropped.
 * - Nothing of the token, and nothing Meta answered, is ever stored or logged.
 */
class WhatsAppTokenMonitor
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const CHECK_FAILED = 'check_failed';

    /** Meta's OAuth error for an access token that is no longer valid (PND-WA-04). */
    public const INVALID_TOKEN_CODE = 190;

    /** Days before the expiry date at which the alert starts, then rises (REQ-WA-22). */
    public const ALERT_DAYS = [14, 7, 3, 1];

    /** Attempt times are stored to the microsecond, as text of fixed width. */
    public const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private MetaGraphClient $meta) {}

    /**
     * Whether an error answer proves the token invalid (decision 2, decision 4).
     * Everything else, the network included, is a failed check.
     */
    public static function classifyError(?int $httpStatus, mixed $metaCode): string
    {
        return $httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500
            && is_numeric($metaCode) && (int) $metaCode === self::INVALID_TOKEN_CODE
            ? self::INVALID
            : self::CHECK_FAILED;
    }

    /**
     * The time an attempt starts, taken before the request to Meta.
     */
    public static function startedAt(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * Check the saved token of one environment with debug_token (REQ-WA-22).
     *
     * @return array{result: ?string, applied: bool} result null: no settings row or no token saved.
     */
    public function check(WhatsAppEnvironment $environment): array
    {
        $setting = WhatsAppSetting::query()->first();
        $column = $environment->column('access_token');
        $cipher = $setting?->getRawOriginal($column);

        if (! is_string($cipher) || $cipher === '') {
            return ['result' => null, 'applied' => false];
        }

        $token = $setting->getAttribute($column);

        if (! is_string($token) || trim($token) === '') {
            return ['result' => null, 'applied' => false];
        }

        $started = self::startedAt();
        $inspection = null;

        try {
            $inspection = $this->meta->inspectToken($this->apiVersion($setting), $token);
            $result = $inspection->valid ? self::VALID : self::INVALID;
        } catch (MetaGraphException $exception) {
            $result = self::classifyError($exception->httpStatus, $exception->metaCode);
        }

        return ['result' => $result, 'applied' => $this->write($setting->getKey(), $environment, $cipher, $result, $started, $result === self::VALID ? $inspection : null)];
    }

    /**
     * Meta refused a message sent with a saved token (REQ-WA-22, decision 4): an OAuth
     * error 190 marks that token invalid at once, without any other request. Nothing
     * for the .env settings, and nothing when the token was replaced meanwhile.
     */
    public function recordRejection(WhatsAppConfig $config, Response $response, CarbonImmutable $started): void
    {
        if ($config->source !== WhatsAppConfig::SOURCE_DATABASE || $config->environment === null) {
            return;
        }

        if (self::classifyError($response->status(), $response->json('error.code')) !== self::INVALID) {
            return;
        }

        $setting = WhatsAppSetting::query()->first();
        $column = $config->environment->column('access_token');
        $cipher = $setting?->getRawOriginal($column);
        $sent = (string) $config->accessToken();

        // The token stored now must be the one the message was sent with.
        if (! is_string($cipher) || $sent === '' || ! hash_equals((string) $setting->getAttribute($column), $sent)) {
            return;
        }

        $this->write($setting->getKey(), $config->environment, $cipher, self::INVALID, $started);
    }

    /**
     * A token just saved after debug_token accepted it (REQ-WA-20). A new token starts
     * a new history; the same token saved again only overrides older results.
     */
    public function recordSaved(WhatsAppSetting $setting, WhatsAppEnvironment $environment, TokenInspection $inspection, CarbonImmutable $started, bool $replaced): void
    {
        // From the database: the model keeps a fresh ciphertext of a token saved again
        // with the same value, which is not dirty and so never written.
        $cipher = DB::table('whatsapp_settings')->where('id', $setting->getKey())->value($environment->column('access_token'));

        if (is_string($cipher) && $cipher !== '') {
            $this->write($setting->getKey(), $environment, $cipher, self::VALID, $started, $inspection, ordered: ! $replaced);
        }
    }

    /**
     * The alerts of every environment with a saved token, worked out from the stored
     * state when the panel is shown (decision 1). Never a value of the token.
     *
     * @return array<string, list<array{level: string, message: string}>>
     */
    public function alerts(?WhatsAppSetting $setting): array
    {
        if ($setting === null) {
            return [];
        }

        $alerts = [];

        foreach (WhatsAppEnvironment::cases() as $environment) {
            if (! $setting->secretInfo($environment->column('access_token'))['saved']) {
                continue;
            }

            $list = [];

            if ($setting->field($environment, 'token_status') === self::INVALID) {
                $list[] = ['level' => 'invalid', 'message' => 'أفادت Meta بأن Access Token غير صالح (منتهٍ أو مُبطَل). لن تُرسل الرسائل بهذا الرمز حتى استبداله.'];
            }

            $expiresAt = $setting->field($environment, 'token_expires_at');

            if ($expiresAt !== null && ! $setting->field($environment, 'token_never_expires')) {
                $expiresAt = CarbonImmutable::instance($expiresAt);

                if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                    $list[] = ['level' => 'expired', 'message' => 'انتهت صلاحية Access Token حسب تاريخه في '.$expiresAt->toDateTimeString().' UTC. استبدله.'];
                } else {
                    foreach (array_reverse(self::ALERT_DAYS) as $days) {
                        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now()->addDays($days))) {
                            $list[] = ['level' => (string) $days, 'message' => 'تنتهي صلاحية Access Token خلال '.$days.' '.($days === 1 ? 'يوم' : 'أيام').' أو أقل، في '.$expiresAt->toDateTimeString().' UTC. استبدله قبل ذلك.'];

                            break;
                        }
                    }
                }
            }

            $failedAt = $this->time($setting->getRawOriginal($environment->column('token_check_failed_at')));

            if ($failedAt !== null) {
                $checkedAt = $setting->field($environment, 'token_checked_at');
                $list[] = ['level' => 'check_failed', 'message' => 'تعذّر فحص الرمز لدى Meta آخر مرة في '.$failedAt->format('Y-m-d H:i:s').' UTC. هذا ليس دليلًا على إبطاله. آخر فحص مؤكد: '.($checkedAt ? $checkedAt->toDateTimeString().' UTC' : 'لا يوجد').'.'];
            }

            $alerts[$environment->value] = $list;
        }

        return $alerts;
    }

    /**
     * Apply one result in a single conditional UPDATE.
     *
     * @param  bool  $ordered  false only for a token just replaced, whose history starts again.
     */
    private function write(int $id, WhatsAppEnvironment $environment, #[SensitiveParameter] string $cipher, string $result, CarbonImmutable $started, ?TokenInspection $inspection = null, bool $ordered = true): bool
    {
        $statusAt = $environment->column('token_status_started_at');
        $failedAt = $environment->column('token_check_failed_at');
        $at = $started->format(self::TIME_FORMAT);

        $query = DB::table('whatsapp_settings')
            ->where('id', $id)
            ->where($environment->column('access_token'), $cipher);

        if ($ordered) {
            // Strictly older only: a result of an attempt that started at the same time loses.
            $query->where(fn ($where) => $where->whereNull($statusAt)->orWhere($statusAt, '<', $at));
        }

        if ($result === self::CHECK_FAILED) {
            return $query
                ->where(fn ($where) => $where->whereNull($failedAt)->orWhere($failedAt, '<', $at))
                ->update([$failedAt => $at, 'updated_at' => now()]) === 1;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $quoted = DB::connection()->getPdo()->quote($at);

        $values = [
            $environment->column('token_status') => $result,
            $statusAt => $at,
            $environment->column('token_checked_at') => $started->format('Y-m-d H:i:s'),
            // A failed attempt that started at the same time or later stays: it is the newer news.
            $failedAt => $ordered ? DB::raw('CASE WHEN '.$grammar->wrap($failedAt).' >= '.$quoted.' THEN '.$grammar->wrap($failedAt).' ELSE NULL END') : null,
            'updated_at' => now(),
        ];

        if ($inspection !== null) {
            $values += [
                $environment->column('token_expires_at') => $inspection->expiresAt?->format('Y-m-d H:i:s'),
                $environment->column('token_never_expires') => $inspection->neverExpires,
                $environment->column('token_expiry_source') => $inspection->expirySource,
            ];
        }

        return $query->update($values) === 1;
    }

    private function apiVersion(WhatsAppSetting $setting): string
    {
        $version = $setting->api_version;

        return is_string($version) && trim($version) !== '' ? $version : WhatsAppSettings::DEFAULT_API_VERSION;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
