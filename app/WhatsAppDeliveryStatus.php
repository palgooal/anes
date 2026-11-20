<?php

namespace App;

/**
 * Actual delivery of a message as reported by Meta's webhook (PH-WA-8, REQ-WA-49).
 *
 * Distinct from WhatsAppSendStatus: "accepted" only means Meta took the request.
 */
enum WhatsAppDeliveryStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
