<?php

namespace App\Services\WhatsApp;

use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use LogicException;
use SensitiveParameter;

/**
 * The WhatsApp settings in effect for one send, resolved by WhatsAppSettings.
 *
 * Immutable. The access token is only reachable through accessToken(); it is
 * masked in var_dump/print_r and JSON, and the object refuses serialisation so
 * it can never land in a cache, a queue payload or a log (REQ-WA-07, REQ-WA-17).
 */
final class WhatsAppConfig implements \JsonSerializable
{
    public const SOURCE_DATABASE = 'database';

    public const SOURCE_ENV = 'env';

    /** Codes are written to the application log, nothing is sent. */
    public const DRIVER_LOG = 'log';

    /** Messages are sent through the Meta Cloud API. */
    public const DRIVER_META = 'meta';

    /** Nothing may be sent: see $blockedReason. */
    public const DRIVER_BLOCKED = 'blocked';

    public const BLOCKED_DISABLED = 'disabled';

    public const BLOCKED_LOG_IN_PRODUCTION = 'log_mode_not_allowed_in_production';

    public const BLOCKED_INCOMPLETE = 'incomplete_settings';

    public function __construct(
        public readonly string $source,
        public readonly string $driver,
        public readonly ?WhatsAppMode $mode,
        public readonly ?WhatsAppEnvironment $environment,
        public readonly ?string $phoneNumberId,
        #[SensitiveParameter] private readonly ?string $accessToken,
        public readonly string $apiVersion,
        public readonly string $templateName,
        public readonly string $templateLanguage,
        public readonly ?string $blockedReason = null,
    ) {}

    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    public function isBlocked(): bool
    {
        return $this->driver === self::DRIVER_BLOCKED;
    }

    public function sendsToMeta(): bool
    {
        return $this->driver === self::DRIVER_META;
    }

    public function writesToLog(): bool
    {
        return $this->driver === self::DRIVER_LOG;
    }

    public function hasAccessToken(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /**
     * A one-line, secret-free description for the console and error reports.
     */
    public function describe(): string
    {
        return 'Source: '.$this->source
            .' · Mode: '.($this->mode?->value ?? $this->driver)
            .($this->environment ? ' · Environment: '.$this->environment->value : '')
            .' · Template: '.$this->templateName.' ('.$this->templateLanguage.')'
            .($this->blockedReason ? ' · Blocked: '.$this->blockedReason : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'source' => $this->source,
            'driver' => $this->driver,
            'mode' => $this->mode?->value,
            'environment' => $this->environment?->value,
            'phoneNumberId' => $this->phoneNumberId,
            'accessToken' => $this->hasAccessToken() ? '[hidden]' : null,
            'apiVersion' => $this->apiVersion,
            'templateName' => $this->templateName,
            'templateLanguage' => $this->templateLanguage,
            'blockedReason' => $this->blockedReason,
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('WhatsApp settings hold secrets and cannot be serialised.');
    }
}
