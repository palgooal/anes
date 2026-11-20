<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use Illuminate\Http\Request;

/**
 * Connection test for one environment (REQ-WA-35, AC-WA-21): access token,
 * phone number and template, each checked on its own. Read-only: nothing is
 * sent, and neither the mode nor any saved field changes.
 *
 * Readiness for production (REQ-WA-24) is decided in PH-WA-6, not here.
 */
class WhatsAppConnectionTester
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(private MetaGraphClient $meta) {}

    /**
     * Run the three checks and record the outcome in the audit trail.
     *
     * @return array<string, array{status: string, message: string, details: array<string, string>}>
     */
    public function run(WhatsAppSetting $setting, WhatsAppEnvironment $environment, User $by, Request $request): array
    {
        $apiVersion = $this->filled($setting->api_version) ?? WhatsAppSettings::DEFAULT_API_VERSION;
        $token = $this->filled($setting->field($environment, 'access_token'));

        $checks = [
            'token' => $this->checkToken($apiVersion, $token),
            'phone_number' => $this->checkPhoneNumber($apiVersion, $token, $setting, $environment),
            'template' => $this->checkTemplate($apiVersion, $token, $setting, $environment),
        ];

        WhatsAppSettingsAudit::record(WhatsAppAuditAction::ConnectionTested, $by, $environment,
            changes: collect($checks)->map(fn (array $check) => ['status' => $check['status']] + $check['details'])->all(),
            request: $request,
        );

        return $checks;
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkToken(string $apiVersion, ?string $token): array
    {
        if (! $token) {
            return $this->result(self::SKIPPED, 'لم يُحفظ Access Token لهذه البيئة.');
        }

        try {
            $inspection = $this->meta->inspectToken($apiVersion, $token);
        } catch (MetaGraphException) {
            return $this->result(self::FAILED, 'تعذّر فحص الرمز لدى Meta.');
        }

        $expiry = match (true) {
            $inspection->neverExpires => 'never',
            $inspection->expiresAt !== null => $inspection->expiresAt->toDateString(),
            default => 'unknown',
        };

        if (! $inspection->valid) {
            return $this->result(self::FAILED, 'Meta تقول إن الرمز غير صالح.', ['expiry' => $expiry]);
        }

        if (! $inspection->canSendMessages()) {
            return $this->result(self::FAILED, 'الرمز لا يملك صلاحية whatsapp_business_messaging.', ['expiry' => $expiry]);
        }

        return $this->result(self::PASSED, 'الرمز صالح ويملك صلاحية الإرسال.', ['expiry' => $expiry]);
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkPhoneNumber(string $apiVersion, ?string $token, WhatsAppSetting $setting, WhatsAppEnvironment $environment): array
    {
        $phoneNumberId = $this->filled($setting->field($environment, 'phone_number_id'));

        if (! $token || ! $phoneNumberId) {
            return $this->result(self::SKIPPED, 'يلزم Phone Number ID وAccess Token.');
        }

        try {
            $number = $this->meta->phoneNumber($apiVersion, $token, $phoneNumberId);
        } catch (MetaGraphException) {
            return $this->result(self::FAILED, 'تعذّر الاستعلام عن الرقم لدى Meta.');
        }

        $saved = $this->filled($setting->field($environment, 'display_number'));
        $digits = preg_replace('/\D/', '', (string) $number['display_phone_number']);

        return $this->result(self::PASSED, 'الرقم موجود لدى Meta.', array_filter([
            'display_phone_number' => $number['display_phone_number'],
            'verified_name' => $number['verified_name'],
            'quality_rating' => $number['quality_rating'],
            'code_verification_status' => $number['code_verification_status'],
            'matches_saved_display_number' => $saved === null ? 'not_saved' : ($saved === $digits ? 'yes' : 'no'),
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkTemplate(string $apiVersion, ?string $token, WhatsAppSetting $setting, WhatsAppEnvironment $environment): array
    {
        $wabaId = $this->filled($setting->field($environment, 'waba_id'));
        $name = $this->filled($setting->field($environment, 'template_name'));
        $language = $this->filled($setting->field($environment, 'template_language')) ?? WhatsAppSettings::DEFAULT_TEMPLATE_LANGUAGE;

        if (! $token || ! $wabaId || ! $name) {
            return $this->result(self::SKIPPED, 'يلزم WhatsApp Business Account ID واسم القالب وAccess Token.');
        }

        try {
            $templates = $this->meta->templates($apiVersion, $token, $wabaId, $name);
        } catch (MetaGraphException) {
            return $this->result(self::FAILED, 'تعذّر الاستعلام عن القالب لدى Meta.');
        }

        $template = collect($templates)->firstWhere('language', $language);

        if (! $template) {
            return $this->result(self::FAILED, 'لا يوجد قالب بهذا الاسم وهذه اللغة.', ['language' => $language]);
        }

        $details = array_filter([
            'language' => $template['language'],
            'status' => $template['status'],
            'category' => $template['category'],
        ], fn ($value) => $value !== null);

        if (strtoupper((string) $template['status']) !== 'APPROVED') {
            return $this->result(self::FAILED, 'القالب موجود لكنه غير معتمد.', $details);
        }

        return $this->result(self::PASSED, 'القالب موجود ومعتمد.', $details);
    }

    /**
     * @param  array<string, string>  $details
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function result(string $status, string $message, array $details = []): array
    {
        return ['status' => $status, 'message' => $message, 'details' => $details];
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
