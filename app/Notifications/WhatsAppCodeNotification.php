<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Services\WhatsApp\WhatsAppConfig;
use App\WhatsAppMessagePurpose;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * A code delivered with the WhatsApp authentication template.
 *
 * Sent synchronously: someone is waiting for it.
 */
abstract class WhatsAppCodeNotification extends Notification
{
    /**
     * @param  ?int  $oneTimeCodeId  The stored login code, for the message log (never the code itself).
     */
    public function __construct(
        #[SensitiveParameter] public string $code,
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

    /**
     * The settings to send with. Null means the settings in effect for OTP codes.
     */
    public function config(): ?WhatsAppConfig
    {
        return null;
    }
}
