<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A restore of .env as the OTP source that was refused before anything was written
 * (REQ-WA-70, AC-WA-52, PH-WA-6.8). Nothing is audited for a refusal.
 */
class EnvRestoreRefusedException extends RuntimeException
{
    /** There is no settings row. */
    public const NOT_MANAGED = 'not_managed';

    /** The row is being prepared: .env is already the OTP source (REQ-WA-65). */
    public const PREPARING = 'preparing';

    /** The .env driver is neither "meta" nor "log". */
    public const DRIVER_NOT_ALLOWED = 'driver_not_allowed';

    /** "log" is only accepted in local and testing (REQ-WA-70). */
    public const LOG_NOT_ALLOWED = 'log_not_allowed';

    /** A "meta" driver without its Phone Number ID or access token. */
    public const ENV_INCOMPLETE = 'env_incomplete';

    /**
     * @param  list<string>  $missing  The .env variable names still empty, never their values.
     */
    public function __construct(public readonly string $reason, public readonly array $missing = [])
    {
        parent::__construct('The restore of .env was refused: '.$reason.'.');
    }
}
