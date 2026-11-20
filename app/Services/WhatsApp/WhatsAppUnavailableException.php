<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Sending is not possible with the current settings: disabled, "log" mode in
 * production, or incomplete settings for the active environment (REQ-WA-04,
 * REQ-WA-11, REQ-WA-12). The message never contains a secret.
 */
class WhatsAppUnavailableException extends RuntimeException
{
    public static function for(WhatsAppConfig $config): self
    {
        return new self('WhatsApp sending is blocked ('.$config->blockedReason.'). '.$config->describe());
    }
}
