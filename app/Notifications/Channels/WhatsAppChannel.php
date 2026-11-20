<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\WhatsApp\MessageLogger;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppUnavailableException;
use App\WhatsAppSendStatus;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends authentication codes through the WhatsApp Business (Meta Cloud) API.
 *
 * The "log" driver writes the message to the application log instead, so the
 * login screens work locally before the number is connected in Meta.
 *
 * Which settings apply is decided by WhatsAppSettings (database or .env).
 * Every attempt that reaches Meta, or is blocked, is recorded by MessageLogger;
 * the "log" driver sends nothing and records nothing.
 */
class WhatsAppChannel
{
    public function __construct(private WhatsAppSettings $settings, private MessageLogger $logger) {}

    /**
     * @throws RequestException when Meta rejects the message.
     * @throws WhatsAppUnavailableException when sending is disabled or the settings are incomplete.
     */
    public function send(object $notifiable, LoginCodeNotification $notification): void
    {
        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (! $to) {
            return;
        }

        $config = $this->settings->current();

        $log = fn (WhatsAppSendStatus $status, ?Response $response = null, ?Throwable $exception = null, ?int $durationMs = null) => $this->logger->record(
            config: $config,
            purpose: $notification->purpose,
            recipient: (string) $to,
            status: $status,
            userId: $notifiable instanceof User ? $notifiable->getKey() : null,
            oneTimeCodeId: $notification->oneTimeCodeId,
            response: $response,
            exception: $exception,
            durationMs: $durationMs,
        );

        if ($config->isBlocked()) {
            $log(WhatsAppSendStatus::Blocked);

            throw WhatsAppUnavailableException::for($config);
        }

        if ($config->writesToLog()) {
            // The plain code is only written to the log locally and in tests (REQ-WA-34).
            Log::info('WhatsApp login code (log driver, not sent)', [
                'to' => $to,
                'code' => app()->environment('local', 'testing') ? $notification->code : '****',
            ]);

            return;
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->post($config, (string) $to, $notification->code);
        } catch (Throwable $exception) {
            // Timeout, lost connection or any unexpected error: the outcome is uncertain.
            $log(WhatsAppSendStatus::fromException($exception), exception: $exception, durationMs: $this->elapsedMs($startedAt));

            throw $exception;
        }

        // A 2xx answer only means Meta accepted the request, not that the message was delivered.
        $log(
            $response->failed() ? WhatsAppSendStatus::fromFailedResponse($response) : WhatsAppSendStatus::Accepted,
            response: $response,
            durationMs: $this->elapsedMs($startedAt),
        );

        $response->throw();
    }

    /**
     * Authentication template with a "copy code" button: Meta requires the
     * code both in the body and as the button parameter.
     */
    private function post(WhatsAppConfig $config, string $to, string $code): Response
    {
        return Http::baseUrl("https://graph.facebook.com/{$config->apiVersion}")
            ->withToken((string) $config->accessToken())
            ->connectTimeout(3)
            ->timeout(10)
            ->post("{$config->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $config->templateName,
                    'language' => ['code' => $config->templateLanguage],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ],
                ],
            ]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
