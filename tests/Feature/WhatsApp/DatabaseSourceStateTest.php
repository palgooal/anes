<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\WhatsAppAuditAction;
use App\WhatsAppMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.1: the OTP source state of the settings row (REQ-WA-02 to REQ-WA-05,
 * REQ-WA-65; AC-WA-01 to AC-WA-03, AC-WA-41, AC-WA-46 in local and testing).
 * Tokens are placeholders; no request ever reaches Meta.
 */
class DatabaseSourceStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENV_MESSAGES = 'graph.facebook.com/v23.0/1122334455/messages';

    /** @var list<string> URLs of every message sent. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-env-token',
            'api_version' => 'v23.0',
            'login_template' => 'env_login_code',
            'template_language' => 'ar',
        ]]);

        AdminTestCodes::generateUsing(fn () => '135790');
        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/messages')) {
                $this->sent[] = $request->url();

                return Http::response(['messages' => [['id' => 'wamid.SRC'.count($this->sent)]]]);
            }

            if (str_ends_with($path, '/debug_token')) {
                return Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]);
            }

            if (str_ends_with($path, '/message_templates')) {
                return Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION']]]);
            }

            return Http::response(['display_phone_number' => '+1 555-000-0001', 'verified_name' => 'ANIS']);
        }]);
    }

    // --- AC-WA-01, AC-WA-02: which settings send OTP codes -------------------------

    public function test_a_row_being_prepared_keeps_otp_codes_on_env(): void
    {
        WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Production)->create();

        $config = app(WhatsAppSettings::class)->current();

        $this->assertSame(WhatsAppConfig::SOURCE_ENV, $config->source);
        $this->assertSame('1122334455', $config->phoneNumberId);
        $this->assertSame('env_login_code', $config->templateName);
        $this->assertSame('fake-env-token', $config->accessToken());
        $this->assertNull($config->mode);
        $this->assertNull($config->environment);

        $this->sendLoginCode();
        $this->assertSame(['https://'.self::ENV_MESSAGES], $this->sent);
    }

    public function test_an_active_row_is_the_only_source(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $config = app(WhatsAppSettings::class)->current();

        $this->assertSame(WhatsAppConfig::SOURCE_DATABASE, $config->source);
        $this->assertSame('100000000000002', $config->phoneNumberId);
        $this->assertSame('login_code', $config->templateName);
        $this->assertSame('fake-production-token-0002', $config->accessToken());
    }

    public function test_the_state_is_read_on_every_send_without_a_cache(): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Production)->create();
        $settings = app(WhatsAppSettings::class);

        $this->assertSame(WhatsAppConfig::SOURCE_ENV, $settings->current()->source);
        $this->sendLoginCode();

        $setting->forceFill(['database_source_activated_at' => now()])->save();
        $this->assertSame(WhatsAppConfig::SOURCE_DATABASE, $settings->current()->source);
        $this->sendLoginCode();

        $setting->forceFill(['database_source_activated_at' => null])->save();
        $this->assertSame(WhatsAppConfig::SOURCE_ENV, $settings->current()->source);
        $this->sendLoginCode();

        $this->assertSame([
            'https://'.self::ENV_MESSAGES,
            'https://graph.facebook.com/v23.0/100000000000002/messages',
            'https://'.self::ENV_MESSAGES,
        ], $this->sent);
    }

    public function test_a_row_being_prepared_in_disabled_mode_does_not_stop_otp_codes(): void
    {
        WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Disabled)->create(['production_access_token' => null]);

        $this->sendLoginCode()->assertSessionHasNoErrors();

        $this->assertSame(['https://'.self::ENV_MESSAGES], $this->sent);
    }

    public function test_the_source_state_cannot_be_set_through_mass_assignment(): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->create();

        $setting->fill(['database_source_activated_at' => now(), 'api_version' => 'v24.0'])->save();

        $this->assertNull($setting->fresh()->database_source_activated_at);
        $this->assertSame('v24.0', $setting->fresh()->api_version);
    }

    // --- AC-WA-03 ("log" branch), AC-WA-46: taking over changes nothing ----------------

    /**
     * @return array<string, array{string}>
     */
    public static function allowedEnvironments(): array
    {
        // REQ-WA-64 is lifted (PH-WA-6.9): every application environment (AC-WA-03, AC-WA-46).
        return ['testing' => ['testing'], 'local' => ['local'], 'production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('allowedEnvironments')]
    public function test_taking_over_creates_a_row_being_prepared_and_otp_codes_stay_on_env(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->sendLoginCode();

        $this->asConfirmedAdmin()
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'قيد التحضير'));

        $setting = WhatsAppSetting::sole();
        $this->assertSame(in_array($environment, ['local', 'testing'], true) ? WhatsAppMode::Log : WhatsAppMode::Disabled, $setting->mode);
        $this->assertFalse($setting->isDatabaseSource());
        $this->assertSame(['old' => null, 'new' => 'preparing'], WhatsAppSettingsAudit::sole()->changes['state']);

        $this->sendLoginCode();

        $this->assertSame(['https://'.self::ENV_MESSAGES, 'https://'.self::ENV_MESSAGES], $this->sent);
    }

    #[DataProvider('allowedEnvironments')]
    public function test_preparing_the_settings_never_moves_otp_codes_off_env(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $admin = User::factory()->admin()->create();
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.take-over'), ['confirm' => '1']);
        $this->assertOtpStillOnEnv(1);

        $this->actingAs($admin)->put(route('staff.whatsapp.general'), ['api_version' => 'v23.0', 'database_source_activated_at' => now()->toDateTimeString()]);
        $this->assertOtpStillOnEnv(2);

        $this->actingAs($admin)->put(route('staff.whatsapp.environment', 'production'), [
            'phone_number_id' => '100000000000002',
            'waba_id' => '200000000000002',
            'display_number' => '966500000002',
            'template_name' => 'login_code',
            'template_language' => 'ar',
        ])->assertSessionHasNoErrors();
        $this->assertOtpStillOnEnv(3);

        $this->asConfirmedAdmin($admin)
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => 'EAAfakeproductiontoken0000000002'])
            ->assertSessionHasNoErrors();
        $this->assertOtpStillOnEnv(4);

        $this->actingAs($admin)->post(route('staff.whatsapp.connection-test', 'production'))->assertSessionHas('connection_test');
        $this->assertOtpStillOnEnv(5);

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test', 'production'), ['phone' => '+966551234567'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => '135790'])->assertSessionHasNoErrors();
        $this->assertOtpStillOnEnv(6, adminTests: 1);

        // The readiness checks change nothing.
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.readiness'))->assertOk();
        $this->assertOtpStillOnEnv(7, adminTests: 1);

        // A failed activation changes nothing: no reference text.
        $this->asConfirmedAdmin($admin)->put(route('staff.whatsapp.activation'), [
            'mode' => 'production',
            'expected_mode' => WhatsAppSetting::sole()->mode->value,
            'expected_state' => 'preparing',
            'acknowledge_impact' => '1',
            'acknowledge_ownership' => '1',
        ])->assertSessionHasErrors('reference', errorBag: 'activation');
        $this->assertOtpStillOnEnv(8, adminTests: 1);

        // A mode switch is refused while the row is being prepared.
        $this->asConfirmedAdmin($admin)->put(route('staff.whatsapp.mode'), ['mode' => 'test', 'expected_mode' => WhatsAppSetting::sole()->mode->value, 'acknowledge_impact' => '1', 'acknowledge_test_recipients' => '1'])
            ->assertSessionHasErrors(errorBag: 'mode');
        $this->assertOtpStillOnEnv(9, adminTests: 1);
        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ModeChangeRefused)->count());

        $this->assertFalse(WhatsAppSetting::sole()->isDatabaseSource());
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', 'like', '%activat%')->count());
        $this->assertContains(WhatsAppAuditAction::SendTestVerification, WhatsAppSettingsAudit::pluck('action')->all());
    }

    // --- AC-WA-41, REQ-WA-65: what the panel shows --------------------------------------

    public function test_a_row_being_prepared_shows_env_as_the_source_and_no_missing_settings_alert(): void
    {
        WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Production)->create(['production_access_token' => null]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('data-source="env"', false)
            ->assertSee('قيد التحضير')
            ->assertSee('السجل قيد التحضير، فلا تؤثر هذه الإعدادات في الإرسال حتى تفعيل صريح.')
            ->assertDontSee('الإرسال متوقف:');
    }

    public function test_an_active_row_shows_the_database_as_the_source(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(['database_source_activated_at' => '2026-11-18 09:00:00']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('data-source="database"', false)
            ->assertSee('مصدر رموز الدخول:')
            ->assertSee('2026-11-18')
            ->assertDontSee('data-source="env"', false);
    }

    /**
     * Send a login code as a guest and check it went through .env.
     */
    private function assertOtpStillOnEnv(int $otpCodes, int $adminTests = 0): void
    {
        $before = count($this->sent);
        $this->sendLoginCode()->assertSessionHasNoErrors();

        $this->assertSame('https://'.self::ENV_MESSAGES, end($this->sent));
        $this->assertSame($before + 1, count($this->sent));
        $this->assertCount($otpCodes + $adminTests, $this->sent);
        $this->assertFalse(WhatsAppSetting::sole()->isDatabaseSource());
    }

    private function sendLoginCode(): TestResponse
    {
        auth()->logout();
        $this->travel(2)->minutes();

        return $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone])
            ->assertSessionHasNoErrors();
    }

    private function asConfirmedAdmin(?User $admin = null): static
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
