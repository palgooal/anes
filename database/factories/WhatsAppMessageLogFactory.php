<?php

namespace Database\Factories;

use App\Models\WhatsAppMessageLog;
use App\WhatsAppEnvironment;
use App\WhatsAppMessagePurpose;
use App\WhatsAppSendStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppMessageLog>
 */
class WhatsAppMessageLogFactory extends Factory
{
    /**
     * An accepted login message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment' => WhatsAppEnvironment::Production,
            'purpose' => WhatsAppMessagePurpose::Login,
            'recipient_hash' => WhatsAppMessageLog::recipientHash('966551234567'),
            'recipient_last4' => '4567',
            'template_name' => 'login_code',
            'template_language' => 'ar',
            'send_status' => WhatsAppSendStatus::Accepted,
            'wamid' => 'wamid.'.fake()->unique()->uuid(),
            'http_status' => 200,
            'duration_ms' => 120,
            'attempted_at' => now(),
        ];
    }
}
