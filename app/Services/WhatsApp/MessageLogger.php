<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppMessageLog;
use App\WhatsAppMessagePurpose;
use App\WhatsAppSendStatus;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one row per send attempt to whatsapp_message_logs (REQ-WA-48 to REQ-WA-51).
 *
 * Only metadata is kept: never the code, the token, the request or response body,
 * or the full recipient number. A failure to write the log is reported and
 * swallowed, so it never blocks or changes the send itself (REQ-WA-51).
 */
class MessageLogger
{
    public function record(
        WhatsAppConfig $config,
        WhatsAppMessagePurpose $purpose,
        string $recipient,
        WhatsAppSendStatus $status,
        ?int $userId = null,
        ?int $oneTimeCodeId = null,
        ?Response $response = null,
        ?Throwable $exception = null,
        ?int $durationMs = null,
    ): void {
        try {
            WhatsAppMessageLog::create([
                'environment' => $config->environment,
                'purpose' => $purpose,
                'one_time_code_id' => $oneTimeCodeId,
                'user_id' => $userId,
                'recipient_hash' => WhatsAppMessageLog::recipientHash($recipient),
                'recipient_last4' => substr($recipient, -4),
                'template_name' => $config->templateName !== '' ? $config->templateName : null,
                'template_language' => $config->templateLanguage,
                'send_status' => $status,
                'wamid' => $status === WhatsAppSendStatus::Accepted ? $this->wamid($response) : null,
                'error_code' => $this->errorCode($status, $response, $exception, $config),
                'error_title' => $this->errorTitle($status, $response, $exception, $recipient),
                'http_status' => $response?->status(),
                'duration_ms' => $durationMs,
                'attempted_at' => now(),
            ]);
        } catch (Throwable $logFailure) {
            report($logFailure);
        }
    }

    private function wamid(?Response $response): ?string
    {
        $wamid = $response?->json('messages.0.id');

        return is_string($wamid) && $wamid !== '' ? Str::limit($wamid, 128, '') : null;
    }

    private function errorCode(WhatsAppSendStatus $status, ?Response $response, ?Throwable $exception, WhatsAppConfig $config): ?string
    {
        if ($status === WhatsAppSendStatus::Accepted) {
            return null;
        }

        if ($status === WhatsAppSendStatus::Blocked) {
            return $config->blockedReason ? Str::limit($config->blockedReason, 32, '') : 'blocked';
        }

        $code = $response?->json('error.code');

        if (is_scalar($code) && (string) $code !== '') {
            return Str::limit((string) $code, 32, '');
        }

        return $response ? 'http_'.$response->status() : ($exception ? 'connection_error' : null);
    }

    /**
     * Meta's short error message (or the connection error), cut to 190 characters,
     * with the recipient number masked in case it is echoed back.
     */
    private function errorTitle(WhatsAppSendStatus $status, ?Response $response, ?Throwable $exception, string $recipient): ?string
    {
        if ($status === WhatsAppSendStatus::Accepted || $status === WhatsAppSendStatus::Blocked) {
            return null;
        }

        $title = $response?->json('error.error_user_title')
            ?? $response?->json('error.message')
            ?? ($response ? null : $exception?->getMessage());

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return Str::limit(str_replace($recipient, '[recipient]', $title), 190, '');
    }
}
