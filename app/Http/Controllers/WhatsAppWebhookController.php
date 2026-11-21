<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Meta's delivery webhook (PH-WA-8). A public route, without a session user, excluded
 * from CSRF protection only (REQ-WA-58), and rate limited.
 *
 * Answers (decisions 8.2 and 8.3): 403 for a missing or wrong signature, 413 for a
 * body over 256 KiB, 400 for a signed body that is not JSON, 200 for everything that
 * was applied or ignored, 500 for an internal error, so that Meta sends it again.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * The verification request (REQ-WA-52). PHP turns the dots of hub.mode into
     * underscores in the query.
     */
    public function verify(Request $request, WhatsAppWebhook $webhook): Response
    {
        try {
            if (! $webhook->verifies($request->query('hub_mode'), $request->query('hub_verify_token'))) {
                return $this->empty(403);
            }
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }

        $challenge = $request->query('hub_challenge');

        return response(is_string($challenge) ? $challenge : '', 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The delivery statuses (REQ-WA-53 to REQ-WA-57).
     */
    public function receive(Request $request, WhatsAppWebhook $webhook): Response
    {
        $length = $request->header('Content-Length');

        if (is_string($length) && ctype_digit($length) && (int) $length > WhatsAppWebhook::MAX_BYTES) {
            return $this->empty(413);
        }

        $raw = (string) $request->getContent();

        // The actual size, whatever the header says.
        if (strlen($raw) > WhatsAppWebhook::MAX_BYTES) {
            return $this->empty(413);
        }

        try {
            $signed = $webhook->signedBy($raw, $request->header('X-Hub-Signature-256'));

            if ($signed === []) {
                return $this->empty(403);
            }

            try {
                $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->empty(400);
            }

            $counts = is_array($payload) ? $webhook->process($payload, $signed) : ['applied' => 0, 'ignored' => 1];

            Log::info('WhatsApp webhook: statuses received.', $counts);

            return $this->empty(200);
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }
    }

    private function empty(int $status): Response
    {
        return response('', $status);
    }

    /**
     * The exception class and code only: never a message that could carry values.
     */
    private function failed(Throwable $exception): Response
    {
        try {
            Log::warning('WhatsApp webhook: internal error.', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        } catch (Throwable) {
            //
        }

        return $this->empty(500);
    }
}
