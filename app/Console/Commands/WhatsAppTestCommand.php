<?php

namespace App\Console\Commands;

use App\PhoneNumber;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSendTester;
use App\Services\WhatsApp\WhatsAppSettings;
use App\WhatsAppEnvironment;
use App\WhatsAppSendStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Send an administrator's test code from the server (REQ-WA-39, AC-WA-45).
 *
 * - With a settings row the operator names the environment; its saved settings
 *   are used whatever the mode (REQ-WA-12). Without one, .env is read as before,
 *   and no Meta environment is assumed for it.
 * - --confirm-production is required for production and, as a precaution, for .env.
 * - 5 sends an hour per quota (test, production, legacy-env), separate from the
 *   panel's limit. Refusals before the send do not count and log no message.
 * - The code is never printed or logged. Only the operator typing the received
 *   code confirms delivery; a non-interactive run never does.
 */
#[Signature('whatsapp:test
    {phone : The mobile number, e.g. 0599123456 or +970599123456}
    {--country= : Calling code used for a national number (966, 970 or 972)}
    {--environment= : test or production (required once the settings are managed from the panel)}
    {--confirm-production : Required to send through production, and through .env settings}')]
#[Description('Send a WhatsApp test code (not a login code) to check the Meta connection')]
class WhatsAppTestCommand extends Command
{
    public const LEGACY_QUOTA = 'legacy-env';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppSettings $settings, WhatsAppSendTester $tester): int
    {
        $phone = PhoneNumber::normalize($this->argument('phone'), $this->option('country'));

        if (! $phone) {
            $this->error('رقم غير صحيح، أو دولته غير مسموحة في AUTH_PHONE_COUNTRIES ('.implode(',', PhoneNumber::allowedCountries()).').');

            return self::FAILURE;
        }

        $setting = $settings->storedSetting();

        if ($setting) {
            $environment = WhatsAppEnvironment::tryFrom((string) $this->option('environment'));

            if (! $environment) {
                $this->error('الإعدادات تُدار من اللوحة: حدّد البيئة بـ --environment=test أو --environment=production.');

                return self::FAILURE;
            }

            $config = $settings->forEnvironment($setting, $environment);
            $quota = $environment->value;
        } else {
            $config = $settings->legacy();
            $quota = self::LEGACY_QUOTA;
        }

        $this->line('To: '.PhoneNumber::format($phone).' · '.$config->describe().' · Quota: '.$quota);

        if ($refusal = $this->refusal($config, $quota)) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $key = WhatsAppSendTester::consoleKey($quota);
        $wait = $tester->secondsUntilAllowed($key);

        if ($wait > 0) {
            $this->error('بلغت حصة '.$quota.' حدها: '.WhatsAppSendTester::MAX_SENDS_PER_HOUR.' اختبارات في الساعة. حاول بعد '.(int) ceil($wait / 60).' دقيقة. لم يُرسل شيء.');

            return self::FAILURE;
        }

        $result = $tester->send($config, $phone, $key, WhatsAppSendTester::CONSOLE, $quota, null, null);

        if ($result['status'] !== WhatsAppSendStatus::Accepted) {
            $this->error(match ($result['status']) {
                WhatsAppSendStatus::Rejected => 'رفضت Meta الطلب'.($result['error_code'] ? ' (رمز الخطأ '.$result['error_code'].')' : '').'. لم يُرسل رمز الاختبار.',
                WhatsAppSendStatus::Uncertain => 'لم تتأكد نتيجة الطلب (انتهت المهلة أو انقطع الاتصال أو حدث خطأ لدى Meta). أُلغي رمز الاختبار.',
                default => 'الإرسال غير ممكن بهذه الإعدادات.',
            });

            return self::FAILURE;
        }

        $this->info('قبلت Meta الطلب. هذا لا يعني وصول الرسالة.');

        if (! $this->input->isInteractive()) {
            $this->warn('تشغيل غير تفاعلي: لم يُتحقق من استلام الرمز، ولا تُعد هذه النتيجة تأكيداً للاستلام.');

            return self::SUCCESS;
        }

        return $this->confirmReceipt($tester, $key);
    }

    /**
     * Reasons to stop before anything is sent: nothing is counted or logged.
     */
    private function refusal(WhatsAppConfig $config, string $quota): ?string
    {
        if ($config->writesToLog()) {
            return 'مشغّل الإرسال في .env هو log: لا يُرسل شيء، فلا يمكن اختبار الإرسال. لم يُرسل شيء.';
        }

        if (! $config->sendsToMeta()) {
            return 'إعدادات هذه البيئة ناقصة ('.$config->blockedReason.'). لم يُرسل شيء.';
        }

        if (! $config->phoneNumberId || ! $config->hasAccessToken()) {
            return 'WHATSAPP_PHONE_NUMBER_ID و WHATSAPP_ACCESS_TOKEN مطلوبان عند WHATSAPP_DRIVER=meta. لم يُرسل شيء.';
        }

        if ($quota !== WhatsAppEnvironment::Test->value && ! $this->option('confirm-production')) {
            return $quota === self::LEGACY_QUOTA
                ? 'الإرسال بإعدادات .env يتطلب --confirm-production احترازياً، لأن بيئة الرقم في .env غير معروفة. هذا لا يعني أن الرقم تابع للإنتاج. لم يُرسل شيء.'
                : 'الإرسال عبر بيئة الإنتاج يتطلب --confirm-production. لم يُرسل شيء.';
        }

        return null;
    }

    private function confirmReceipt(WhatsAppSendTester $tester, string $key): int
    {
        while (true) {
            $input = (string) $this->secret('اكتب الرمز الذي وصلك على واتساب (لديك 5 دقائق)');
            $result = $tester->verify($key, $input, null, null);

            switch ($result['outcome']) {
                case AdminTestCodes::VERIFIED:
                    $this->info('تأكد استلام رمز الاختبار: الرسالة وصلت والرمز ظهر صحيحاً.');

                    return self::SUCCESS;
                case AdminTestCodes::WRONG:
                    $this->warn('الرمز غير صحيح. المحاولات المتبقية: '.$result['attempts_left'].'.');
                    break;
                case AdminTestCodes::EXHAUSTED:
                    $this->error('استُنفدت محاولات التحقق وأُلغي رمز الاختبار. لم يتأكد الاستلام.');

                    return self::FAILURE;
                default:
                    $this->error('انتهت مدة رمز الاختبار. لم يتأكد الاستلام.');

                    return self::FAILURE;
            }
        }
    }
}
