<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\ModeChangeRefusedException;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Switch the WhatsApp mode from the server (REQ-WA-69, REQ-WA-71, AC-WA-51, AC-WA-53).
 *
 * - Only "disabled" or "test", and only once the database is the OTP source. Never
 *   "production" (activation from the panel only) and never "log".
 * - --confirm is required. In the production application environment, "test" also
 *   requires --acknowledge-login-impact.
 * - --env is refused before anything else: the command runs in the server's real
 *   application environment, which is shown with the impact before any check of
 *   the options.
 * - Only the mode changes, in a short transaction with the row locked, audited
 *   without a user. A refusal writes nothing, not even an audit entry. No secret
 *   or phone number is printed.
 */
#[Signature('whatsapp:mode
    {mode : disabled or test}
    {--confirm : Required: switch the mode}
    {--acknowledge-login-impact : Required for test in the production application environment}')]
#[Description('Switch the WhatsApp mode to disabled or test from the server')]
class WhatsAppModeCommand extends Command
{
    public function handle(WhatsAppSettingsManager $manager, WhatsAppSettings $settings): int
    {
        if ($this->input->hasParameterOption('--env')) {
            return $this->refuse('لا يُقبل الخيار --env مع هذا الأمر: شغّله ببيئة الخادم الفعلية.');
        }

        $to = WhatsAppMode::tryFrom((string) $this->argument('mode'));

        if (! in_array($to, WhatsAppSettingsManager::SERVER_MODES, true)) {
            return $this->refuse(match ($to) {
                WhatsAppMode::Production => 'لا يفعّل هذا الأمر وضع الإنتاج: التفعيل إلى production من اللوحة وحدها بعد فحوص الجاهزية.',
                WhatsAppMode::Log => 'وضع log غير متاح من الخادم. القيم المقبولة: disabled أو test.',
                default => 'وضع غير معروف. القيم المقبولة: disabled أو test.',
            });
        }

        $setting = $settings->storedSetting();
        $expected = $setting?->mode;

        // Everything but the operator's options, so the impact is shown before they are checked.
        if ($problem = $manager->serverModeProblem($setting, $to, $expected, acknowledged: true)) {
            return $this->refuse($this->problemMessage($problem));
        }

        $this->showImpact($expected, $to);

        if (! $this->option('confirm')) {
            return $this->refuse('أضف --confirm لتنفيذ التبديل.');
        }

        $acknowledged = (bool) $this->option('acknowledge-login-impact');

        if ($to === WhatsAppMode::Test && $this->laravel->isProduction() && ! $acknowledged) {
            return $this->refuse('في بيئة التطبيق production يتطلب التبديل إلى test الخيار --acknowledge-login-impact إقرارًا بأثره على الدخول.');
        }

        try {
            $manager->switchModeFromServer($to, $expected, $acknowledged);
        } catch (ModeChangeRefusedException $exception) {
            return $this->refuse($this->problemMessage($exception->reason));
        } catch (Throwable $exception) {
            $this->logDatabaseError($exception);

            // A failed commit leaves the outcome unknown: never claim that nothing changed.
            $this->error('تعذّر التبديل بسبب خطأ في قاعدة البيانات، ولم يتأكد تنفيذه. تحقّق من الوضع الحالي قبل إعادة المحاولة.');

            return self::FAILURE;
        }

        $this->info('بُدّل الوضع من '.$expected->value.' إلى '.$to->value.'، وسُجّل في سجل التدقيق.');

        return self::SUCCESS;
    }

    /**
     * The impact on sign-in (REQ-WA-71, AC-WA-53), with the application environment detected.
     */
    private function showImpact(WhatsAppMode $from, WhatsAppMode $to): void
    {
        $this->warn('بيئة التطبيق: '.$this->laravel->environment());
        $this->line('مصدر رموز الدخول: إعدادات اللوحة · الوضع الحالي: '.$from->value.' · الوضع المطلوب: '.$to->value);

        if ($to === WhatsAppMode::Disabled) {
            $this->warn('سيتوقف إرسال كل رموز الدخول والتحقق الثنائي فورًا: المستفيدون والفريق والمدير نفسه، ولن يُرسل أي طلب إلى Meta.');
        } else {
            $this->warn('سترسل رموز الدخول عبر بيئة الاختبار، ولا تصل إلا إلى المستلمين المسموحين فيها. من ليس رقمه منهم، من المستفيدين والفريق والمدير نفسه، لن يستطيع الدخول.');
        }

        $this->warn('لا يوجد أي تجاوز للتحقق الثنائي. إذا انتهت جلسات المديرين ولم تصلهم الرموز تعذّر الدخول إلى اللوحة.');
    }

    private function problemMessage(string $reason): string
    {
        return match ($reason) {
            ModeChangeRefusedException::NOT_MANAGED => 'لا توجد إعدادات واتساب في قاعدة البيانات: مصدر الرموز هو .env.',
            ModeChangeRefusedException::PREPARING => 'السجل قيد التحضير: مصدر الرموز هو .env، فلا أثر للوضع قبل التفعيل.',
            ModeChangeRefusedException::STALE => 'تغيّر الوضع منذ عرضه. أعد تشغيل الأمر.',
            ModeChangeRefusedException::PRODUCTION => 'لا يفعّل هذا الأمر وضع الإنتاج.',
            ModeChangeRefusedException::LOG_NOT_ALLOWED => 'وضع log غير متاح من الخادم.',
            ModeChangeRefusedException::UNCHANGED => 'الوضع المطلوب هو الوضع الحالي.',
            ModeChangeRefusedException::TEST_INCOMPLETE => 'إعدادات بيئة الاختبار ناقصة، فلا يمكن التبديل إلى test.',
            ModeChangeRefusedException::ACKNOWLEDGEMENT_REQUIRED => 'التبديل إلى test في بيئة production يتطلب --acknowledge-login-impact.',
            default => 'رُفض التبديل.',
        };
    }

    private function refuse(string $message): int
    {
        $this->error($message.' لم يُكتب شيء.');

        return self::FAILURE;
    }

    /**
     * The exception class and code only: never a message that could carry values.
     */
    private function logDatabaseError(Throwable $exception): void
    {
        try {
            Log::warning('whatsapp:mode: database error, the mode was not changed.', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        } catch (Throwable) {
            //
        }
    }
}
