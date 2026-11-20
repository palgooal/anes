<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\EnvRestoreRefusedException;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Restore .env as the OTP source from the server (REQ-WA-70, REQ-WA-71, AC-WA-52, AC-WA-53).
 *
 * - Only once the database is the OTP source, and only after a preliminary check of
 *   the .env settings in force, read locally without any request to Meta: driver
 *   "meta" with a Phone Number ID and an access token, or "log" in local and
 *   testing only. Any other driver is refused.
 * - --confirm is required. --env is refused before anything else.
 * - The warnings come before the options are checked: the check is preliminary and
 *   never proves that a code will arrive, a failing .env stops every sign-in code,
 *   and going back is done by activation from the panel only.
 * - Only the source state changes, in a short transaction with the row locked: the
 *   row, its secrets and its mode stay as they are. Audited without a user. A
 *   refusal writes nothing, not even an audit entry. No secret is printed.
 * - There is no activation from the server and no way around two-factor sign-in.
 */
#[Signature('whatsapp:restore-env
    {--confirm : Required: make .env the OTP source again}')]
#[Description('Make the .env settings the WhatsApp OTP source again, from the server')]
class WhatsAppRestoreEnvCommand extends Command
{
    public function handle(WhatsAppSettingsManager $manager, WhatsAppSettings $settings): int
    {
        if ($this->input->hasParameterOption('--env')) {
            return $this->refuse('لا يُقبل الخيار --env مع هذا الأمر: شغّله ببيئة الخادم الفعلية.');
        }

        $setting = $settings->storedSetting();

        if ($problem = $manager->restoreProblem($setting)) {
            return $this->refuse($this->problemMessage($problem, $manager->missingEnvSettings()));
        }

        $this->showImpact($setting->mode->value, $manager->restoreWarnings());

        if (! $this->option('confirm')) {
            return $this->refuse('أضف --confirm لتنفيذ الاستعادة.');
        }

        try {
            $manager->restoreEnv();
        } catch (EnvRestoreRefusedException $exception) {
            return $this->refuse($this->problemMessage($exception->reason, $exception->missing));
        } catch (Throwable $exception) {
            $this->logDatabaseError($exception);

            // A failed commit leaves the outcome unknown: never claim that nothing changed.
            $this->error('تعذّرت الاستعادة بسبب خطأ في قاعدة البيانات، ولم يتأكد تنفيذها. تحقّق من مصدر الرموز في اللوحة قبل إعادة المحاولة.');

            return self::FAILURE;
        }

        $this->info('أصبح .env مصدر رموز الدخول من الرمز التالي، وعاد سجل الإعدادات «قيد التحضير» بأسراره ووضعه، وسُجّلت الاستعادة في سجل التدقيق.');
        $this->warn('اختبر .env الآن بأمر whatsapp:test --legacy-env --confirm-production.');

        return self::SUCCESS;
    }

    /**
     * The impact on sign-in and the limits of the check (REQ-WA-70, REQ-WA-71, AC-WA-52, AC-WA-53).
     *
     * @param  list<string>  $warnings
     */
    private function showImpact(string $mode, array $warnings): void
    {
        $this->warn('بيئة التطبيق: '.$this->laravel->environment());
        $this->line('مصدر رموز الدخول الحالي: إعدادات اللوحة (الوضع '.$mode.'). بعد الاستعادة يصبح .env مصدر الرمز التالي فورًا، ويبقى سجل الإعدادات وأسراره ووضعه دون تغيير.');
        $this->warn('التحقق من .env مبدئي: لم يُرسل أي طلب إلى Meta، وهو لا يضمن نجاح الإرسال الفعلي.');
        $this->warn('إذا فشل الإرسال عبر .env بعد الاستعادة تتوقف رموز الدخول للمستفيدين والفريق والمدير نفسه.');
        $this->warn('اختبر .env بأمر whatsapp:test --legacy-env --confirm-production قبل الاستعادة وبعدها مباشرة، وأبقِ جلسة مدير مفتوحة أثناءها.');
        $this->warn('التراجع يتم بالتفعيل من اللوحة وحده وبشروطه كاملة، ويحتاج جلسة مدير قائمة، فليس مسار تعافٍ مضمونًا.');
        $this->line('بعد الاستعادة لا تبقى معرّفات بيئة الإنتاج مجمّدة في اللوحة.');

        if ($this->laravel->configurationIsCached()) {
            $this->warn('الإعدادات مخزّنة مؤقتًا: الفحص جرى على القيم المخزّنة، وتعديل .env لا يسري حتى يُعاد بناؤها.');
        }

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
    }

    /**
     * @param  list<string>  $missing  Variable names only.
     */
    private function problemMessage(string $reason, array $missing): string
    {
        return match ($reason) {
            EnvRestoreRefusedException::NOT_MANAGED => 'لا توجد إعدادات واتساب في قاعدة البيانات: مصدر الرموز هو .env أصلًا.',
            EnvRestoreRefusedException::PREPARING => 'السجل قيد التحضير: مصدر الرموز هو .env أصلًا.',
            EnvRestoreRefusedException::DRIVER_NOT_ALLOWED => 'مشغّل واتساب في .env (WHATSAPP_DRIVER) ليس meta ولا log.',
            EnvRestoreRefusedException::LOG_NOT_ALLOWED => 'المشغّل log في .env لا يُقبل إلا في بيئة local أو testing.',
            EnvRestoreRefusedException::ENV_INCOMPLETE => 'إعدادات .env ناقصة: '.implode('، ', $missing).'.',
            default => 'رُفضت الاستعادة.',
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
            Log::warning('whatsapp:restore-env: database error, the OTP source was not restored.', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        } catch (Throwable) {
            //
        }
    }
}
