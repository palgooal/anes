<?php

namespace App;

use App\Services\WhatsApp\WhatsAppUnavailableException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Outcome of a send request to Meta (docs/ANIS-WHATSAPP-SETTINGS-001.md, section 5).
 *
 * This is only Meta's answer to the request, never proof of delivery.
 */
enum WhatsAppSendStatus: string
{
    /** Meta answered 2xx: the request was accepted, not necessarily delivered. */
    case Accepted = 'accepted';

    /** Meta answered 4xx: the message was refused. */
    case Rejected = 'rejected';

    /** Timeout, lost connection, a 5xx answer or any unexpected error: the message may or may not arrive. */
    case Uncertain = 'uncertain';

    /** Nothing was sent: sending is disabled or the settings are incomplete. */
    case Blocked = 'blocked';

    /**
     * Classify the error raised while sending. Anything not known to be a clean
     * refusal or a block is uncertain (REQ-WA-28).
     */
    public static function fromException(Throwable $exception): self
    {
        return match (true) {
            $exception instanceof WhatsAppUnavailableException => self::Blocked,
            $exception instanceof RequestException => $exception->response->serverError() ? self::Uncertain : self::Rejected,
            default => self::Uncertain,
        };
    }
}
