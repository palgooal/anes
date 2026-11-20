<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Moving WhatsApp management to the panel is refused in this application
 * environment until PH-WA-6 (REQ-WA-64): the row would start in "disabled" mode
 * and nothing could switch it back on, so every OTP would stop.
 */
class TakeOverNotAllowedException extends RuntimeException
{
    public static function inThisEnvironment(): self
    {
        return new self('Moving WhatsApp management to the panel is not available in this environment yet.');
    }
}
