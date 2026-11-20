<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppMode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * whatsapp:test reads its settings through WhatsAppSettings (PH-WA-1, REQ-WA-06).
 */
class WhatsAppTestCommandSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_uses_the_database_settings_when_a_row_exists(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '999999999999999', 'services.whatsapp.access_token' => 'env-token-must-not-be-used']);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/100000000000001/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->artisan('whatsapp:test', ['phone' => '+966551234567'])
            ->expectsOutputToContain('Source: database')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fake-test-token-0001')
            && $request['to'] === '966551234567');
    }

    public function test_command_stops_when_sending_is_blocked(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        Http::preventStrayRequests();

        $this->artisan('whatsapp:test', ['phone' => '+966551234567'])
            ->expectsOutputToContain('disabled')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
