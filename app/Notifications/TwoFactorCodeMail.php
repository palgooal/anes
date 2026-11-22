<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The staff two-factor code by email (DEC-AUTH-009), used only while WhatsApp cannot
 * really send. Sent at once, never queued, so a failure is known before the code
 * screen opens. The message holds the code and its validity, and no link to act on.
 */
class TwoFactorCodeMail extends Notification
{
    public function __construct(#[SensitiveParameter] public string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.one_time_codes.expires_minutes');

        return (new MailMessage)
            ->subject('رمز التحقق لدخول بوابة فريق العمل')
            ->greeting('السلام عليكم')
            ->line('رمز التحقق لإكمال دخولك إلى بوابة فريق العمل:')
            ->line('**'.$this->code.'**')
            ->line("صلاحية الرمز {$minutes} دقائق، ويُستخدم مرة واحدة. لا تشاركه مع أحد.")
            ->line('إن لم تحاول تسجيل الدخول الآن فتجاهل هذه الرسالة، وغيّر كلمة مرورك.');
    }
}
