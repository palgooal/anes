<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppSetting;
use App\WhatsAppMode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * whatsapp:test reads its settings through WhatsAppSettings (REQ-WA-06), from
 * the environment the operator names (REQ-WA-39, PH-WA-5).
 */
class WhatsAppTestCommandSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_uses_the_chosen_environment_from_the_database_whatever_the_mode(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '999999999999999', 'services.whatsapp.access_token' => 'env-token-must-not-be-used']);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/100000000000001/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->artisan('whatsapp:test', ['phone' => '+966551234567', '--environment' => 'test', '--no-interaction' => true])
            ->expectsOutputToContain('Source: database')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fake-test-token-0001')
            && $request['to'] === '966551234567');
        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
    }

    public function test_command_stops_when_the_chosen_environment_is_incomplete(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create(['test_access_token' => null]);
        Http::preventStrayRequests();

        $this->artisan('whatsapp:test', ['phone' => '+966551234567', '--environment' => 'test', '--no-interaction' => true])
            ->expectsOutputToContain('incomplete_settings')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
