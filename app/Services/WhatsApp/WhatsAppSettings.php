<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The only place that decides which WhatsApp settings are in effect
 * (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-02 to REQ-WA-07, REQ-WA-11).
 *
 * - No row in whatsapp_settings: config('services.whatsapp') (.env), exactly as before.
 * - A row exists: the database is the only source. Nothing is read from .env or
 *   config('services.whatsapp'). Empty non-sensitive fields fall back to the fixed
 *   defaults below, and to nothing else.
 * - The active environment is missing a required field: sending is blocked.
 *
 * The settings are read on every call and never cached across requests, so a
 * decrypted token is never stored anywhere (REQ-WA-07).
 */
class WhatsAppSettings
{
    /**
     * Fixed defaults for empty non-sensitive fields once the database is the
     * source (REQ-WA-03). No other field has a default.
     */
    public const DEFAULT_API_VERSION = 'v23.0';

    public const DEFAULT_TEMPLATE_LANGUAGE = 'ar';

    /**
     * Fields an environment needs before anything can be sent through it.
     */
    public const REQUIRED_FIELDS = ['phone_number_id', 'access_token', 'template_name'];

    public function current(): WhatsAppConfig
    {
        $setting = $this->storedSetting();

        return $setting ? $this->fromDatabase($setting) : $this->fromEnv();
    }

    /**
     * The settings row, or null when there is none (or the table is not migrated yet).
     */
    private function storedSetting(): ?WhatsAppSetting
    {
        try {
            return WhatsAppSetting::query()->first();
        } catch (QueryException $exception) {
            // Before the migration runs there is simply no row. Any other database
            // error is raised: it must never silently fall back to .env.
            if (! Schema::hasTable('whatsapp_settings')) {
                return null;
            }

            throw $exception;
        }
    }

    private function fromEnv(): WhatsAppConfig
    {
        $config = config('services.whatsapp');

        // Same rule as before: anything other than "meta" writes to the log.
        return new WhatsAppConfig(
            source: WhatsAppConfig::SOURCE_ENV,
            driver: ($config['driver'] ?? null) === 'meta' ? WhatsAppConfig::DRIVER_META : WhatsAppConfig::DRIVER_LOG,
            mode: null,
            environment: null,
            phoneNumberId: $config['phone_number_id'] ?? null,
            accessToken: $config['access_token'] ?? null,
            apiVersion: (string) ($config['api_version'] ?? self::DEFAULT_API_VERSION),
            templateName: (string) ($config['login_template'] ?? ''),
            templateLanguage: (string) ($config['template_language'] ?? self::DEFAULT_TEMPLATE_LANGUAGE),
        );
    }

    private function fromDatabase(WhatsAppSetting $setting): WhatsAppConfig
    {
        $mode = $setting->mode;
        $environment = $mode->environment();
        $apiVersion = $this->filled($setting->api_version) ?? self::DEFAULT_API_VERSION;

        $blocked = fn (string $reason, ?string $template = null, ?string $language = null) => new WhatsAppConfig(
            source: WhatsAppConfig::SOURCE_DATABASE,
            driver: WhatsAppConfig::DRIVER_BLOCKED,
            mode: $mode,
            environment: $environment,
            phoneNumberId: null,
            accessToken: null,
            apiVersion: $apiVersion,
            templateName: $template ?? '',
            templateLanguage: $language ?? self::DEFAULT_TEMPLATE_LANGUAGE,
            blockedReason: $reason,
        );

        if ($mode === WhatsAppMode::Disabled) {
            return $blocked(WhatsAppConfig::BLOCKED_DISABLED);
        }

        if ($mode === WhatsAppMode::Log) {
            if (app()->isProduction()) {
                return $blocked(WhatsAppConfig::BLOCKED_LOG_IN_PRODUCTION);
            }

            return new WhatsAppConfig(
                source: WhatsAppConfig::SOURCE_DATABASE,
                driver: WhatsAppConfig::DRIVER_LOG,
                mode: $mode,
                environment: null,
                phoneNumberId: null,
                accessToken: null,
                apiVersion: $apiVersion,
                templateName: '',
                templateLanguage: self::DEFAULT_TEMPLATE_LANGUAGE,
            );
        }

        $template = $this->filled($setting->field($environment, 'template_name'));
        $language = $this->filled($setting->field($environment, 'template_language')) ?? self::DEFAULT_TEMPLATE_LANGUAGE;

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($this->filled($setting->field($environment, $field)) === null) {
                return $blocked(WhatsAppConfig::BLOCKED_INCOMPLETE, $template, $language);
            }
        }

        return new WhatsAppConfig(
            source: WhatsAppConfig::SOURCE_DATABASE,
            driver: WhatsAppConfig::DRIVER_META,
            mode: $mode,
            environment: $environment,
            phoneNumberId: $setting->field($environment, 'phone_number_id'),
            accessToken: $setting->field($environment, 'access_token'),
            apiVersion: $apiVersion,
            templateName: $template,
            templateLanguage: $language,
        );
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
