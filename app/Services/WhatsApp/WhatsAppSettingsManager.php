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
use RuntimeException;
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

    /**
     * Production identifiers frozen while production mode is in force with the database
     * source active (REQ-WA-15, AC-WA-10): Phone Number ID, WABA ID, the template and its
     * language. Only these: the access token can still be rotated after debug_token
     * accepts the new one (REQ-WA-20).
     */
    public const FROZEN_PRODUCTION_FIELDS = ['phone_number_id', 'waba_id', 'template_name', 'template_language'];

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

    // --- Server commands (REQ-WA-69, REQ-WA-70, PH-WA-6.8) --------------------------------------

    /** Modes the server command may switch to (REQ-WA-69): never log, never production. */
    public const SERVER_MODES = [WhatsAppMode::Disabled, WhatsAppMode::Test];

    /** The .env drivers a restore accepts (REQ-WA-70). Anything else is refused. */
    public const ENV_DRIVERS = ['meta', 'log'];

    /** The .env settings a "meta" driver needs before a restore (REQ-WA-70), by variable name. */
    public const ENV_REQUIRED = ['phone_number_id' => 'WHATSAPP_PHONE_NUMBER_ID', 'access_token' => 'WHATSAPP_ACCESS_TOKEN'];

    /**
     * Why the server command cannot switch to a mode right now, or null when it can
     * (REQ-WA-69, AC-WA-51). Reads only.
     *
     * @param  bool  $acknowledged  The operator acknowledged the impact on sign-in (needed for test in production).
     */
    public function serverModeProblem(?WhatsAppSetting $setting, WhatsAppMode $to, ?WhatsAppMode $expected, bool $acknowledged): ?string
    {
        return match (true) {
            $to === WhatsAppMode::Production => ModeChangeRefusedException::PRODUCTION,
            ! in_array($to, self::SERVER_MODES, true) => ModeChangeRefusedException::LOG_NOT_ALLOWED,
            default => $this->modeProblem($setting, $to, $expected)
                ?? ($to === WhatsAppMode::Test && app()->isProduction() && ! $acknowledged ? ModeChangeRefusedException::ACKNOWLEDGEMENT_REQUIRED : null),
        };
    }

    /**
     * Switch the mode from the server (REQ-WA-69): in a short transaction, with the row
     * locked and every condition checked again, only the mode changes, audited without
     * a user or a request (REQ-WA-44). A refusal writes nothing at all, not even an
     * audit entry (AC-WA-51).
     *
     * @param  WhatsAppMode  $expected  The mode shown to the operator, to detect a change since then.
     *
     * @throws ModeChangeRefusedException
     */
    public function switchModeFromServer(WhatsAppMode $to, WhatsAppMode $expected, bool $acknowledged): WhatsAppSetting
    {
        return DB::transaction(function () use ($to, $expected, $acknowledged) {
            $setting = WhatsAppSetting::query()->lockForUpdate()->first();

            if ($problem = $this->serverModeProblem($setting, $to, $expected, $acknowledged)) {
                throw new ModeChangeRefusedException($problem, $setting?->mode);
            }

            $old = $setting->mode;
            $setting->mode = $to;

            // A saving listener can stop the write without an exception: nothing may be claimed then.
            if (! $setting->save()) {
                throw new RuntimeException('The WhatsApp mode was not saved.');
            }

            WhatsAppSettingsAudit::record(WhatsAppAuditAction::ModeChanged, null, $to->environment(), changes: [
                'mode' => ['old' => $old->value, 'new' => $to->value],
                'via' => 'server',
            ], request: null);

            return $setting;
        });
    }

    /**
     * Why .env cannot be restored as the OTP source right now, or null when it can
     * (REQ-WA-70, AC-WA-52). A preliminary check of the settings in force, read locally:
     * nothing is sent to Meta, so it never proves that a code will arrive.
     */
    public function restoreProblem(?WhatsAppSetting $setting): ?string
    {
        $driver = config('services.whatsapp.driver');

        return match (true) {
            $setting === null => EnvRestoreRefusedException::NOT_MANAGED,
            ! $setting->isDatabaseSource() => EnvRestoreRefusedException::PREPARING,
            ! in_array($driver, self::ENV_DRIVERS, true) => EnvRestoreRefusedException::DRIVER_NOT_ALLOWED,
            $driver === 'log' && ! app()->environment('local', 'testing') => EnvRestoreRefusedException::LOG_NOT_ALLOWED,
            $driver === 'meta' && $this->missingEnvSettings() !== [] => EnvRestoreRefusedException::ENV_INCOMPLETE,
            default => null,
        };
    }

    /**
     * The .env variables a "meta" driver still lacks (names only, never values).
     *
     * @return list<string>
     */
    public function missingEnvSettings(): array
    {
        return array_values(array_filter(
            self::ENV_REQUIRED,
            fn (string $variable, string $key) => ! $this->isFilled(config('services.whatsapp.'.$key)),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Warnings about the .env settings that do not stop a restore (decision 4, PH-WA-6.8):
     * the document requires only the driver, Phone Number ID and access token.
     *
     * @return list<string>
     */
    public function restoreWarnings(): array
    {
        if (config('services.whatsapp.driver') === 'meta' && ! $this->isFilled(config('services.whatsapp.login_template'))) {
            return ['اسم قالب الدخول في إعدادات .env فارغ، وسيرفض Meta الإرسال به. اختبر .env بأمر whatsapp:test --legacy-env قبل الاستعادة.'];
        }

        return [];
    }

    /**
     * Make .env the OTP source again (REQ-WA-70): in a short transaction, with the row
     * locked and every condition checked again, only the source state goes back to
     * "being prepared". The row, its secrets and its mode stay as they are, and the
     * change is audited without a user or a request (REQ-WA-44). A refusal writes
     * nothing at all (AC-WA-52). Going back is done by activation from the panel only.
     *
     * @throws EnvRestoreRefusedException
     */
    public function restoreEnv(): WhatsAppSetting
    {
        return DB::transaction(function () {
            $setting = WhatsAppSetting::query()->lockForUpdate()->first();

            if ($problem = $this->restoreProblem($setting)) {
                throw new EnvRestoreRefusedException($problem, $problem === EnvRestoreRefusedException::ENV_INCOMPLETE ? $this->missingEnvSettings() : []);
            }

            $setting->database_source_activated_at = null;

            if (! $setting->save()) {
                throw new RuntimeException('The OTP source was not restored.');
            }

            WhatsAppSettingsAudit::record(WhatsAppAuditAction::EnvRestored, null, null, changes: [
                'source' => ['old' => WhatsAppActivation::ACTIVE, 'new' => WhatsAppActivation::PREPARING],
                'mode' => $setting->mode->value,
                'env_driver' => config('services.whatsapp.driver'),
                'via' => 'server',
            ], request: null);

            return $setting;
        });
    }

    private function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
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
     * The fields of an environment that cannot change right now (REQ-WA-15): the
     * production identifiers while production mode is in force with the database
     * source active, nothing otherwise. Reads only.
     *
     * @return list<string>
     */
    public function frozenFields(?WhatsAppSetting $setting, ?WhatsAppEnvironment $environment): array
    {
        return $environment === WhatsAppEnvironment::Production
            && $setting?->isDatabaseSource()
            && $setting->mode === WhatsAppMode::Production
                ? self::FROZEN_PRODUCTION_FIELDS
                : [];
    }

    /**
     * Change the non-secret fields of one environment. Returns false when nothing changed.
     *
     * @param  array<string, ?string>  $values
     *
     * @throws ProductionIdentifiersFrozenException when a frozen production identifier would change (REQ-WA-15); nothing is written.
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
     * Save the changed columns in a short transaction, with the row locked and read
     * again, so the frozen fields (REQ-WA-15) are checked against the mode and source
     * state at the moment of writing: a mode switch cannot slip in between. A refusal
     * writes nothing, not even the fields that were allowed, and is audited with the
     * field names only.
     *
     * @param  array<string, ?string>  $columns
     *
     * @throws ProductionIdentifiersFrozenException
     */
    private function updateFields(WhatsAppSetting $setting, array $columns, ?WhatsAppEnvironment $environment, User $by, Request $request): bool
    {
        try {
            return DB::transaction(function () use ($setting, $columns, $environment, $by, $request) {
                $locked = WhatsAppSetting::query()->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();
                $changes = [];

                foreach ($columns as $column => $value) {
                    $value = $value === '' ? null : $value;
                    $old = $locked->getAttribute($column);

                    if ($old !== $value) {
                        $changes[$column] = ['old' => $old, 'new' => $value];
                    }
                }

                if ($changes === []) {
                    return false;
                }

                $frozen = array_values(array_filter(
                    $this->frozenFields($locked, $environment),
                    fn (string $field) => array_key_exists($environment->column($field), $changes),
                ));

                if ($frozen !== []) {
                    throw new ProductionIdentifiersFrozenException($frozen);
                }

                foreach ($changes as $column => $change) {
                    $locked->setAttribute($column, $change['new']);
                }

                $locked->save();
                WhatsAppSettingsAudit::record(WhatsAppAuditAction::SettingsUpdated, $by, $environment, $changes, $request);
                $setting->setRawAttributes($locked->getAttributes(), true);

                return true;
            });
        } catch (ProductionIdentifiersFrozenException $exception) {
            WhatsAppSettingsAudit::record(WhatsAppAuditAction::SettingsChangeRefused, $by, $environment, [
                'reason' => ProductionIdentifiersFrozenException::REASON,
                'fields' => $exception->fields,
            ], $request);

            throw $exception;
        }
    }
}
