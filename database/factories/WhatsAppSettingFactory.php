<?php

namespace Database\Factories;

use App\Models\WhatsAppSetting;
use App\WhatsAppMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fake settings for tests. The tokens are placeholders, never real Meta tokens.
 *
 * @extends Factory<WhatsAppSetting>
 */
class WhatsAppSettingFactory extends Factory
{
    /**
     * Both environments complete, sending disabled, and the database already the
     * OTP source, as every row was before PH-WA-6. Use preparing() for a row that
     * is still being prepared (REQ-WA-65).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mode' => WhatsAppMode::Disabled,
            'database_source_activated_at' => now(),
            'api_version' => 'v23.0',
            'test_phone_number_id' => '100000000000001',
            'test_waba_id' => '200000000000001',
            'test_display_number' => '15550000001',
            'test_access_token' => 'fake-test-token-0001',
            'test_template_name' => 'login_code',
            'test_template_language' => 'ar',
            'production_phone_number_id' => '100000000000002',
            'production_waba_id' => '200000000000002',
            'production_display_number' => '966500000002',
            'production_access_token' => 'fake-production-token-0002',
            'production_template_name' => 'login_code',
            'production_template_language' => 'ar',
        ];
    }

    public function mode(WhatsAppMode $mode): static
    {
        return $this->state(fn (array $attributes) => ['mode' => $mode]);
    }

    /**
     * Being prepared: OTP codes are still sent with .env (REQ-WA-65).
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => ['database_source_activated_at' => null]);
    }
}
