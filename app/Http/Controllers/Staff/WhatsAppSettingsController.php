<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\WhatsApp\ActivateWhatsAppRequest;
use App\Http\Requests\Staff\WhatsApp\ReviewActivationRequest;
use App\Http\Requests\Staff\WhatsApp\ReviewWhatsAppModeRequest;
use App\Http\Requests\Staff\WhatsApp\SwitchWhatsAppModeRequest;
use App\Http\Requests\Staff\WhatsApp\TakeOverWhatsAppSettingsRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppEnvironmentRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppGeneralRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppSecretsRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppWebhookTokenRequest;
use App\Http\Requests\Staff\WhatsApp\VerifyWhatsAppSendTestRequest;
use App\Http\Requests\Staff\WhatsApp\WhatsAppSendTestRequest;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\PhoneNumber;
use App\Services\WhatsApp\ActivationOutcomeUnknownException;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\ModeChangeRefusedException;
use App\Services\WhatsApp\ProductionIdentifiersFrozenException;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\TakeOverNotAllowedException;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppConnectionTester;
use App\Services\WhatsApp\WhatsAppReadiness;
use App\Services\WhatsApp\WhatsAppSendTester;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use App\WhatsAppSendStatus;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Administration: WhatsApp Cloud API settings (docs/ANIS-WHATSAPP-SETTINGS-001.md, PH-WA-4).
 *
 * Secrets are write-only: the page shows whether each one is saved, its last 4
 * characters and when and by whom it was set, never its value (REQ-WA-18).
 */
class WhatsAppSettingsController extends Controller
{
    /**
     * Sensitive WhatsApp actions need a password confirmed within the last 15 minutes
     * (REQ-WA-42). Applied to their routes only; auth.password_timeout is unchanged.
     */
    public const PASSWORD_CONFIRMATION_SECONDS = 900;

    /** Shown when management cannot move to the panel in this environment (REQ-WA-64). */
    public const TAKE_OVER_NOT_ALLOWED = 'نقل إدارة واتساب إلى اللوحة غير متاح في هذه البيئة حاليًا، حتى تكتمل في هذه المرحلة وسائل التفعيل والاستعادة. وحتى ذلك الحين يستمر الإرسال من ملف .env.';

    public function __construct(
        private WhatsAppSettings $settings,
        private WhatsAppSettingsManager $manager,
        private WhatsAppConnectionTester $connectionTester,
        private WhatsAppSendTester $sendTester,
        private SettingsFingerprint $fingerprint,
        private WhatsAppReadiness $readiness,
        private WhatsAppActivation $activation,
    ) {}

    public function show(): View
    {
        $setting = WhatsAppSetting::query()->first();
        $config = $this->settings->current();

        // REQ-WA-63, REQ-WA-04: sending is stopped because the active environment is missing
        // required fields. Only once the database is the OTP source: a row being prepared
        // stops nothing, since codes are still sent with .env.
        $incomplete = null;

        if ($setting?->isDatabaseSource() && $config->blockedReason === WhatsAppConfig::BLOCKED_INCOMPLETE && $config->environment) {
            $incomplete = [
                'environment' => $config->environment,
                'fields' => $this->settings->missingRequiredFields($setting, $config->environment),
            ];
        }

        $editorIds = collect($setting?->secret_metadata ?? [])->pluck('updated_by')->filter()->unique();

        return view('staff.whatsapp.index', [
            'setting' => $setting,
            'config' => $config,
            'incomplete' => $incomplete,
            'editors' => User::query()->whereKey($editorIds)->pluck('name', 'id'),
            'envDriver' => config('services.whatsapp.driver') === 'meta' ? 'meta' : 'log',
            'takeOverAllowed' => WhatsAppSettingsManager::takeOverAllowed(),
            'frozenProduction' => $this->manager->frozenFields($setting, WhatsAppEnvironment::Production),
            'activationTargets' => $this->activation->offeredTargets($setting),
            'pendingTest' => $this->sendTester->pending(WhatsAppSendTester::panelKey(auth()->user())),
        ]);
    }

    /**
     * REQ-WA-05: explicit move from .env to the panel.
     */
    public function takeOver(TakeOverWhatsAppSettingsRequest $request): RedirectResponse
    {
        try {
            $this->manager->takeOver($request->user(), $request);
        } catch (TakeOverNotAllowedException) {
            return to_route('staff.whatsapp')->withErrors(['whatsapp' => self::TAKE_OVER_NOT_ALLOWED]);
        } catch (DomainException) {
            return to_route('staff.whatsapp')->withErrors(['whatsapp' => 'إعدادات واتساب تُدار من اللوحة بالفعل.']);
        }

        return to_route('staff.whatsapp')->with('status', 'نُقلت إدارة إعدادات واتساب إلى هذه اللوحة، والسجل الآن قيد التحضير. ما زالت رموز الدخول تُرسل بإعدادات ملف .env حتى تفعيل صريح.');
    }

    public function updateGeneral(UpdateWhatsAppGeneralRequest $request): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        $changed = $this->manager->updateGeneral($setting, $request->validated(), $request->user(), $request);

        return to_route('staff.whatsapp')->with('status', $changed ? 'حُفظت الإعدادات العامة.' : 'لم يتغير شيء.');
    }

    public function updateEnvironment(UpdateWhatsAppEnvironmentRequest $request, WhatsAppEnvironment $environment): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        try {
            $changed = $this->manager->updateEnvironment($setting, $environment, $request->validated(), $request->user(), $request);
        } catch (ProductionIdentifiersFrozenException $exception) {
            $message = 'لا يتغير هذا الحقل ما دام وضع الإنتاج نافذًا.';

            return to_route('staff.whatsapp')->withInput()->withErrors(
                ['frozen' => 'لم يُحفظ شيء: معرّفات الإنتاج لا تتغير ما دام وضع الإنتاج نافذًا. تدوير Access Token متاح.']
                    + array_fill_keys($exception->fields, $message),
                $environment->value,
            );
        } catch (DomainException) {
            return to_route('staff.whatsapp')->withInput()->withErrors(['phone_number_id' => 'هذا المعرّف مستخدم في البيئة الأخرى.'], $environment->value);
        }

        return to_route('staff.whatsapp')->with('status', $changed ? 'حُفظت إعدادات '.$this->label($environment).'.' : 'لم يتغير شيء.');
    }

    public function updateSecrets(UpdateWhatsAppSecretsRequest $request, WhatsAppEnvironment $environment): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        try {
            $saved = $this->manager->replaceSecrets($setting, $environment, [
                $environment->column('access_token') => $request->validated('access_token'),
                $environment->column('app_secret') => $request->validated('app_secret'),
            ], $request->user(), $request);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('secrets_'.$environment->value);
        }

        return to_route('staff.whatsapp')->with('status', $saved > 0 ? 'حُفظت الأسرار الجديدة لـ'.$this->label($environment).'.' : 'لم يتغير شيء: الحقول الفارغة تُبقي القيم المحفوظة.');
    }

    public function updateWebhookToken(UpdateWhatsAppWebhookTokenRequest $request): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        $saved = $this->manager->replaceSecrets($setting, null, [
            'webhook_verify_token' => $request->validated('webhook_verify_token'),
        ], $request->user(), $request);

        return to_route('staff.whatsapp')->with('status', $saved > 0 ? 'حُفظ رمز التحقق الجديد.' : 'لم يتغير شيء: الحقل الفارغ يُبقي القيمة المحفوظة.');
    }

    /**
     * REQ-WA-35: connection test on the chosen environment. Sends nothing, changes
     * nothing, needs no password confirmation.
     */
    public function testConnection(Request $request, WhatsAppEnvironment $environment): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        $checks = $this->connectionTester->run($setting, $environment, $request->user(), $request);

        return to_route('staff.whatsapp')->with('connection_test', ['environment' => $environment->value, 'checks' => $checks]);
    }

    /**
     * REQ-WA-36, AC-WA-44: show the environment and the full number for review
     * before anything is sent. Nothing is stored or sent here.
     */
    public function reviewSendTest(WhatsAppSendTestRequest $request, WhatsAppEnvironment $environment): View|RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        if ($refusal = $this->sendTestRefusal($setting, $environment, $request)) {
            return $refusal;
        }

        return view('staff.whatsapp.send-test-review', [
            'environment' => $environment,
            'phone' => $request->validated('phone'),
            'formatted' => PhoneNumber::format($request->validated('phone')),
            'sendsLeft' => $this->sendTester->remaining(WhatsAppSendTester::panelKey($request->user())),
        ]);
    }

    /**
     * REQ-WA-24: run the six readiness checks of the production environment on request.
     * Opening the panel never runs them. The result is shown once and never stored,
     * and nothing changes: not the mode, not the OTP source, not a field. No production
     * activation is offered here.
     */
    public function readiness(): View|RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        return view('staff.whatsapp.readiness', [
            'setting' => $setting,
            'result' => $this->readiness->check($setting),
            'checkedAt' => now(),
        ]);
    }

    /**
     * REQ-WA-36, REQ-WA-38: send the test code after the review. Refusals before the
     * send are not counted.
     */
    public function sendTest(WhatsAppSendTestRequest $request, WhatsAppEnvironment $environment): RedirectResponse
    {
        $setting = $this->managedSetting();

        if (! $setting) {
            return $this->notManagedYet();
        }

        if ($refusal = $this->sendTestRefusal($setting, $environment, $request)) {
            return $refusal;
        }

        $phone = $request->validated('phone');
        $result = $this->sendTester->send(
            config: $this->settings->forEnvironment($setting, $environment),
            phone: $phone,
            key: WhatsAppSendTester::panelKey($request->user()),
            origin: WhatsAppSendTester::PANEL,
            quota: null,
            by: $request->user(),
            request: $request,
            // REQ-WA-68: a production test is tied to the settings that sent it.
            fingerprint: $environment === WhatsAppEnvironment::Production ? $this->fingerprint->production($setting) : null,
        );

        $to = '••••'.substr($phone, -4);

        return match ($result['status']) {
            WhatsAppSendStatus::Accepted => to_route('staff.whatsapp')->with('status', 'قبلت Meta طلب الإرسال إلى '.$to.' عبر '.$this->label($environment).'. هذا لا يعني وصول الرسالة: اكتب الرمز الذي وصلك خلال 5 دقائق لتأكيد الاستلام.'),
            WhatsAppSendStatus::Rejected => $this->sendTestError($environment, 'رفضت Meta طلب الإرسال'.($result['error_code'] ? ' (رمز الخطأ '.$result['error_code'].')' : '').'. لم يُرسل رمز الاختبار.'),
            WhatsAppSendStatus::Uncertain => $this->sendTestError($environment, 'لم تتأكد نتيجة الطلب (انتهت المهلة أو انقطع الاتصال أو حدث خطأ لدى Meta). أُلغي رمز الاختبار، ولا يُعتمد هذا الاختبار.'),
            WhatsAppSendStatus::Blocked => $this->sendTestError($environment, 'الإرسال غير ممكن بإعدادات هذه البيئة.'),
        };
    }

    /**
     * REQ-WA-36, AC-WA-23, AC-WA-43: only the code that actually arrived confirms the test.
     */
    public function verifySendTest(VerifyWhatsAppSendTestRequest $request): RedirectResponse
    {
        $result = $this->sendTester->verify(WhatsAppSendTester::panelKey($request->user()), $request->validated('code'), $request->user(), $request);

        return match ($result['outcome']) {
            AdminTestCodes::VERIFIED => to_route('staff.whatsapp')->with('status', 'تأكد استلام رمز الاختبار على ••••'.($result['context']['last4'] ?? '').': الرسالة وصلت والرمز ظهر صحيحاً.'),
            AdminTestCodes::WRONG => to_route('staff.whatsapp')->withErrors(['code' => 'الرمز غير صحيح. المحاولات المتبقية: '.$result['attempts_left'].'.'], 'verify_send_test'),
            AdminTestCodes::EXHAUSTED => to_route('staff.whatsapp')->withErrors(['code' => 'استُنفدت محاولات التحقق وأُلغي رمز الاختبار. أرسل اختباراً جديداً.'], 'verify_send_test'),
            default => to_route('staff.whatsapp')->withErrors(['code' => 'لا يوجد رمز اختبار صالح: انتهت مدته أو لم يُرسل. أرسل اختباراً جديداً.'], 'verify_send_test'),
        };
    }

    /**
     * Reasons to refuse a send test before anything is sent (not counted in the limit).
     */
    private function sendTestRefusal(WhatsAppSetting $setting, WhatsAppEnvironment $environment, Request $request): ?RedirectResponse
    {
        $config = $this->settings->forEnvironment($setting, $environment);

        if (! $config->sendsToMeta()) {
            $missing = collect($this->settings->missingRequiredFields($setting, $environment))
                ->map(fn (string $field) => ['phone_number_id' => 'Phone Number ID', 'access_token' => 'Access Token', 'template_name' => 'اسم القالب'][$field] ?? $field)
                ->join('، ');

            return $this->sendTestError($environment, 'إعدادات '.$this->label($environment).' ناقصة: '.$missing.'.');
        }

        $wait = $this->sendTester->secondsUntilAllowed(WhatsAppSendTester::panelKey($request->user()));

        if ($wait > 0) {
            return $this->sendTestError($environment, 'بلغت حد '.WhatsAppSendTester::MAX_SENDS_PER_HOUR.' اختبارات إرسال في الساعة. حاول بعد '.(int) ceil($wait / 60).' دقيقة.');
        }

        return null;
    }

    private function sendTestError(WhatsAppEnvironment $environment, string $message): RedirectResponse
    {
        return to_route('staff.whatsapp')->withErrors(['phone' => $message], 'send_test_'.$environment->value);
    }

    /**
     * REQ-WA-13, REQ-WA-16, REQ-WA-71: show the current and requested mode and what
     * the switch does to login codes, before anything changes. Nothing is written.
     */
    public function reviewMode(ReviewWhatsAppModeRequest $request): View|RedirectResponse
    {
        $setting = $this->managedSetting();
        $to = $request->mode();

        if ($problem = $this->manager->modeProblem($setting, $to)) {
            return $this->modeError($problem, $setting);
        }

        return view('staff.whatsapp.mode-review', [
            'current' => $setting->mode,
            'to' => $to,
            'testRecipientsWarning' => $to === WhatsAppMode::Test && app()->isProduction(),
        ]);
    }

    /**
     * REQ-WA-09, REQ-WA-13: switch the mode once the impact was acknowledged. Only the
     * mode changes; a refusal writes nothing and is audited (REQ-WA-44).
     */
    public function switchMode(SwitchWhatsAppModeRequest $request): RedirectResponse
    {
        try {
            $setting = $this->manager->switchMode($request->mode(), $request->expectedMode(), $request->user(), $request);
        } catch (ModeChangeRefusedException $exception) {
            return $this->modeError($exception->reason, $this->managedSetting());
        }

        return to_route('staff.whatsapp')->with('status', 'تغيّر وضع الإرسال إلى: '.$setting->mode->label().'.');
    }

    private function modeError(string $reason, ?WhatsAppSetting $setting): RedirectResponse
    {
        $message = match ($reason) {
            ModeChangeRefusedException::NOT_MANAGED => 'انقل إدارة الإعدادات إلى اللوحة أولاً.',
            ModeChangeRefusedException::PREPARING => 'تبديل الوضع غير متاح: السجل قيد التحضير، ومصدر رموز الدخول الفعّال ما زال ملف .env.',
            ModeChangeRefusedException::STALE => 'تغيّر الوضع أو حالة المصدر منذ عرض المراجعة، فلم يُغيَّر شيء. راجع الحالة الحالية وأعد المحاولة.',
            ModeChangeRefusedException::PRODUCTION => 'التفعيل إلى الإنتاج لا يتم من هنا، بل بإجراء التفعيل بعد فحوص الجاهزية.',
            ModeChangeRefusedException::LOG_NOT_ALLOWED => 'وضع «تسجيل فقط» متاح في بيئة التطوير والاختبار فقط.',
            ModeChangeRefusedException::UNCHANGED => 'هذا هو الوضع الحالي، فلم يُغيَّر شيء.',
            ModeChangeRefusedException::TEST_INCOMPLETE => 'إعدادات بيئة الاختبار ناقصة ('.collect($setting ? $this->settings->missingRequiredFields($setting, WhatsAppEnvironment::Test) : [])
                ->map(fn (string $field) => ['phone_number_id' => 'Phone Number ID', 'access_token' => 'Access Token', 'template_name' => 'اسم القالب'][$field] ?? $field)
                ->join('، ').')، فلا يمكن التبديل إليها.',
            default => 'تعذّر تبديل الوضع.',
        };

        return to_route('staff.whatsapp')->withErrors(['mode' => $message], 'mode');
    }

    /**
     * REQ-WA-66: review an activation. Nothing is checked at Meta here (D4): the
     * readiness checks run with the activation itself.
     */
    public function reviewActivation(ReviewActivationRequest $request): View|RedirectResponse
    {
        $setting = $this->managedSetting();
        $to = $request->mode();

        if ($problem = $this->activation->targetProblem($setting, $to)) {
            return $this->activationError($problem, $setting);
        }

        return view('staff.whatsapp.activation-review', [
            'setting' => $setting,
            'state' => WhatsAppActivation::state($setting),
            'to' => $to,
        ]);
    }

    /**
     * REQ-WA-66, REQ-WA-67: activate. A refusal changes nothing and shows why; the
     * readiness result of this attempt is shown, never stored.
     */
    public function activate(ActivateWhatsAppRequest $request): View|RedirectResponse
    {
        try {
            $this->activation->activate($request->mode(), $request->expectedMode(), $request->validated('expected_state'), $request->reference(), $request->user(), $request);
        } catch (ActivationRefusedException $exception) {
            if ($exception->checks === null) {
                return $this->activationError($exception->reason, $this->managedSetting(), $exception->recorded);
            }

            return view('staff.whatsapp.activation-refused', [
                'to' => $request->mode(),
                'message' => $this->activationMessage($exception->reason, $this->managedSetting()),
                'checks' => $exception->checks,
                'recorded' => $exception->recorded,
            ]);
        } catch (ActivationOutcomeUnknownException) {
            return to_route('staff.whatsapp')->withErrors(['activation' => 'تعذّر تأكيد نتيجة التفعيل: فشل حفظ المعاملة ولم يمكن قراءة الحالة بعده. لا يُعدّ التفعيل ناجحًا ولا مرفوضًا. راجع مصدر رموز الدخول في هذه الصفحة قبل أي محاولة جديدة.'], 'activation');
        }

        return to_route('staff.whatsapp')->with('status', 'تم التفعيل: صارت إعدادات هذه اللوحة مصدر رموز الدخول، بوضع «'.$request->mode()->label().'».');
    }

    private function activationError(string $reason, ?WhatsAppSetting $setting, ?bool $recorded = null): RedirectResponse
    {
        return to_route('staff.whatsapp')->withErrors(['activation' => $this->activationMessage($reason, $setting).$this->recordedNote($recorded)], 'activation');
    }

    private function recordedNote(?bool $recorded): string
    {
        return $recorded === false ? ' تعذّر أيضًا تسجيل هذه المحاولة في سجل التدقيق.' : '';
    }

    private function activationMessage(string $reason, ?WhatsAppSetting $setting): string
    {
        return match ($reason) {
            ActivationRefusedException::NOT_MANAGED => 'انقل إدارة الإعدادات إلى اللوحة أولاً.',
            ActivationRefusedException::STALE => 'تغيّر الوضع أو حالة المصدر منذ عرض المراجعة، فلم يُفعَّل شيء. راجع الحالة الحالية وأعد المحاولة.',
            ActivationRefusedException::NOT_ALLOWED => app()->isProduction()
                ? 'في بيئة التطبيق الحالية لا يُفعَّل إلا الإنتاج.'
                : 'لا يمكن تفعيل هذا الوضع في بيئة التطبيق الحالية.',
            ActivationRefusedException::UNCHANGED => 'مصدر رموز الدخول مفعّل بهذا الوضع أصلًا، فلم يتغير شيء.',
            ActivationRefusedException::ALREADY_ACTIVE => 'المصدر مفعّل: انتقل إلى هذا الوضع من «تبديل الوضع». التفعيل بعد ذلك للإنتاج فقط.',
            ActivationRefusedException::TEST_INCOMPLETE => 'إعدادات بيئة الاختبار ناقصة ('.collect($setting ? $this->settings->missingRequiredFields($setting, WhatsAppEnvironment::Test) : [])
                ->map(fn (string $field) => ['phone_number_id' => 'Phone Number ID', 'access_token' => 'Access Token', 'template_name' => 'اسم القالب'][$field] ?? $field)
                ->join('، ').')، فلا يمكن تفعيلها.',
            ActivationRefusedException::REFERENCE_INVALID => 'النص المرجعي للإقرار غير مقبول، فلم يُفعَّل شيء.',
            ActivationRefusedException::READINESS_FAILED => 'لم تُستوفَ شروط الجاهزية في هذه المحاولة، فلم يُفعَّل شيء.',
            ActivationRefusedException::SNAPSHOT_CHANGED => 'تغيّرت الإعدادات المحفوظة أثناء الفحوص، فلم يُفعَّل شيء. أعد المحاولة لتُفحص القيم الحالية.',
            ActivationRefusedException::EVIDENCE_LOST => 'لم يعد اختبار الإرسال المؤكَّد صالحًا عند التفعيل، فلم يُفعَّل شيء. أجرِ اختبار إرسال جديدًا من اللوحة.',
            ActivationRefusedException::DATABASE_ERROR => 'تعذّر التفعيل بسبب خطأ في قاعدة البيانات، ولم يتغير شيء.',
            default => 'تعذّر التفعيل، ولم يتغير شيء.',
        };
    }

    private function managedSetting(): ?WhatsAppSetting
    {
        return WhatsAppSetting::query()->first();
    }

    private function notManagedYet(): RedirectResponse
    {
        return to_route('staff.whatsapp')->withErrors(['whatsapp' => 'انقل إدارة الإعدادات إلى اللوحة أولاً.']);
    }

    private function label(WhatsAppEnvironment $environment): string
    {
        return $environment === WhatsAppEnvironment::Production ? 'بيئة الإنتاج' : 'بيئة الاختبار';
    }
}
