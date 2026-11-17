<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Notifications\Notification;

/**
 * A one-time login (or two-factor) code delivered on WhatsApp.
 *
 * Sent synchronously: the user is waiting on the next screen for it.
 */
class LoginCodeNotification extends Notification
{
    public function __construct(public string $code) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }
}
