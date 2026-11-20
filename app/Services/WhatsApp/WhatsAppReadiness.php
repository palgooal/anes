<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use Carbon\CarbonImmutable;

/**
 * The readiness checks of the production environment (REQ-WA-24, REQ-WA-25):
 * six conditions, each with its own result and reason. All six are mandatory.
 *
 * - Read-only: nothing is written, nothing is sent, and neither the mode nor the
 *   OTP source changes (AC-WA-46). The checks run on the values of the row they
 *   are given, so activation (REQ-WA-67) can run them on its snapshot.
 * - The result is never stored: it is valid for the run that produced it only.
 * - Any failure to reach Meta, and any incomplete or unexpected answer, leaves the
 *   condition unmet. debug_token alone never proves readiness (REQ-WA-25), and
 *   Meta accepting a message is not delivery: only a code an administrator typed
 *   counts (condition 5).
 * - Results hold no secret and no full phone number.
 */
class WhatsAppReadiness
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    /** Condition 6 is given with the activation request; it cannot be met by a check alone. */
    public const PENDING = 'pending';

    /** Fields required by condition (1); the template language may use its default (REQ-WA-03). */
    public const REQUIRED_FIELDS = ['phone_number_id', 'waba_id', 'display_number', 'access_token', 'template_name'];

    public const MIN_DAYS_BEFORE_EXPIRY = 7;

    /** VER-AUTH-03: when the template footer sets a code expiry, it is 5 minutes. */
    public const CODE_EXPIRATION_MINUTES = 5;

    public const EVIDENCE_HOURS = 24;

    public function __construct(private MetaGraphClient $meta, private SettingsFingerprint $fingerprint) {}

    /**
     * Run the six checks on the production settings of $setting.
     *
     * @param  ?string  $ownershipReference  The reference text of the REQ-AUTH-37 acknowledgement, given at activation only.
     * @return array{ready: bool, checks: array<int, array{status: string, message: string, details: array<string, string>}>}
     */
    public function check(WhatsAppSetting $setting, ?string $ownershipReference = null): array
    {
        $environment = WhatsAppEnvironment::Production;
        $apiVersion = $this->filled($setting->api_version) ?? WhatsAppSettings::DEFAULT_API_VERSION;
        $token = $this->filled($setting->field($environment, 'access_token'));

        $checks = [
            1 => $this->checkFields($setting),
            2 => $this->checkToken($apiVersion, $token),
            3 => $this->checkPhoneNumber($apiVersion, $token, $setting),
            4 => $this->checkTemplate($apiVersion, $token, $setting),
            5 => $this->checkEvidence($setting),
            6 => $this->checkAcknowledgement($ownershipReference),
        ];

        return [
            'ready' => collect($checks)->every(fn (array $check) => $check['status'] === self::PASSED),
            'checks' => $checks,
        ];
    }

    /**
     * The confirmed panel send test that satisfies condition (5), or null (REQ-WA-68, AC-WA-50):
     * confirmed by a known administrator within the last 24 hours, on production, from the
     * panel, and with the fingerprint of the settings as they are now.
     */
    public function evidence(WhatsAppSetting $setting): ?WhatsAppSettingsAudit
    {
        $current = $this->fingerprint->production($setting);

        return WhatsAppSettingsAudit::query()
            ->where('action', WhatsAppAuditAction::SendTestVerification)
            ->where('environment', WhatsAppEnvironment::Production)
            ->whereNotNull('user_id')
            ->whereHas('user')
            ->where('created_at', '>=', now()->subHours(self::EVIDENCE_HOURS))
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->first(fn (WhatsAppSettingsAudit $audit) => ($audit->changes['outcome'] ?? null) === AdminTestCodes::VERIFIED
                && ($audit->changes['origin'] ?? null) === WhatsAppSendTester::PANEL
                && SettingsFingerprint::matches($audit->changes['fingerprint'] ?? null, $current));
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkFields(WhatsAppSetting $setting): array
    {
        $missing = array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field) => $this->filled($setting->field(WhatsAppEnvironment::Production, $field)) === null,
        ));

        if ($missing !== []) {
            return $this->result(self::FAILED, 'إعدادات بيئة الإنتاج ناقصة.', ['missing' => implode(',', $missing)]);
        }

        return $this->result(self::PASSED, 'إعدادات بيئة الإنتاج مكتملة.');
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkToken(string $apiVersion, ?string $token): array
    {
        if (! $token) {
            return $this->result(self::FAILED, 'لم يُحفظ Access Token لبيئة الإنتاج.');
        }

        try {
            $inspection = $this->meta->inspectToken($apiVersion, $token);
        } catch (MetaGraphException) {
            // PND-WA-03: whether debug_token accepts a token inspecting itself is still to be confirmed.
            return $this->result(self::FAILED, 'تعذّر فحص الرمز لدى Meta، فلا تُعدّ صلاحيته معروفة.');
        }

        if (! $inspection->valid) {
            return $this->result(self::FAILED, 'أفادت Meta بأن الرمز غير صالح.');
        }

        if (! $inspection->canSendMessages()) {
            return $this->result(self::FAILED, 'الرمز لا يملك صلاحية '.TokenInspection::MESSAGING_SCOPE.'.');
        }

        if ($inspection->neverExpires) {
            return $this->result(self::PASSED, 'الرمز صالح، وبلا تاريخ انتهاء حسب Meta.', ['expiry' => 'never']);
        }

        if ($inspection->expiresAt === null) {
            return $this->result(self::FAILED, 'لم تُعِد Meta تاريخ انتهاء الرمز، فحالة انتهائه غير معروفة.', ['expiry' => 'unknown']);
        }

        $expiry = ['expiry' => $inspection->expiresAt->toDateTimeString()];

        if ($inspection->expiresAt->lessThan(CarbonImmutable::now()->addDays(self::MIN_DAYS_BEFORE_EXPIRY))) {
            return $this->result(self::FAILED, 'يبقى على انتهاء الرمز أقل من '.self::MIN_DAYS_BEFORE_EXPIRY.' أيام.', $expiry);
        }

        return $this->result(self::PASSED, 'الرمز صالح ويبقى على انتهائه '.self::MIN_DAYS_BEFORE_EXPIRY.' أيام على الأقل.', $expiry);
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkPhoneNumber(string $apiVersion, ?string $token, WhatsAppSetting $setting): array
    {
        $phoneNumberId = $this->filled($setting->field(WhatsAppEnvironment::Production, 'phone_number_id'));
        $saved = $this->filled($setting->field(WhatsAppEnvironment::Production, 'display_number'));

        if (! $token || ! $phoneNumberId || ! $saved) {
            return $this->result(self::FAILED, 'يلزم Phone Number ID ورقم العرض وAccess Token.');
        }

        try {
            $number = $this->meta->phoneNumber($apiVersion, $token, $phoneNumberId);
        } catch (MetaGraphException) {
            return $this->result(self::FAILED, 'تعذّر الاستعلام عن الرقم لدى Meta.');
        }

        $digits = preg_replace('/\D/', '', (string) $number['display_phone_number']);

        if ($digits === '') {
            return $this->result(self::FAILED, 'لم تُعِد Meta رقم العرض لهذا المعرّف.');
        }

        $last4 = ['meta_last4' => substr($digits, -4), 'saved_last4' => substr($saved, -4)];

        if ($digits !== $saved) {
            return $this->result(self::FAILED, 'رقم العرض لدى Meta لا يطابق رقم العرض المحفوظ.', $last4);
        }

        return $this->result(self::PASSED, 'الرقم موجود لدى Meta ورقم العرض مطابق للمحفوظ.', $last4);
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkTemplate(string $apiVersion, ?string $token, WhatsAppSetting $setting): array
    {
        $wabaId = $this->filled($setting->field(WhatsAppEnvironment::Production, 'waba_id'));
        $name = $this->filled($setting->field(WhatsAppEnvironment::Production, 'template_name'));
        $language = $this->filled($setting->field(WhatsAppEnvironment::Production, 'template_language')) ?? WhatsAppSettings::DEFAULT_TEMPLATE_LANGUAGE;

        if (! $token || ! $wabaId || ! $name) {
            return $this->result(self::FAILED, 'يلزم WhatsApp Business Account ID واسم القالب وAccess Token.');
        }

        try {
            $templates = $this->meta->templates($apiVersion, $token, $wabaId, $name, withComponents: true);
        } catch (MetaGraphException) {
            return $this->result(self::FAILED, 'تعذّر الاستعلام عن القالب لدى Meta.');
        }

        $template = collect($templates)->firstWhere('language', $language);

        if (! $template) {
            return $this->result(self::FAILED, 'لا يوجد قالب بهذا الاسم بلغة '.$language.'.', ['language' => $language]);
        }

        $details = array_filter([
            'language' => $template['language'],
            'status' => $template['status'],
            'category' => $template['category'],
        ], fn ($value) => $value !== null);

        if (strtoupper((string) $template['status']) !== 'APPROVED') {
            return $this->result(self::FAILED, 'القالب غير معتمد.', $details);
        }

        if (strtoupper((string) $template['category']) !== 'AUTHENTICATION') {
            return $this->result(self::FAILED, 'فئة القالب ليست AUTHENTICATION.', $details);
        }

        if (! is_array($template['components'] ?? null)) {
            return $this->result(self::FAILED, 'لم تُعِد Meta مكونات القالب، فلا يمكن التحقق من مدة صلاحية الرمز.', $details);
        }

        $expiry = $this->codeExpiration($template['components']);

        if ($expiry === false) {
            return $this->result(self::FAILED, 'مدة صلاحية الرمز في تذييل القالب بصيغة غير متوقعة.', $details);
        }

        if ($expiry !== null && $expiry !== self::CODE_EXPIRATION_MINUTES) {
            return $this->result(self::FAILED, 'مدة صلاحية الرمز في تذييل القالب ليست '.self::CODE_EXPIRATION_MINUTES.' دقائق.', $details + ['code_expiration_minutes' => (string) $expiry]);
        }

        return $this->result(self::PASSED, 'القالب معتمد من فئة AUTHENTICATION وبنفس اللغة.', $details + [
            'code_expiration_minutes' => $expiry === null ? 'not_set' : (string) $expiry,
        ]);
    }

    /**
     * code_expiration_minutes of the footer: null when no footer sets it, false when
     * a footer sets it in an unexpected form.
     *
     * @param  list<array<string, mixed>>  $components
     */
    private function codeExpiration(array $components): int|false|null
    {
        $found = null;

        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'FOOTER' || ! array_key_exists('code_expiration_minutes', $component)) {
                continue;
            }

            $value = $component['code_expiration_minutes'];

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return false;
            }

            if ($found !== null && $found !== (int) $value) {
                return false;
            }

            $found = (int) $value;
        }

        return $found;
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkEvidence(WhatsAppSetting $setting): array
    {
        $evidence = $this->evidence($setting);

        if (! $evidence) {
            return $this->result(self::FAILED, 'لا يوجد اختبار إرسال مؤكَّد من اللوحة على بيئة الإنتاج خلال آخر '.self::EVIDENCE_HOURS.' ساعة بالإعدادات الحالية.');
        }

        return $this->result(self::PASSED, 'يوجد اختبار إرسال مؤكَّد من اللوحة على بيئة الإنتاج بالإعدادات الحالية.', [
            'confirmed_at' => $evidence->created_at->toDateTimeString(),
            'recipient_last4' => (string) ($evidence->changes['recipient_last4'] ?? ''),
        ]);
    }

    /**
     * @return array{status: string, message: string, details: array<string, string>}
     */
    private function checkAcknowledgement(?string $reference): array
    {
        if ($this->filled($reference) === null) {
            return $this->result(self::PENDING, 'يُقدَّم الإقرار بتحقق REQ-AUTH-37 ونصه المرجعي عند التفعيل، ويُحفظ في سجل تدقيق التفعيل. إدخال النص لا يُعدّ إثباتًا فعليًا للملكية.');
        }

        return $this->result(self::PASSED, 'قُدِّم الإقرار بنص مرجعي.');
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
