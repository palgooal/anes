<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the administration approves a join request or adds a member:
 * a link to choose a password (a standard password-reset token).
 */
class TeamInvitationNotification extends Notification
{
    public function __construct(public string $token) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('تم تفعيل حسابك في بوابة فريق العمل')
            ->greeting('السلام عليكم '.$notifiable->name)
            ->line('أهلاً بك في فريق خدمة الدعم والمساندة لمرضى الرعاية التلطيفية. فُعّل حسابك بدور: '.$notifiable->role->label().'.')
            ->line('اختر كلمة المرور لتدخل إلى لوحتك:')
            ->action('تعيين كلمة المرور', $url)
            ->line('صلاحية الرابط ساعة واحدة. إن انتهت، استخدم «نسيت كلمة المرور؟» في صفحة الدخول.');
    }
}
