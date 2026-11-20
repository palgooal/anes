<?php

namespace App;

/**
 * Actions recorded in the WhatsApp settings audit trail (REQ-WA-44).
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

    /**
     * The mode was switched (REQ-WA-13, REQ-WA-44): only the mode changed. From the
     * panel by an administrator, or from the server by whatsapp:mode (REQ-WA-69),
     * then without a user and with "via" set to "server".
     */
    case ModeChanged = 'mode_changed';

    /** A mode switch was refused before anything was written, with the reason (REQ-WA-44). */
    case ModeChangeRefused = 'mode_change_refused';

    /**
     * A change to the settings was refused before anything was written, with the reason
     * and the field names only (REQ-WA-15, REQ-WA-44): the production identifiers are
     * frozen while production mode is in force.
     */
    case SettingsChangeRefused = 'settings_change_refused';

    /**
     * The OTP source was activated (REQ-WA-66, REQ-WA-26): the source state and the
     * mode, with the readiness result, the settings fingerprint and the reference
     * text of the acknowledgement for production. Written in the activation transaction.
     */
    case Activated = 'activated';

    /**
     * An activation was refused and nothing changed (AC-WA-47, REQ-WA-67): the reason
     * and the condition statuses only, never the reference text. Written after the
     * activation transaction was rolled back.
     */
    case ActivationRefused = 'activation_refused';

    /**
     * .env was restored as the OTP source from the server by whatsapp:restore-env
     * (REQ-WA-70, REQ-WA-44): the row is being prepared again, its mode and secrets
     * unchanged. Recorded without a user, with the .env driver and "via" set to "server".
     */
    case EnvRestored = 'env_restored';

    /** A connection test ran on one environment: token, phone number and template (REQ-WA-35). */
    case ConnectionTested = 'connection_tested';

    /** A test message was sent, from the panel or whatsapp:test, whatever Meta answered (REQ-WA-38, REQ-WA-39). */
    case SendTestAttempted = 'send_test_attempted';

    /** A received test code was checked (REQ-WA-36). Meta accepting the request is not delivery. */
    case SendTestVerification = 'send_test_verification';
}
