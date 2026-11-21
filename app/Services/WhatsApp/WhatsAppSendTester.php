<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSettingsAudit;
use App\Notifications\AdminTestCodeNotification;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppSendStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Sends an administrator's test code and checks the code the recipient typed
 * (REQ-WA-36 to REQ-WA-39), for the panel and for whatsapp:test alike.
 *
 * - The limit is 5 sends an hour per key: an administrator in the panel, or
 *   one quota of the command (test, production, legacy-env). Every send that
 *   starts counts, whatever Meta answers; a refused request never reaches here.
 * - Meta accepting the request is not delivery: only the right code typed
 *   within 5 minutes confirms the test (AC-WA-23).
 * - Unless Meta accepted the request the code is discarded, as login codes are
 *   (DEC-WA-003).
 * - The audit trail and the message log keep the last 4 digits only.
 */
class WhatsAppSendTester
{
    public const MAX_SENDS_PER_HOUR = 5;

    public const PANEL = 'panel';

    public const CONSOLE = 'console';

    public function __construct(private AdminTestCodes $codes) {}

    public static function panelKey(User $user): string
    {
        return 'admin:'.$user->getKey();
    }

    public static function consoleKey(string $quota): string
    {
        return 'console:'.$quota;
    }

    /**
     * Seconds until another send is allowed for this key, or 0 when it is allowed now.
     */
    public function secondsUntilAllowed(string $key): int
    {
        $limiter = $this->limiterKey($key);

        return RateLimiter::tooManyAttempts($limiter, self::MAX_SENDS_PER_HOUR) ? max(1, RateLimiter::availableIn($limiter)) : 0;
    }

    /**
     * Sends still allowed this hour for this key.
     */
    public function remaining(string $key): int
    {
        return RateLimiter::remaining($this->limiterKey($key), self::MAX_SENDS_PER_HOUR);
    }

    /**
     * Send a test code. The caller has already checked the limit and that the
     * settings can send to Meta; both are checked again here.
     *
     * $fingerprint is the fingerprint of the production settings used for this send
     * (REQ-WA-68). It is kept with the code and in both audit entries, so the
     * confirmation stays tied to the settings that actually sent the code.
     *
     * @return array{status: WhatsAppSendStatus, error_code: ?string}
     */
    public function send(WhatsAppConfig $config, #[SensitiveParameter] string $phone, string $key, string $origin, ?string $quota, ?User $by, ?Request $request, ?string $fingerprint = null): array
    {
        if (! $config->sendsToMeta()) {
            throw new LogicException('A test code is only sent through Meta.');
        }

        if ($this->secondsUntilAllowed($key) > 0) {
            throw new LogicException('The send limit was reached; the caller must check it first.');
        }

        // The send starts now: it counts whatever Meta answers (REQ-WA-38, REQ-WA-39).
        RateLimiter::hit($this->limiterKey($key), 3600);

        $last4 = substr($phone, -4);
        $code = $this->codes->issue($key, [
            'environment' => $config->environment?->value,
            'last4' => $last4,
            'origin' => $origin,
            'quota' => $quota,
            'fingerprint' => $fingerprint,
        ]);

        $errorCode = null;

        try {
            Notification::route('whatsapp', $phone)->notify(new AdminTestCodeNotification($code, $config));
            $status = WhatsAppSendStatus::Accepted;
        } catch (RequestException $exception) {
            $status = WhatsAppSendStatus::fromFailedResponse($exception->response);
            $errorCode = $this->metaErrorCode($exception);
        } catch (ConnectionException $exception) {
            // Its message carries the request URL, with the Phone Number ID: never logged.
            Log::warning('WhatsApp: the test code could not be sent.', ['error' => 'connection_error']);
            $status = WhatsAppSendStatus::fromException($exception);
        } catch (Throwable $exception) {
            // Unexpected: still reported. The phone number and the codes are sensitive
            // parameters, so no stack trace shows them.
            report($exception);
            $status = WhatsAppSendStatus::fromException($exception);
        }

        unset($code);

        if ($status !== WhatsAppSendStatus::Accepted) {
            $this->codes->discard($key);
        }

        WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestAttempted, $by, $config->environment, changes: array_filter([
            'origin' => $origin,
            'quota' => $quota,
            'source' => $config->source,
            'recipient_last4' => $last4,
            'send_status' => $status->value,
            'error_code' => $errorCode,
            'fingerprint' => $fingerprint,
        ], fn ($value) => $value !== null), request: $request);

        return ['status' => $status, 'error_code' => $errorCode];
    }

    /**
     * Check the code the recipient typed. Every check of a waiting code is audited.
     *
     * @return array{outcome: string, attempts_left: int, context: array<string, string|null>|null}
     */
    public function verify(string $key, string $input, ?User $by, ?Request $request): array
    {
        $result = $this->codes->verify($key, $input);

        if ($result['outcome'] !== AdminTestCodes::MISSING) {
            $environment = $result['context']['environment'] ?? null;

            WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestVerification, $by,
                $environment ? WhatsAppEnvironment::from($environment) : null,
                changes: array_filter([
                    'origin' => $result['context']['origin'] ?? null,
                    'quota' => $result['context']['quota'] ?? null,
                    'recipient_last4' => $result['context']['last4'] ?? null,
                    'outcome' => $result['outcome'],
                    'fingerprint' => $result['context']['fingerprint'] ?? null,
                ], fn ($value) => $value !== null),
                request: $request,
            );
        }

        return $result;
    }

    /**
     * @return array{expires_at: int, attempts_left: int, context: array<string, string|null>}|null
     */
    public function pending(string $key): ?array
    {
        return $this->codes->pending($key);
    }

    private function limiterKey(string $key): string
    {
        return 'whatsapp-send-test:'.$key;
    }

    private function metaErrorCode(RequestException $exception): ?string
    {
        $code = $exception->response->json('error.code');

        return is_scalar($code) && (string) $code !== '' ? substr((string) $code, 0, 32) : 'http_'.$exception->response->status();
    }
}
