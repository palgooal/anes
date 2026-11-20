<?php

namespace App;

/**
 * Actions recorded in the WhatsApp settings audit trail (REQ-WA-44).
 *
 * Mode changes, production activation and connection or send tests are added
 * with the phases that introduce them (PH-WA-5, PH-WA-6).
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
}
