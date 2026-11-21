<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppTokenMonitor;
use App\WhatsAppEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily check of the saved access tokens (REQ-WA-22, AC-WA-40, PH-WA-7),
 * scheduled in routes/console.php.
 *
 * - The test and production tokens saved in the settings row, whether the row is
 *   being prepared or active. The .env token is never checked (decision 3), and no
 *   settings row is ever created.
 * - Only debug_token: nothing is sent, and only the token state is written.
 * - Every environment is checked, whatever happened to the other one. The command
 *   fails when a token is invalid, a check could not be done or an error occurred,
 *   so that the scheduler log shows it.
 * - Nothing of a token, and nothing Meta answered, is printed or logged.
 */
#[Signature('whatsapp:check-tokens')]
#[Description('Check the WhatsApp access tokens saved in the panel with Meta (daily)')]
class WhatsAppCheckTokensCommand extends Command
{
    public function handle(WhatsAppTokenMonitor $monitor, WhatsAppSettings $settings): int
    {
        if ($this->input->hasParameterOption('--env')) {
            $this->error('لا يُقبل الخيار --env مع هذا الأمر: شغّله ببيئة الخادم الفعلية. لم يُفحص شيء.');

            return self::FAILURE;
        }

        if ($settings->storedSetting() === null) {
            $this->info('لا توجد رموز محفوظة في قاعدة البيانات للفحص. رمز .env لا يُفحص دوريًا: اختبره بأمر whatsapp:test --legacy-env.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach (WhatsAppEnvironment::cases() as $environment) {
            try {
                $outcome = $monitor->check($environment);
            } catch (Throwable $exception) {
                $failed = true;
                $this->logError($environment, $exception);
                $this->error($environment->value.': تعذّر الفحص بسبب خطأ غير متوقع.');

                continue;
            }

            if ($outcome['result'] === null) {
                $this->line($environment->value.': لا يوجد رمز محفوظ.');

                continue;
            }

            if (! $outcome['applied']) {
                $this->line($environment->value.': لم تُحفظ النتيجة، لأن الرمز استُبدل أثناء الفحص أو سُجّلت نتيجة أحدث.');

                continue;
            }

            match ($outcome['result']) {
                WhatsAppTokenMonitor::VALID => $this->info($environment->value.': الرمز صالح.'),
                WhatsAppTokenMonitor::INVALID => $this->error($environment->value.': أفادت Meta بأن الرمز غير صالح.'),
                default => $this->warn($environment->value.': تعذّر الفحص لدى Meta، وبقيت آخر حالة مؤكدة كما هي.'),
            };

            $failed = $failed || $outcome['result'] !== WhatsAppTokenMonitor::VALID;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The exception class and code only: never a message that could carry values.
     */
    private function logError(WhatsAppEnvironment $environment, Throwable $exception): void
    {
        try {
            Log::warning('whatsapp:check-tokens: the check failed.', [
                'environment' => $environment->value,
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        } catch (Throwable) {
            //
        }
    }
}
