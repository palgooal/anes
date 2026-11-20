<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\WhatsAppMessagePurpose;
use Illuminate\Notifications\Notification;

/**
 * A one-time login (or two-factor) code delivered on WhatsApp.
 *
 * Sent synchronously: the user is waiting on the next screen for it.
 */
class LoginCodeNotification extends Notification
{
    /**
     * @param  ?int  $oneTimeCodeId  The stored code, for the message log (never the code itself).
     */
    public function __construct(
        public string $code,
        public ?int $oneTimeCodeId = null,
        public WhatsAppMessagePurpose $purpose = WhatsAppMessagePurpose::Login,
    ) {}

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
