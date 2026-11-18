<?php

namespace App\Notifications\Channels;

use App\Notifications\LoginCodeNotification;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends authentication codes through the WhatsApp Business (Meta Cloud) API.
 *
 * The "log" driver writes the message to the application log instead, so the
 * login screens work locally before the number is connected in Meta.
 */
class WhatsAppChannel
{
    /**
     * @throws RequestException when Meta rejects the message.
     */
    public function send(object $notifiable, LoginCodeNotification $notification): void
    {
        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (! $to) {
            return;
        }

        $config = config('services.whatsapp');

        if ($config['driver'] !== 'meta') {
            // The plain code is only written to the log outside production.
            Log::info('WhatsApp login code (log driver, not sent)', [
                'to' => $to,
                'code' => app()->isProduction() ? '****' : $notification->code,
            ]);

            return;
        }

        /*
         * Authentication template with a "copy code" button: Meta requires the
         * code both in the body and as the button parameter.
         */
        Http::baseUrl("https://graph.facebook.com/{$config['api_version']}")
            ->withToken($config['access_token'])
            ->connectTimeout(3)
            ->timeout(10)
            ->post("{$config['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $config['login_template'],
                    'language' => ['code' => $config['template_language']],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $notification->code]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $notification->code]]],
                    ],
                ],
            ])
            ->throw();
    }
}
