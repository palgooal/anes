<?php

namespace App\Services\WhatsApp;

use Carbon\CarbonImmutable;

/**
 * What Meta's debug_token reports about an access token (REQ-WA-20, REQ-WA-21).
 * Holds no part of the token itself.
 */
final class TokenInspection
{
    /** Expiry reported by Meta (a date, or 0 for "no expiry date"). */
    public const SOURCE_META = 'meta';

    /** Meta did not report an expiry. */
    public const SOURCE_UNKNOWN = 'unknown';

    public const MESSAGING_SCOPE = 'whatsapp_business_messaging';

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $scopes,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly bool $neverExpires,
        public readonly string $expirySource,
    ) {}

    /**
     * Build from the "data" object of a debug_token answer.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromDebugToken(array $data): self
    {
        $expiresAt = $data['expires_at'] ?? null;
        $known = is_int($expiresAt) || (is_string($expiresAt) && ctype_digit($expiresAt));

        return new self(
            valid: ($data['is_valid'] ?? false) === true,
            scopes: array_values(array_filter((array) ($data['scopes'] ?? []), 'is_string')),
            expiresAt: $known && (int) $expiresAt > 0 ? CarbonImmutable::createFromTimestamp((int) $expiresAt) : null,
            // Meta reports 0 for a token without an expiry date. That is not a guarantee
            // the token stays valid: it is still checked regularly (REQ-WA-21, REQ-WA-22).
            neverExpires: $known && (int) $expiresAt === 0,
            expirySource: $known ? self::SOURCE_META : self::SOURCE_UNKNOWN,
        );
    }

    public function canSendMessages(): bool
    {
        return $this->valid && in_array(self::MESSAGING_SCOPE, $this->scopes, true);
    }
}
