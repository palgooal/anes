<?php

namespace Tests\Feature\Notifications\Channels;

use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppChannelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_log_driver_writes_the_code_and_calls_no_api(): void
    {
        Http::preventStrayRequests();
        Log::spy();
        config(['services.whatsapp.driver' => 'log']);
        $patient = User::factory()->patient()->create(['phone' => '966551234567']);

        $patient->notify(new LoginCodeNotification('4821'));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context === ['to' => '966551234567', 'code' => '4821']);
        Http::assertNothingSent();
    }

    public function test_log_driver_never_writes_the_code_in_production(): void
    {
        Log::spy();
        config(['services.whatsapp.driver' => 'log']);
        $this->app->detectEnvironment(fn () => 'production');

        User::factory()->patient()->create(['phone' => '970599123456'])->notify(new LoginCodeNotification('4821'));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context === ['to' => '970599123456', 'code' => '****']);
    }

    public function test_meta_driver_sends_authentication_template_to_the_international_number(): void
    {
        config(['services.whatsapp' => [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => 'test-token',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/1122334455/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $patient = User::factory()->patient()->create(['phone' => '966551234567']);

        $patient->notify(new LoginCodeNotification('4821'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '966551234567'
            && $request['template']['name'] === 'login_code'
            && $request['template']['language']['code'] === 'ar'
            && $request['template']['components'][0]['parameters'][0]['text'] === '4821'
            && $request['template']['components'][1]['parameters'][0]['text'] === '4821');
    }

    public function test_meta_rejection_is_raised_instead_of_silently_ignored(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1122334455', 'services.whatsapp.api_version' => 'v23.0']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/1122334455/messages' => Http::response(['error' => ['message' => 'invalid']], 400)]);

        $this->expectException(RequestException::class);

        User::factory()->patient()->create()->notify(new LoginCodeNotification('4821'));
    }
}
