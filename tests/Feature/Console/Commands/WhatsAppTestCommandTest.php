<?php

namespace Tests\Feature\Console\Commands;

use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WhatsAppTestCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sends_a_code_to_the_given_number(): void
    {
        config(['auth.phone_countries' => ['966', '970']]);
        Notification::fake();

        $this->artisan('whatsapp:test', ['phone' => '0599123456', '--country' => '970'])->assertSuccessful();

        Notification::assertSentTo(new AnonymousNotifiable, LoginCodeNotification::class,
            fn (LoginCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['whatsapp'] === '970599123456'
                && preg_match('/^\d{6}$/', $notification->code) === 1);
    }

    public function test_rejects_an_invalid_number(): void
    {
        Notification::fake();

        $this->artisan('whatsapp:test', ['phone' => '123'])->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_meta_driver_needs_credentials(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => null, 'services.whatsapp.access_token' => null]);
        Notification::fake();

        $this->artisan('whatsapp:test', ['phone' => '+966551234567'])
            ->expectsOutputToContain('WHATSAPP_PHONE_NUMBER_ID')
            ->assertFailed();
    }

    public function test_shows_the_reason_meta_rejected_the_message(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1122334455', 'services.whatsapp.access_token' => 't', 'services.whatsapp.api_version' => 'v23.0']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/1122334455/messages' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 404)]);

        $this->artisan('whatsapp:test', ['phone' => '+966551234567'])
            ->expectsOutputToContain('Template name does not exist in the translation')
            ->assertFailed();

        Http::assertSent(fn (Request $request) => $request['to'] === '966551234567');
    }
}
