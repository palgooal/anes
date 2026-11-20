<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * The commit of the activation failed and its outcome could not be read back:
 * neither success nor refusal can be claimed. Nothing is written to the audit trail.
 */
class ActivationOutcomeUnknownException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The outcome of the WhatsApp activation is unknown: the commit failed and the state could not be read.');
    }
}
