<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A Graph API call failed. The message is written by MetaGraphClient and never
 * contains a token: the original HTTP error is deliberately not chained, since
 * its message can include the request URL with the token in it (REQ-WA-19).
 *
 * When Meta answered, its HTTP status and its numeric error code are kept, and
 * nothing else of the answer (PH-WA-7: telling a revoked token from a failed check).
 */
class MetaGraphException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly ?int $metaCode = null)
    {
        parent::__construct($message);
    }
}
