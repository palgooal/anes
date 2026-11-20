<?php

namespace App\Notifications;

use App\Services\WhatsApp\WhatsAppConfig;
use App\WhatsAppMessagePurpose;
use SensitiveParameter;

/**
 * An administrator's test code (REQ-WA-36, REQ-WA-37, REQ-WA-39).
 *
 * Separate from login codes: never stored in one_time_codes, logged with the
 * "admin_test" purpose, and sent with the settings of the environment the
 * administrator chose, whatever the mode (REQ-WA-12).
 */
final class AdminTestCodeNotification extends WhatsAppCodeNotification
{
    public function __construct(#[SensitiveParameter] string $code, private WhatsAppConfig $settings)
    {
        parent::__construct($code, null, WhatsAppMessagePurpose::AdminTest);
    }

    public function config(): WhatsAppConfig
    {
        return $this->settings;
    }
}
