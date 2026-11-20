<?php

namespace App\Models;

use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Database\Factories\WhatsAppSettingFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row of WhatsApp settings (docs/ANIS-WHATSAPP-SETTINGS-001.md).
 *
 * Read it through App\Services\WhatsApp\WhatsAppSettings, never directly.
 * Secrets are encrypted at rest and never serialised (REQ-WA-17).
 */
#[Table('whatsapp_settings')]
#[Fillable([
    'mode', 'api_version',
    'test_phone_number_id', 'test_waba_id', 'test_display_number', 'test_access_token',
    'test_template_name', 'test_template_language', 'test_app_secret',
    'production_phone_number_id', 'production_waba_id', 'production_display_number', 'production_access_token',
    'production_template_name', 'production_template_language', 'production_app_secret',
    'webhook_verify_token',
])]
#[Hidden(self::SECRETS)]
class WhatsAppSetting extends Model
{
    /** @use HasFactory<WhatsAppSettingFactory> */
    use HasFactory;

    public const SECRETS = [
        'test_access_token', 'test_app_secret',
        'production_access_token', 'production_app_secret',
        'webhook_verify_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => WhatsAppMode::class,
            'singleton' => 'boolean',
            ...array_fill_keys(self::SECRETS, 'encrypted'),
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            if (static::query()->exists()) {
                throw new DomainException('WhatsApp settings already exist: there is a single settings row.');
            }
        });

        // REQ-WA-10: the same Meta phone number must not serve both environments.
        static::saving(function (self $setting): void {
            $test = $setting->test_phone_number_id;

            if ($test !== null && $test !== '' && $test === $setting->production_phone_number_id) {
                throw new DomainException('The test and production environments cannot use the same Phone Number ID.');
            }
        });
    }

    /**
     * A field of one environment, e.g. field(Production, 'phone_number_id').
     */
    public function field(WhatsAppEnvironment $environment, string $field): mixed
    {
        return $this->getAttribute($environment->column($field));
    }
}
