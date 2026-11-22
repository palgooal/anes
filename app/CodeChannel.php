<?php

namespace App;

/**
 * Where a one-time code is sent (DEC-AUTH-009 in docs/ANIS-AUTH-OTP-001.md).
 *
 * Beneficiary login codes always go to WhatsApp. The staff two-factor code goes to
 * WhatsApp when it can really reach Meta, otherwise to the staff member's email. The
 * channel is chosen once, before the code is issued, and kept for the whole code
 * screen, resends included.
 */
enum CodeChannel: string
{
    case WhatsApp = 'whatsapp';
    case Mail = 'mail';
}
