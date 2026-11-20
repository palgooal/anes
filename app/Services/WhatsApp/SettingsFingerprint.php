<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppEnvironment;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fingerprint of the production settings (REQ-WA-68): HMAC-SHA256 with the
 * application key over the Graph API version, Phone Number ID, WABA ID, template
 * name, template language and the full production access token.
 *
 * - The values are the ones a send uses: the Graph API version and the template
 *   language fall back to their documented defaults (REQ-WA-03), exactly as
 *   WhatsAppSettings::forEnvironment() does for the send itself.
 * - The input is a versioned JSON list, so two different sets of values can never
 *   produce the same input by moving characters between fields.
 * - Only the 64-character hex digest is ever stored. It reveals none of the values
 *   (AC-WA-50), and any change to one of them, a rotated access token included,
 *   gives another fingerprint. Rotating the application key does too.
 *
 * It links a send-test evidence to the settings over time. It is not the full
 * snapshot comparison used at activation (REQ-WA-67).
 */
class SettingsFingerprint
{
    private const VERSION = 'anis.whatsapp.production-settings.v1';

    public function production(WhatsAppSetting $setting): string
    {
        $environment = WhatsAppEnvironment::Production;

        $values = [
            self::VERSION,
            $this->filled($setting->api_version) ?? WhatsAppSettings::DEFAULT_API_VERSION,
            $this->filled($setting->field($environment, 'phone_number_id')),
            $this->filled($setting->field($environment, 'waba_id')),
            $this->filled($setting->field($environment, 'template_name')),
            $this->filled($setting->field($environment, 'template_language')) ?? WhatsAppSettings::DEFAULT_TEMPLATE_LANGUAGE,
            $this->filled($setting->field($environment, 'access_token')),
        ];

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->key());
    }

    /**
     * Compare two fingerprints in constant time.
     */
    public static function matches(mixed $stored, string $current): bool
    {
        return is_string($stored) && strlen($stored) === 64 && hash_equals($current, $stored);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('The application key is not set.');
        }

        return Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:'), true) : $key;
    }

    private function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
