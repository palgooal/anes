<?php

namespace App;

/**
 * How WhatsApp messages are handled once the settings are managed from the
 * database (docs/ANIS-WHATSAPP-SETTINGS-001.md, section 5).
 */
enum WhatsAppMode: string
{
    /** Nothing is sent. */
    case Disabled = 'disabled';

    /** Codes are written to the application log instead of being sent (development only). */
    case Log = 'log';

    /** Messages go through the test environment settings. */
    case Test = 'test';

    /** Messages go through the production environment settings. */
    case Production = 'production';

    /**
     * The settings environment this mode sends through, if any.
     */
    public function environment(): ?WhatsAppEnvironment
    {
        return match ($this) {
            self::Test => WhatsAppEnvironment::Test,
            self::Production => WhatsAppEnvironment::Production,
            default => null,
        };
    }
}
