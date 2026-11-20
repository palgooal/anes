<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * An activation that was refused: the settings row is unchanged and no "activated"
 * entry exists (AC-WA-47, REQ-WA-67).
 */
class ActivationRefusedException extends RuntimeException
{
    /** There is no settings row. */
    public const NOT_MANAGED = 'not_managed';

    /** The mode or source state is not the one the administrator reviewed. */
    public const STALE = 'stale';

    /** The requested mode cannot be activated in this application environment (REQ-WA-66, AC-WA-49). */
    public const NOT_ALLOWED = 'not_allowed';

    /** The source is already active with this mode. */
    public const UNCHANGED = 'unchanged';

    /** Once active, only production is reached by activation; other modes are switched (REQ-WA-13, REQ-WA-14). */
    public const ALREADY_ACTIVE = 'already_active';

    /** The test environment lacks required fields (REQ-WA-66). */
    public const TEST_INCOMPLETE = 'test_incomplete';

    /** The reference text of the acknowledgement is missing or not allowed (AC-WA-54). */
    public const REFERENCE_INVALID = 'reference_invalid';

    /** A readiness condition is not met (REQ-WA-24, AC-WA-09). */
    public const READINESS_FAILED = 'readiness_failed';

    /** A stored value changed since the snapshot (REQ-WA-67, AC-WA-48). */
    public const SNAPSHOT_CHANGED = 'snapshot_changed';

    /** The send-test evidence no longer holds under the lock (REQ-WA-67, REQ-WA-68). */
    public const EVIDENCE_LOST = 'evidence_lost';

    /** The database failed before the activation was committed; nothing changed. */
    public const DATABASE_ERROR = 'database_error';

    /** Whether the refusal could be written to the audit trail; null until it was tried. */
    public ?bool $recorded = null;

    /**
     * @param  ?array<int, array{status: string, message: string, details: array<string, string>}>  $checks  The readiness result of this attempt, when it ran.
     */
    public function __construct(public readonly string $reason, public readonly ?array $checks = null)
    {
        parent::__construct('The WhatsApp activation was refused: '.$reason.'.');
    }
}
