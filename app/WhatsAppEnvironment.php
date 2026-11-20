<?php

namespace App;

/**
 * One of the two independent groups of WhatsApp settings (REQ-WA-01).
 */
enum WhatsAppEnvironment: string
{
    case Test = 'test';
    case Production = 'production';

    /**
     * Name of this environment's column in whatsapp_settings, e.g. "test_phone_number_id".
     */
    public function column(string $field): string
    {
        return $this->value.'_'.$field;
    }

    public function other(): self
    {
        return $this === self::Test ? self::Production : self::Test;
    }
}
