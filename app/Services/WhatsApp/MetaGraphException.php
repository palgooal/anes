<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A Graph API call failed. The message is written by MetaGraphClient and never
 * contains a token: the original HTTP error is deliberately not chained, since
 * its message can include the request URL with the token in it (REQ-WA-19).
 */
class MetaGraphException extends RuntimeException {}
