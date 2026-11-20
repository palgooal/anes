<?php

namespace App;

/**
 * Why a WhatsApp message was sent (REQ-WA-48).
 */
enum WhatsAppMessagePurpose: string
{
    case Login = 'login';
    case TwoFactor = 'two_factor';

    /** A test message sent by an administrator, never a real login code. */
    case AdminTest = 'admin_test';

    public static function fromCodePurpose(CodePurpose $purpose): self
    {
        return match ($purpose) {
            CodePurpose::Login => self::Login,
            CodePurpose::TwoFactor => self::TwoFactor,
        };
    }
}
