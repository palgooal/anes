<?php

namespace App\Services\WhatsApp;

use App\WhatsAppMode;
use RuntimeException;

/**
 * A mode change that was refused before anything was written (REQ-WA-13, PH-WA-6.3).
 */
class ModeChangeRefusedException extends RuntimeException
{
    /** There is no settings row. */
    public const NOT_MANAGED = 'not_managed';

    /** The row is being prepared: .env is still the OTP source (REQ-WA-65). */
    public const PREPARING = 'preparing';

    /** The mode or the source state changed since the change was reviewed. */
    public const STALE = 'stale';

    /**
     * Production is only reached through activation after the readiness checks (REQ-WA-14).
     * Also recorded when production is sent to the switch route and refused by validation.
     */
    public const PRODUCTION = 'production_not_allowed';

    /** "log" is only available in local and testing (REQ-WA-11). */
    public const LOG_NOT_ALLOWED = 'log_not_allowed';

    /** The test environment lacks required fields (REQ-WA-13, REQ-WA-04). */
    public const TEST_INCOMPLETE = 'test_incomplete';

    /** The requested mode is already the current one. */
    public const UNCHANGED = 'unchanged';

    /**
     * Test in the production application environment without the operator's
     * acknowledgement of its impact on sign-in (REQ-WA-69). Server command only.
     */
    public const ACKNOWLEDGEMENT_REQUIRED = 'acknowledgement_required';

    public function __construct(public readonly string $reason, public readonly ?WhatsAppMode $current = null)
    {
        parent::__construct('The WhatsApp mode change was refused: '.$reason.'.');
    }
}
