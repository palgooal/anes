<?php

namespace Tests\Feature\Console\Commands;

use App\Models\OneTimeCode;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Notifications\AdminTestCodeNotification;
use App\Notifications\LoginCodeNotification;
use App\Services\WhatsApp\AdminTestCodes;
use App\WhatsAppAuditAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * whatsapp:test (REQ-WA-39, AC-WA-22, AC-WA-45). Tokens are placeholders; no
 * request ever reaches Meta.
 */
class WhatsAppTestCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '+966551234567';

    private const TEST_URL = 'graph.facebook.com/v23.0/100000000000001/messages';

    private const PRODUCTION_URL = 'graph.facebook.com/v23.0/100000000000002/messages';

    private const ENV_URL = 'graph.facebook.com/v23.0/1122334455/messages';

    /** What the fake Meta answers: 'accept', 'reject', 'server-error' or 'timeout'. */
    private string $meta = 'accept';

    /** @var list<string> Codes the fake Meta received. */
    private array $sentCodes = [];

    /** @var list<string> Everything written to the application log. */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*/messages' => function (Request $request) {
            $this->sentCodes[] = $request['template']['components'][0]['parameters'][0]['text'];

            return match ($this->meta) {
                'accept' => Http::response(['messages' => [['id' => 'wamid.CMD'.count($this->sentCodes)]]]),
                'reject' => Http::response(['error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001]], 404),
                'server-error' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2]], 503),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- Basics -------------------------------------------------------------------

    public function test_rejects_an_invalid_number(): void
    {
        $this->artisan('whatsapp:test', ['phone' => '123'])->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessageLog::count());
    }

    public function test_sends_a_test_code_not_a_login_code(): void
    {
        WhatsAppSetting::factory()->create();
        Notification::fake();

        $this->artisan('whatsapp:test', ['phone' => '0551234567', '--country' => '966', '--environment' => 'test', '--no-interaction' => true])->assertSuccessful();

        Notification::assertSentTo(new AnonymousNotifiable, AdminTestCodeNotification::class,
            fn (AdminTestCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['whatsapp'] === '966551234567'
                && preg_match('/^\d{6}$/', $notification->code) === 1
                && $notification->config()->environment?->value === 'test');
        Notification::assertNotSentTo(new AnonymousNotifiable, LoginCodeNotification::class);
        $this->assertSame(0, OneTimeCode::count());
    }

    // --- Environment and confirmation ---------------------------------------------------

    public function test_the_environment_is_required_once_the_settings_are_managed_from_the_panel(): void
    {
        WhatsAppSetting::factory()->create();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--no-interaction' => true])
            ->expectsOutputToContain('--environment=test')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    public function test_production_needs_confirm_production_and_nothing_counts_without_it(): void
    {
        WhatsAppSetting::factory()->create();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'production', '--no-interaction' => true])
            ->expectsOutputToContain('--confirm-production')
            ->assertFailed();

        $this->assertNothingHappened();

        // The refusal was not counted: the 5 sends of the hour are all still available.
        for ($i = 0; $i < 5; $i++) {
            $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'production', '--confirm-production' => true, '--no-interaction' => true])->assertSuccessful();
        }

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), self::PRODUCTION_URL));
    }

    public function test_env_settings_need_confirm_production_without_assuming_an_environment(): void
    {
        config(['services.whatsapp' => ['driver' => 'meta', 'phone_number_id' => '1122334455', 'access_token' => 'fake-env-token', 'api_version' => 'v23.0', 'login_template' => 'login_code', 'template_language' => 'ar']]);

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--no-interaction' => true])
            ->expectsOutputToContain('لا يعني أن الرقم تابع للإنتاج')
            ->assertFailed();
        $this->assertNothingHappened();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--confirm-production' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Quota: legacy-env')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), self::ENV_URL));
        $this->assertNull(WhatsAppMessageLog::sole()->environment);
        $this->assertNull(WhatsAppSettingsAudit::sole()->environment);
        $this->assertSame('legacy-env', WhatsAppSettingsAudit::sole()->changes['quota']);
    }

    public function test_env_log_driver_cannot_test_and_logs_no_code(): void
    {
        config(['services.whatsapp.driver' => 'log']);

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--confirm-production' => true, '--no-interaction' => true])
            ->expectsOutputToContain('log')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    public function test_meta_driver_needs_credentials(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => null, 'services.whatsapp.access_token' => null]);

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--confirm-production' => true, '--no-interaction' => true])
            ->expectsOutputToContain('WHATSAPP_PHONE_NUMBER_ID')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    // --- Limits: 5 an hour per quota -------------------------------------------------------

    public function test_the_sixth_send_of_the_hour_is_refused_and_quotas_are_separate(): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta = 'reject';

        // Every send that starts counts, whatever Meta answers.
        for ($i = 0; $i < 5; $i++) {
            $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])->assertFailed();
        }

        Http::assertSentCount(5);

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])
            ->expectsOutputToContain('حصة test')
            ->assertFailed();

        Http::assertSentCount(5);
        $this->assertSame(5, WhatsAppMessageLog::count());
        $this->assertSame(5, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestAttempted)->count());

        // The production quota is untouched.
        $this->meta = 'accept';
        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'production', '--confirm-production' => true, '--no-interaction' => true])->assertSuccessful();
        Http::assertSentCount(6);

        // And so is legacy-env once the panel no longer manages the settings.
        WhatsAppSetting::query()->delete();
        config(['services.whatsapp' => ['driver' => 'meta', 'phone_number_id' => '1122334455', 'access_token' => 'fake-env-token', 'api_version' => 'v23.0', 'login_template' => 'login_code', 'template_language' => 'ar']]);
        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--confirm-production' => true, '--no-interaction' => true])->assertSuccessful();
        Http::assertSentCount(7);
    }

    public function test_the_limit_resets_after_an_hour(): void
    {
        WhatsAppSetting::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])->assertSuccessful();
        }

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])->assertFailed();

        $this->travel(61)->minutes();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])->assertSuccessful();
        Http::assertSentCount(6);
    }

    // --- Results: accepted is not delivered ------------------------------------------------

    public function test_a_non_interactive_run_never_confirms_receipt(): void
    {
        WhatsAppSetting::factory()->create();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])
            ->expectsOutputToContain('هذا لا يعني وصول الرسالة')
            ->expectsOutputToContain('لم يُتحقق من استلام الرمز')
            ->assertSuccessful();

        $this->assertSame(['send_test_attempted'], WhatsAppSettingsAudit::pluck('action')->map->value->all());
    }

    public function test_typing_the_received_code_confirms_receipt(): void
    {
        WhatsAppSetting::factory()->create();
        AdminTestCodes::generateUsing(fn () => '246810');

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test'])
            ->expectsQuestion('اكتب الرمز الذي وصلك على واتساب (لديك 5 دقائق)', '246810')
            ->expectsOutputToContain('تأكد استلام رمز الاختبار')
            ->assertSuccessful();

        $this->assertSame(['246810'], $this->sentCodes);
        $verification = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestVerification)->sole();
        $this->assertSame('verified', $verification->changes['outcome']);
        $this->assertNull($verification->user_id);
    }

    public function test_five_wrong_codes_cancel_the_test(): void
    {
        WhatsAppSetting::factory()->create();

        $command = $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test']);

        for ($i = 0; $i < 5; $i++) {
            $command->expectsQuestion('اكتب الرمز الذي وصلك على واتساب (لديك 5 دقائق)', 'not-it');
        }

        $command->expectsOutputToContain('استُنفدت محاولات التحقق')->assertFailed();
    }

    public function test_a_meta_refusal_is_shown_without_the_message_body(): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta = 'reject';

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true])
            ->expectsOutputToContain('رفضت Meta الطلب (رمز الخطأ 132001)')
            ->assertFailed();

        $this->assertSame('rejected', WhatsAppSettingsAudit::sole()->changes['send_status']);
        $this->assertSame('132001', WhatsAppSettingsAudit::sole()->changes['error_code']);
    }

    public function test_an_uncertain_result_cancels_the_code(): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta = 'timeout';

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test'])
            ->expectsOutputToContain('لم تتأكد نتيجة الطلب')
            ->assertFailed();

        $this->assertSame('uncertain', WhatsAppSettingsAudit::sole()->changes['send_status']);
        $this->assertSame('uncertain', WhatsAppMessageLog::sole()->send_status->value);
    }

    // --- AC-WA-22: the code is never shown or logged --------------------------------------

    public function test_the_code_and_the_full_number_never_reach_the_terminal_logs_or_trails(): void
    {
        WhatsAppSetting::factory()->create();

        $this->assertSame(0, Artisan::call('whatsapp:test', ['phone' => self::PHONE, '--environment' => 'test', '--no-interaction' => true]));
        $terminal = Artisan::output();

        $code = $this->sentCodes[0];
        $trail = json_encode([WhatsAppSettingsAudit::all()->toArray(), WhatsAppMessageLog::all()->toArray(), $this->logged]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertStringNotContainsString($code, $terminal);
        $this->assertStringNotContainsString($code, $trail);
        $this->assertStringNotContainsString('966551234567', $trail);
        $this->assertStringContainsString('4567', $trail);
        $this->assertStringNotContainsString('fake-test-token', $trail.$terminal);
    }

    private function assertNothingHappened(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessageLog::count());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }
}
