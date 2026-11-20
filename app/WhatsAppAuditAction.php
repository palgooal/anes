<?php

namespace App;

/**
 * Actions recorded in the WhatsApp settings audit trail (REQ-WA-44).
 *
 * Mode changes and production activation are added with PH-WA-6.
 */
enum WhatsAppAuditAction: string
{
    /** The settings row was created: management moved from .env to the panel. */
    case TakeOver = 'take_over';

    /** Non-secret fields were changed. */
    case SettingsUpdated = 'settings_updated';

    /** A secret was saved or replaced (recorded as a flag and its last 4 characters). */
    case SecretReplaced = 'secret_replaced';

    /** An access token was refused by the debug_token check and not saved. */
    case AccessTokenRejected = 'access_token_rejected';

    /** Secrets were re-encrypted with the current application key. */
    case SecretsReencrypted = 'secrets_reencrypted';

    /** A connection test ran on one environment: token, phone number and template (REQ-WA-35). */
    case ConnectionTested = 'connection_tested';

    /** A test message was sent, from the panel or whatsapp:test, whatever Meta answered (REQ-WA-38, REQ-WA-39). */
    case SendTestAttempted = 'send_test_attempted';

    /** A received test code was checked (REQ-WA-36). Meta accepting the request is not delivery. */
    case SendTestVerification = 'send_test_verification';
}
