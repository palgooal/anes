<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Every change to the WhatsApp settings from the panel goes through here, so each
 * one is saved together with its audit entry (REQ-WA-44, REQ-WA-45).
 *
 * Secrets are never returned, logged or written to the audit trail: only a flag
 * and their last 4 characters (REQ-WA-18, REQ-WA-19).
 */
class WhatsAppSettingsManager
{
    /** Non-secret fields of each environment that the panel may change. */
    public const ENVIRONMENT_FIELDS = ['phone_number_id', 'waba_id', 'display_number', 'template_name', 'template_language'];

    /** Secret fields of each environment. */
    public const ENVIRONMENT_SECRETS = ['access_token', 'app_secret'];

    public function __construct(private MetaGraphClient $meta, private WhatsAppSettings $settings) {}

    /**
     * Whether management may move to the panel in this application environment.
     *
     * Temporary restriction until PH-WA-6 (REQ-WA-64): outside "local" and "testing"
     * the row would start "disabled", and nothing can switch the mode yet.
     */
    public static function takeOverAllowed(): bool
    {
        return app()->environment('local', 'testing');
    }

    /**
     * Create the settings row, being prepared (REQ-WA-05, REQ-WA-65): OTP codes keep
     * using .env until an explicit activation, so creating or editing the row never
     * changes sending. The row starts empty, with "log" mode stored locally and in
     * tests, "disabled" elsewhere; the stored mode has no effect while preparing.
     * Outside local and testing this stays refused until PH-WA-6 lifts REQ-WA-64.
     *
     * @throws TakeOverNotAllowedException outside "local" and "testing" (REQ-WA-64), before anything is written.
     * @throws DomainException when the settings row already exists.
     */
    public function takeOver(User $by, Request $request): WhatsAppSetting
    {
        if (! self::takeOverAllowed()) {
            throw TakeOverNotAllowedException::inThisEnvironment();
        }

        return DB::transaction(function () use ($by, $request) {
            if (WhatsAppSetting::query()->lockForUpdate()->exists()) {
                throw new DomainException('The WhatsApp settings are already managed from the panel.');
            }

            $mode = app()->environment('local', 'testing') ? WhatsAppMode::Log : WhatsAppMode::Disabled;
            // database_source_activated_at stays NULL: the row is being prepared.
            $setting = WhatsAppSetting::create(['mode' => $mode]);

            WhatsAppSettingsAudit::record(WhatsAppAuditAction::TakeOver, $by, changes: [
                'state' => ['old' => null, 'new' => 'preparing'],
                'mode' => ['old' => null, 'new' => $mode->value],
            ], request: $request);

            return $setting;
        });
    }

    /**
     * Change settings shared by both environments. Returns false when nothing changed.
     *
     * @param  array{api_version?: ?string}  $values
     */
    /** Modes the panel may switch to (REQ-WA-13). Production is reached by activation only (REQ-WA-14). */
    public const SWITCHABLE_MODES = [WhatsAppMode::Disabled, WhatsAppMode::Test, WhatsAppMode::Log];

    /**
     * Why switching to a mode is not possible right now, or null when it is
     * (REQ-WA-11, REQ-WA-13, REQ-WA-14, REQ-WA-65). Reads only.
     *
     * @param  ?WhatsAppMode  $expected  The mode the administrator reviewed, to detect a change since then.
     */
    public function modeProblem(?WhatsAppSetting $setting, WhatsAppMode $to, ?WhatsAppMode $expected = null): ?string
    {
        return match (true) {
            $setting === null => ModeChangeRefusedException::NOT_MANAGED,
            ! $setting->isDatabaseSource() => ModeChangeRefusedException::PREPARING,
            $expected !== null && $expected !== $setting->mode => ModeChangeRefusedException::STALE,
            $to === WhatsAppMode::Production => ModeChangeRefusedException::PRODUCTION,
            $to === WhatsAppMode::Log && ! app()->environment('local', 'testing') => ModeChangeRefusedException::LOG_NOT_ALLOWED,
            $to === $setting->mode => ModeChangeRefusedException::UNCHANGED,
            $to === WhatsAppMode::Test && $this->settings->missingRequiredFields($setting, WhatsAppEnvironment::Test) !== [] => ModeChangeRefusedException::TEST_INCOMPLETE,
            default => null,
        };
    }

    /**
     * Switch the mode from the panel (REQ-WA-09, REQ-WA-13): in a short transaction,
     * with the row locked and every condition checked again, only the mode changes.
     * The OTP source state, the settings and the secrets are never touched.
     * A refusal writes nothing to the settings and is audited with its reason.
     *
     * @throws ModeChangeRefusedException
     */
    public function switchMode(WhatsAppMode $to, WhatsAppMode $expected, User $by, Request $request): WhatsAppSetting
    {
        try {
            return DB::transaction(function () use ($to, $expected, $by, $request) {
                $setting = WhatsAppSetting::query()->lockForUpdate()->first();

                if ($problem = $this->modeProblem($setting, $to, $expected)) {
                    throw new ModeChangeRefusedException($problem, $setting?->mode);
                }

                $old = $setting->mode;
                $setting->mode = $to;
                $setting->save();

                WhatsAppSettingsAudit::record(WhatsAppAuditAction::ModeChanged, $by, $to->environment(), changes: [
                    'mode' => ['old' => $old->value, 'new' => $to->value],
                ], request: $request);

                return $setting;
            });
        } catch (ModeChangeRefusedException $exception) {
            WhatsAppSettingsAudit::record(WhatsAppAuditAction::ModeChangeRefused, $by, $to->environment(), changes: [
                'mode' => ['current' => $exception->current?->value, 'requested' => $to->value],
                'reason' => $exception->reason,
            ], request: $request);

            throw $exception;
        }
    }

    /**
     * Record an attempt to switch to production through the switch route, which the
     * request validation refuses before anything is read for writing (REQ-WA-14).
     * Only the normalised requested mode and the current one are kept: never the
     * request content, passwords, CSRF tokens or secrets.
     */
    public function recordProductionAttempt(User $by, Request $request): void
    {
        WhatsAppSettingsAudit::record(WhatsAppAuditAction::ModeChangeRefused, $by, WhatsAppMode::Production->environment(), changes: [
            'mode' => ['current' => WhatsAppSetting::query()->first(['mode'])?->mode?->value, 'requested' => WhatsAppMode::Production->value],
            'reason' => ModeChangeRefusedException::PRODUCTION,
        ], request: $request);
    }

    public function updateGeneral(WhatsAppSetting $setting, array $values, User $by, Request $request): bool
    {
        return $this->updateFields($setting, ['api_version' => $values['api_version'] ?? null], null, $by, $request);
    }

    /**
     * Change the non-secret fields of one environment. Returns false when nothing changed.
     *
     * @param  array<string, ?string>  $values
     */
    public function updateEnvironment(WhatsAppSetting $setting, WhatsAppEnvironment $environment, array $values, User $by, Request $request): bool
    {
        $columns = [];

        foreach (self::ENVIRONMENT_FIELDS as $field) {
            $columns[$environment->column($field)] = $values[$field] ?? null;
        }

        return $this->updateFields($setting, $columns, $environment, $by, $request);
    }

    /**
     * Save the secrets that were filled in; empty ones keep their stored value.
     * An access token is saved only after Meta's debug_token confirms it can send
     * messages (REQ-WA-20). Returns the number of secrets saved.
     *
     * @param  array<string, ?string>  $secrets  column => new value
     *
     * @throws ValidationException when the access token is refused or cannot be checked.
     */
    public function replaceSecrets(WhatsAppSetting $setting, ?WhatsAppEnvironment $environment, #[SensitiveParameter] array $secrets, User $by, Request $request): int
    {
        $secrets = array_filter($secrets, fn (?string $value) => $value !== null && $value !== '');

        if ($secrets === []) {
            return 0;
        }

        $tokenColumn = $environment?->column('access_token');
        $inspection = null;

        if ($tokenColumn !== null && isset($secrets[$tokenColumn])) {
            $inspection = $this->inspectOrReject($setting, $environment, $tokenColumn, $secrets[$tokenColumn], $by, $request);
        }

        DB::transaction(function () use ($setting, $environment, $secrets, $tokenColumn, $inspection, $by, $request) {
            foreach ($secrets as $column => $value) {
                $before = $setting->secretInfo($column);
                $setting->setSecret($column, $value, $by);

                if ($column === $tokenColumn && $inspection) {
                    $setting->forceFill([
                        $environment->column('token_expires_at') => $inspection->expiresAt,
                        $environment->column('token_never_expires') => $inspection->neverExpires,
                        $environment->column('token_expiry_source') => $inspection->expirySource,
                        $environment->column('token_checked_at') => now(),
                    ]);
                }

                WhatsAppSettingsAudit::record(WhatsAppAuditAction::SecretReplaced, $by, $environment, [
                    'secret' => $column,
                    'replaced' => $before['saved'],
                    'last4_old' => $before['saved'] ? $before['last4'] : null,
                    'last4_new' => WhatsAppSetting::lastFour($value),
                ], $request);
            }

            $setting->save();
        });

        return count($secrets);
    }

    /**
     * @throws ValidationException
     */
    private function inspectOrReject(WhatsAppSetting $setting, WhatsAppEnvironment $environment, string $column, #[SensitiveParameter] string $token, User $by, Request $request): TokenInspection
    {
        $apiVersion = $setting->api_version ?: WhatsAppSettings::DEFAULT_API_VERSION;

        try {
            $inspection = $this->meta->inspectToken($apiVersion, $token);
        } catch (MetaGraphException) {
            $this->recordRejection($environment, $column, 'unverifiable', $by, $request);

            throw ValidationException::withMessages([
                'access_token' => 'تعذّر التحقق من الرمز لدى Meta، فلم يُحفظ. تأكد من الرمز وحاول مرة أخرى.',
            ]);
        }

        if (! $inspection->canSendMessages()) {
            $this->recordRejection($environment, $column, $inspection->valid ? 'missing_scope' : 'invalid', $by, $request);

            throw ValidationException::withMessages([
                'access_token' => $inspection->valid
                    ? 'الرمز لا يملك صلاحية إرسال الرسائل ('.TokenInspection::MESSAGING_SCOPE.')، فلم يُحفظ.'
                    : 'أفادت Meta بأن الرمز غير صالح، فلم يُحفظ.',
            ]);
        }

        return $inspection;
    }

    private function recordRejection(WhatsAppEnvironment $environment, string $column, string $reason, User $by, Request $request): void
    {
        WhatsAppSettingsAudit::record(WhatsAppAuditAction::AccessTokenRejected, $by, $environment, [
            'secret' => $column,
            'reason' => $reason,
        ], $request);
    }

    /**
     * @param  array<string, ?string>  $columns
     */
    private function updateFields(WhatsAppSetting $setting, array $columns, ?WhatsAppEnvironment $environment, User $by, Request $request): bool
    {
        $changes = [];

        foreach ($columns as $column => $value) {
            $value = $value === '' ? null : $value;
            $old = $setting->getAttribute($column);

            if ($old !== $value) {
                $changes[$column] = ['old' => $old, 'new' => $value];
                $setting->setAttribute($column, $value);
            }
        }

        if ($changes === []) {
            return false;
        }

        DB::transaction(function () use ($setting, $environment, $changes, $by, $request) {
            $setting->save();
            WhatsAppSettingsAudit::record(WhatsAppAuditAction::SettingsUpdated, $by, $environment, $changes, $request);
        });

        return true;
    }
}
