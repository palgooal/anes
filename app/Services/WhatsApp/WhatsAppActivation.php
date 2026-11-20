<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Atomic activation of the database as the OTP source (REQ-WA-66, REQ-WA-67).
 *
 * 1. Read the row once, outside any transaction, and keep every stored value but
 *    created_at and updated_at as the snapshot (secrets in their encrypted form).
 * 2. For production, run the readiness checks on that same row, with no database
 *    transaction open while Meta is called. A previous check is never reused.
 * 3. In a short transaction, lock the row, read it again and compare every column
 *    of the snapshot; look for the send-test evidence again; then write the source
 *    state and the mode, and the "activated" entry, together.
 * 4. Any refusal rolls everything back. It is recorded as "activation_refused"
 *    after the rollback, without the reference text (AC-WA-47); if that write
 *    fails too, the refusal says so instead of claiming it was recorded.
 * 5. If the commit itself fails, the outcome is read back: confirmed, refused, or
 *    unknown, and an unknown outcome is never reported as one or the other.
 */
class WhatsAppActivation
{
    public const PREPARING = 'preparing';

    public const ACTIVE = 'active';

    /** Modes an activation may set (REQ-WA-66); never "disabled". */
    public const TARGETS = [WhatsAppMode::Production, WhatsAppMode::Test, WhatsAppMode::Log];

    public const REFERENCE_MIN = 5;

    public const REFERENCE_MAX = 500;

    public function __construct(
        private WhatsAppReadiness $readiness,
        private SettingsFingerprint $fingerprint,
        private WhatsAppSettings $settings,
    ) {}

    public static function state(?WhatsAppSetting $setting): ?string
    {
        return $setting === null ? null : ($setting->isDatabaseSource() ? self::ACTIVE : self::PREPARING);
    }

    /**
     * The modes the panel offers to activate now (REQ-WA-66, AC-WA-49). The service
     * checks them again on every request.
     *
     * @return list<WhatsAppMode>
     */
    public function offeredTargets(?WhatsAppSetting $setting): array
    {
        return array_values(array_filter(
            self::TARGETS,
            fn (WhatsAppMode $mode) => $this->targetProblem($setting, $mode) === null,
        ));
    }

    /**
     * Why activating this mode is not possible right now, or null. Reads only.
     */
    public function targetProblem(?WhatsAppSetting $setting, WhatsAppMode $to, ?WhatsAppMode $expectedMode = null, ?string $expectedState = null): ?string
    {
        if ($setting === null) {
            return ActivationRefusedException::NOT_MANAGED;
        }

        if (($expectedMode !== null && $expectedMode !== $setting->mode) || ($expectedState !== null && $expectedState !== self::state($setting))) {
            return ActivationRefusedException::STALE;
        }

        // In a production application only production is ever activated (REQ-WA-66, AC-WA-49);
        // "log" only locally and in tests (REQ-WA-11); never "disabled".
        if (! in_array($to, self::TARGETS, true)
            || (app()->isProduction() && $to !== WhatsAppMode::Production)
            || ($to === WhatsAppMode::Log && ! app()->environment('local', 'testing'))) {
            return ActivationRefusedException::NOT_ALLOWED;
        }

        if ($setting->isDatabaseSource()) {
            if ($setting->mode === $to) {
                return ActivationRefusedException::UNCHANGED;
            }

            if ($to !== WhatsAppMode::Production) {
                return ActivationRefusedException::ALREADY_ACTIVE;
            }
        }

        if ($to === WhatsAppMode::Test && $this->settings->missingRequiredFields($setting, WhatsAppEnvironment::Test) !== []) {
            return ActivationRefusedException::TEST_INCOMPLETE;
        }

        return null;
    }

    /**
     * Why the reference text of the acknowledgement is not accepted, or null (AC-WA-54).
     *
     * A practical guard against secrets, not a promise to detect every one: one line
     * of 5 to 500 characters, none of the secrets stored in the settings row, nothing
     * shaped like a Meta access token or a long key, and no run of 9 digits or more
     * (a full phone number).
     */
    public static function referenceProblem(#[SensitiveParameter] ?string $reference, ?WhatsAppSetting $setting): ?string
    {
        $text = trim((string) $reference);
        $length = mb_strlen($text);

        if ($length < self::REFERENCE_MIN || $length > self::REFERENCE_MAX) {
            return 'اكتب نصًّا مرجعيًّا من '.self::REFERENCE_MIN.' إلى '.self::REFERENCE_MAX.' حرف يشير إلى مستند التحقق من ملكية الرقم.';
        }

        if (preg_match('/\p{Cc}/u', $text)) {
            return 'اكتب النص المرجعي في سطر واحد.';
        }

        foreach ($setting === null ? [] : WhatsAppSetting::SECRETS as $column) {
            $secret = $setting->getAttribute($column);

            if (is_string($secret) && strlen($secret) >= 8 && str_contains($text, $secret)) {
                return 'لا تكتب في النص المرجعي أي سر محفوظ في الإعدادات.';
            }
        }

        if (preg_match('/EAA[A-Za-z0-9]{20,}|[A-Za-z0-9_\-+\/=]{32,}/', $text)) {
            return 'النص المرجعي يحتوي ما يشبه رمزًا أو مفتاحًا سريًّا، فلم يُقبل.';
        }

        $digits = preg_replace('/[\s\-.()+\x{00A0}]/u', '', strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]));

        if (preg_match('/\d{9,}/', (string) $digits)) {
            return 'النص المرجعي يحتوي ما يشبه رقم هاتف كاملًا (9 أرقام متتالية أو أكثر)، فلم يُقبل.';
        }

        return null;
    }

    /**
     * Activate the database as the OTP source with the given mode.
     *
     * @return array{mode: WhatsAppMode, checks: ?array<int, array{status: string, message: string, details: array<string, string>}>}
     *
     * @throws ActivationRefusedException nothing changed; $recorded says whether the refusal was audited.
     * @throws ActivationOutcomeUnknownException the commit failed and its outcome cannot be read.
     */
    public function activate(WhatsAppMode $to, WhatsAppMode $expectedMode, string $expectedState, #[SensitiveParameter] ?string $reference, User $by, Request $request): array
    {
        $attempt = (string) Str::uuid();
        $setting = null;
        $checks = null;

        try {
            // (1) The snapshot: read once, outside any transaction.
            $setting = WhatsAppSetting::query()->first();

            if ($problem = $this->targetProblem($setting, $to, $expectedMode, $expectedState)) {
                throw new ActivationRefusedException($problem);
            }

            if ($to === WhatsAppMode::Production && self::referenceProblem($reference, $setting) !== null) {
                throw new ActivationRefusedException(ActivationRefusedException::REFERENCE_INVALID);
            }

            $snapshot = self::storedValues($setting);

            // (2) Readiness on the snapshot values, with no transaction open while Meta is called.
            if ($to === WhatsAppMode::Production) {
                $result = $this->readiness->check($setting, trim((string) $reference));
                $checks = $result['checks'];

                if (! $result['ready']) {
                    throw new ActivationRefusedException(ActivationRefusedException::READINESS_FAILED, $checks);
                }
            }

            // (3) The short transaction.
            $this->write($setting, $snapshot, $to, $checks, $to === WhatsAppMode::Production ? trim((string) $reference) : null, $attempt, $by, $request);

            return ['mode' => $to, 'checks' => $checks];
        } catch (ActivationRefusedException $exception) {
            $exception->recorded = $this->recordRefusal($exception, $setting, $to, $reference, $attempt, $by, $request);

            throw $exception;
        }
    }

    /**
     * Every stored value of the row but created_at and updated_at, as the database
     * returned it (REQ-WA-67): secrets stay encrypted, so saving the same token again
     * is a change too (AC-WA-48).
     *
     * @return array<string, ?string>
     */
    public static function storedValues(WhatsAppSetting $setting): array
    {
        $values = Arr::except($setting->getRawOriginal(), ['created_at', 'updated_at']);
        ksort($values);

        return array_map(fn ($value) => $value === null ? null : (string) $value, $values);
    }

    /**
     * @param  array<string, ?string>  $snapshot
     * @param  ?array<int, array{status: string, message: string, details: array<string, string>}>  $checks
     *
     * @throws ActivationRefusedException
     * @throws ActivationOutcomeUnknownException
     */
    private function write(WhatsAppSetting $setting, array $snapshot, WhatsAppMode $to, ?array $checks, ?string $reference, string $attempt, User $by, Request $request): void
    {
        $connection = $setting->getConnection();
        $level = $connection->transactionLevel();

        try {
            $connection->beginTransaction();

            $locked = WhatsAppSetting::query()->whereKey($setting->getKey())->lockForUpdate()->first();

            if ($locked === null || self::storedValues($locked) !== $snapshot) {
                throw new ActivationRefusedException(ActivationRefusedException::SNAPSHOT_CHANGED, $checks);
            }

            $evidence = null;

            if ($to === WhatsAppMode::Production) {
                $evidence = $this->readiness->evidence($locked);

                if ($evidence === null) {
                    throw new ActivationRefusedException(ActivationRefusedException::EVIDENCE_LOST, $checks);
                }
            }

            $old = ['mode' => $locked->mode, 'state' => self::state($locked)];

            // A saving listener can stop the write without an exception: nothing may be claimed then.
            if (! $locked->forceFill(['mode' => $to, 'database_source_activated_at' => now()])->save()) {
                throw new ActivationRefusedException(ActivationRefusedException::DATABASE_ERROR, $checks);
            }

            WhatsAppSettingsAudit::record(WhatsAppAuditAction::Activated, $by, $to->environment(), changes: array_filter([
                'activation_id' => $attempt,
                'mode' => ['old' => $old['mode']->value, 'new' => $to->value],
                'source' => ['old' => $old['state'], 'new' => self::ACTIVE],
                'readiness' => $checks === null ? null : self::checkSnapshot($checks),
                'fingerprint' => $to === WhatsAppMode::Production ? $this->fingerprint->production($locked) : null,
                'evidence_audit_id' => $evidence?->getKey(),
                'reference' => $reference,
            ], fn ($value) => $value !== null), request: $request);
        } catch (Throwable $exception) {
            $this->rollBackTo($connection, $level);

            if ($exception instanceof ActivationRefusedException) {
                throw $exception;
            }

            $this->logDatabaseError($exception);

            throw new ActivationRefusedException(ActivationRefusedException::DATABASE_ERROR, $checks);
        }

        try {
            $this->commit($connection);
        } catch (Throwable $exception) {
            $this->rollBackTo($connection, $level);
            $this->logDatabaseError($exception);

            match ($this->outcome($setting->getKey(), $snapshot, $attempt)) {
                'committed' => null,
                'not_committed' => throw new ActivationRefusedException(ActivationRefusedException::DATABASE_ERROR, $checks),
                default => throw new ActivationOutcomeUnknownException,
            };
        }
    }

    /**
     * Commit the activation transaction. A separate method so a failing commit can be tested.
     */
    protected function commit(Connection $connection): void
    {
        $connection->commit();
    }

    /**
     * After a failed commit: did the activation reach the database?
     */
    protected function outcome(mixed $key, array $snapshot, string $attempt): string
    {
        try {
            $activated = WhatsAppSettingsAudit::query()
                ->where('action', WhatsAppAuditAction::Activated)
                ->latest('id')
                ->limit(20)
                ->get()
                ->contains(fn (WhatsAppSettingsAudit $audit) => ($audit->changes['activation_id'] ?? null) === $attempt);

            if ($activated) {
                return 'committed';
            }

            $row = WhatsAppSetting::query()->whereKey($key)->first();

            return $row !== null && self::storedValues($row) === $snapshot ? 'not_committed' : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function rollBackTo(Connection $connection, int $level): void
    {
        try {
            if ($connection->transactionLevel() > $level) {
                $connection->rollBack($level);
            }
        } catch (Throwable) {
            // The connection may be gone; the database discards an uncommitted transaction.
        }
    }

    /**
     * The exception class and code only: a query exception's message holds the SQL and
     * its bindings, the reference text among them.
     */
    private function logDatabaseError(Throwable $exception): void
    {
        try {
            Log::warning('WhatsApp activation: database error, the activation was not confirmed.', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * The readiness result kept in the audit trail: statuses and non-secret details.
     *
     * @param  array<int, array{status: string, message: string, details: array<string, string>}>  $checks
     * @return array<string, array{status: string, details?: array<string, string>}>
     */
    private static function checkSnapshot(array $checks): array
    {
        $snapshot = [];

        foreach ($checks as $number => $check) {
            $snapshot['condition_'.$number] = array_filter([
                'status' => $check['status'],
                'details' => $check['details'] === [] ? null : $check['details'],
            ], fn ($value) => $value !== null);
        }

        return $snapshot;
    }

    private function recordRefusal(ActivationRefusedException $exception, ?WhatsAppSetting $setting, WhatsAppMode $to, #[SensitiveParameter] ?string $reference, string $attempt, User $by, Request $request): bool
    {
        try {
            WhatsAppSettingsAudit::record(WhatsAppAuditAction::ActivationRefused, $by, $to->environment(), changes: array_filter([
                'activation_id' => $attempt,
                'reason' => $exception->reason,
                'mode' => ['current' => $setting?->mode?->value, 'requested' => $to->value],
                'source' => self::state($setting),
                'readiness' => $exception->checks === null ? null : array_map(fn (array $check) => $check['status'], array_combine(
                    array_map(fn ($number) => 'condition_'.$number, array_keys($exception->checks)),
                    $exception->checks,
                )),
                'reference_provided' => trim((string) $reference) !== '',
            ], fn ($value) => $value !== null), request: $request);

            return true;
        } catch (Throwable $failure) {
            $this->logDatabaseError($failure);

            return false;
        }
    }
}
