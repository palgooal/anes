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
    'webhook_verify_token', 'secret_metadata',
    'test_token_expires_at', 'test_token_never_expires', 'test_token_expiry_source', 'test_token_checked_at',
    'production_token_expires_at', 'production_token_never_expires', 'production_token_expiry_source', 'production_token_checked_at',
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
            'secret_metadata' => 'array',
            'test_token_expires_at' => 'datetime',
            'test_token_never_expires' => 'boolean',
            'test_token_checked_at' => 'datetime',
            'production_token_expires_at' => 'datetime',
            'production_token_never_expires' => 'boolean',
            'production_token_checked_at' => 'datetime',
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

    /**
     * What may be shown about a secret instead of its value (REQ-WA-18):
     * whether it is saved, its last 4 characters, when and by whom it was last set.
     *
     * @return array{saved: bool, last4: ?string, updated_at: ?string, updated_by: ?int}
     */
    public function secretInfo(string $column): array
    {
        $value = $this->getAttribute($column);
        $meta = $this->secret_metadata[$column] ?? [];

        return [
            'saved' => is_string($value) && $value !== '',
            'last4' => $meta['last4'] ?? null,
            'updated_at' => $meta['updated_at'] ?? null,
            'updated_by' => $meta['updated_by'] ?? null,
        ];
    }

    /**
     * Store a secret (encrypted by its cast) and the metadata shown in its place.
     */
    public function setSecret(string $column, string $value, ?User $by): void
    {
        $this->setAttribute($column, $value);

        $metadata = $this->secret_metadata ?? [];
        $metadata[$column] = [
            'last4' => self::lastFour($value),
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $by?->getKey(),
        ];
        $this->secret_metadata = $metadata;
    }

    public static function lastFour(string $value): string
    {
        return mb_substr($value, -4);
    }
}
