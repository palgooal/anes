<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\WhatsApp\TakeOverWhatsAppSettingsRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppEnvironmentRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppGeneralRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppSecretsRequest;
use App\Http\Requests\Staff\WhatsApp\UpdateWhatsAppWebhookTokenRequest;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\TakeOverNotAllowedException;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppEnvironment;
use DomainException;
use Illuminate\Http\RedirectResponse;
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
    public const TAKE_OVER_NOT_ALLOWED = 'نقل إدارة واتساب إلى اللوحة غير متاح في هذه البيئة حاليًا، لأن الإرسال سيتوقف ولا توجد بعد وسيلة لتفعيله من اللوحة. سيُتاح مع مرحلة تفعيل الإنتاج، وحتى ذلك الحين يستمر الإرسال من ملف .env.';

    public function __construct(
        private WhatsAppSettings $settings,
        private WhatsAppSettingsManager $manager,
    ) {}

    public function show(): View
    {
        $setting = WhatsAppSetting::query()->first();
        $config = $this->settings->current();

        // REQ-WA-63: sending is stopped because the active environment is missing required fields.
        $incomplete = null;

        if ($setting && $config->blockedReason === WhatsAppConfig::BLOCKED_INCOMPLETE && $config->environment) {
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

        return to_route('staff.whatsapp')->with('status', 'أصبحت إعدادات واتساب تُدار من هذه اللوحة، ولم تعد تُقرأ من ملف .env.');
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
