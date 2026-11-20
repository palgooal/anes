<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Notifications\LoginCodeNotification;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppUnavailableException;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-1 of docs/ANIS-WHATSAPP-SETTINGS-001.md (AC-WA-01, 02, 04, 06, 07, 11).
 * All tokens here are placeholders; no request ever reaches Meta.
 */
class WhatsAppSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ENV_SETTINGS = [
        'driver' => 'meta',
        'number' => '966539564429',
        'phone_number_id' => '999999999999999',
        'access_token' => 'env-token-must-not-be-used',
        'api_version' => 'v99.0',
        'login_template' => 'env_template',
        'template_language' => 'en',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // --- AC-WA-01: no settings row ------------------------------------------

    public function test_without_a_settings_row_the_env_settings_are_used_as_before(): void
    {
        config(['services.whatsapp' => self::ENV_SETTINGS]);

        $config = $this->settings();

        $this->assertSame(WhatsAppConfig::SOURCE_ENV, $config->source);
        $this->assertSame(WhatsAppConfig::DRIVER_META, $config->driver);
        $this->assertSame('999999999999999', $config->phoneNumberId);
        $this->assertSame('env-token-must-not-be-used', $config->accessToken());
        $this->assertSame('v99.0', $config->apiVersion);
        $this->assertSame('env_template', $config->templateName);
        $this->assertSame('en', $config->templateLanguage);
    }

    public function test_any_env_driver_other_than_meta_still_writes_to_the_log(): void
    {
        config(['services.whatsapp.driver' => 'something-else']);

        $this->assertSame(WhatsAppConfig::DRIVER_LOG, $this->settings()->driver);
    }

    public function test_sending_never_creates_the_settings_row(): void
    {
        config(['services.whatsapp.driver' => 'log']);

        User::factory()->patient()->create()->notify(new LoginCodeNotification('123456'));

        $this->assertSame(0, WhatsAppSetting::query()->count());
    }

    public function test_before_the_migration_runs_the_env_settings_are_used(): void
    {
        Schema::drop('whatsapp_settings');
        config(['services.whatsapp' => self::ENV_SETTINGS]);

        $this->assertSame(WhatsAppConfig::SOURCE_ENV, $this->settings()->source);
    }

    // --- AC-WA-02: a settings row is the only source -------------------------

    public function test_with_a_settings_row_nothing_is_read_from_env(): void
    {
        config(['services.whatsapp' => self::ENV_SETTINGS]);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $config = $this->settings();

        $this->assertSame(WhatsAppConfig::SOURCE_DATABASE, $config->source);
        $this->assertSame(WhatsAppEnvironment::Production, $config->environment);
        $this->assertSame('100000000000002', $config->phoneNumberId);
        $this->assertSame('fake-production-token-0002', $config->accessToken());
        $this->assertSame('v23.0', $config->apiVersion);
        $this->assertSame('login_code', $config->templateName);
        $this->assertSame('ar', $config->templateLanguage);
    }

    public function test_empty_api_version_and_language_use_the_fixed_defaults_not_env(): void
    {
        config(['services.whatsapp' => self::ENV_SETTINGS]);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create([
            'api_version' => null,
            'test_template_language' => '',
        ]);

        $config = $this->settings();

        $this->assertSame(WhatsAppSettings::DEFAULT_API_VERSION, $config->apiVersion);
        $this->assertSame('v23.0', $config->apiVersion);
        $this->assertSame(WhatsAppSettings::DEFAULT_TEMPLATE_LANGUAGE, $config->templateLanguage);
        $this->assertSame('ar', $config->templateLanguage);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'phone number id' => ['phone_number_id'],
            'access token' => ['access_token'],
            'template name' => ['template_name'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_missing_required_field_blocks_sending_without_falling_back_to_env(string $field): void
    {
        config(['services.whatsapp' => self::ENV_SETTINGS]);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(['production_'.$field => null]);

        $config = $this->settings();

        $this->assertTrue($config->isBlocked());
        $this->assertSame(WhatsAppConfig::BLOCKED_INCOMPLETE, $config->blockedReason);
        $this->assertNull($config->phoneNumberId);
        $this->assertNull($config->accessToken());
    }

    public function test_the_other_environment_is_never_used_to_fill_gaps(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create(['test_access_token' => null]);

        $this->assertTrue($this->settings()->isBlocked());
    }

    public function test_each_mode_uses_only_its_own_environment(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();

        $this->assertSame('100000000000001', $this->settings()->phoneNumberId);
        $this->assertSame('fake-test-token-0001', $this->settings()->accessToken());

        $setting->update(['mode' => WhatsAppMode::Production]);

        $this->assertSame('100000000000002', $this->settings()->phoneNumberId);
        $this->assertSame('fake-production-token-0002', $this->settings()->accessToken());
        $this->assertSame('100000000000001', $setting->fresh()->test_phone_number_id);
    }

    public function test_disabled_mode_blocks_sending(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();

        $this->assertSame(WhatsAppConfig::BLOCKED_DISABLED, $this->settings()->blockedReason);
    }

    // --- AC-WA-07: log mode -------------------------------------------------

    public function test_log_mode_writes_to_the_log_outside_production(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Log)->create();

        $this->assertTrue($this->settings()->writesToLog());
    }

    public function test_log_mode_is_blocked_in_production(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Log)->create();
        $this->app->detectEnvironment(fn () => 'production');

        $config = $this->settings();

        $this->assertTrue($config->isBlocked());
        $this->assertSame(WhatsAppConfig::BLOCKED_LOG_IN_PRODUCTION, $config->blockedReason);
    }

    public function test_log_mode_in_production_neither_logs_the_code_nor_sends(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Log)->create();
        $this->app->detectEnvironment(fn () => 'production');
        Log::spy();

        try {
            User::factory()->patient()->create()->notify(new LoginCodeNotification('123456'));
            $this->fail('Sending should have been blocked.');
        } catch (WhatsAppUnavailableException) {
        }

        Log::shouldNotHaveReceived('info');
        Http::assertNothingSent();
    }

    // --- Channel wiring (AC-WA-04) ------------------------------------------

    public function test_channel_sends_with_the_database_settings_and_the_same_request_format(): void
    {
        config(['services.whatsapp' => self::ENV_SETTINGS]);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        Http::fake(['graph.facebook.com/v23.0/100000000000002/messages' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $patient = User::factory()->patient()->create(['phone' => '966551234567']);

        $patient->notify(new LoginCodeNotification('012345'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fake-production-token-0002')
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => '966551234567',
                'type' => 'template',
                'template' => [
                    'name' => 'login_code',
                    'language' => ['code' => 'ar'],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '012345']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '012345']]],
                    ],
                ],
            ]);
    }

    public function test_channel_refuses_to_send_when_blocked(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();

        $this->expectException(WhatsAppUnavailableException::class);

        User::factory()->patient()->create()->notify(new LoginCodeNotification('123456'));
    }

    public function test_blocked_sending_shows_the_existing_failure_message_on_login(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $patient = User::factory()->patient()->create();

        $this->from(route('login'))
            ->post(route('login'), ['phone' => $patient->phone])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('send');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_channel_and_command_do_not_read_the_whatsapp_config_directly(): void
    {
        foreach (['app/Notifications/Channels/WhatsAppChannel.php', 'app/Console/Commands/WhatsAppTestCommand.php'] as $file) {
            $this->assertStringNotContainsString("config('services.whatsapp", file_get_contents(base_path($file)), $file);
        }
    }

    // --- AC-WA-06: environments stay separate --------------------------------

    public function test_the_same_phone_number_id_cannot_serve_both_environments(): void
    {
        $this->expectException(DomainException::class);

        WhatsAppSetting::factory()->create([
            'test_phone_number_id' => '123456789012345',
            'production_phone_number_id' => '123456789012345',
        ]);
    }

    public function test_there_is_a_single_settings_row(): void
    {
        WhatsAppSetting::factory()->create();

        $this->expectException(DomainException::class);

        WhatsAppSetting::factory()->create([
            'test_phone_number_id' => '100000000000003',
            'production_phone_number_id' => '100000000000004',
        ]);
    }

    // --- AC-WA-11: secrets ---------------------------------------------------

    public function test_secrets_are_encrypted_at_rest(): void
    {
        WhatsAppSetting::factory()->create([
            'test_app_secret' => 'fake-app-secret',
            'webhook_verify_token' => 'fake-verify-token',
        ]);

        $row = (array) DB::table('whatsapp_settings')->first();

        foreach ([
            'test_access_token' => 'fake-test-token-0001',
            'production_access_token' => 'fake-production-token-0002',
            'test_app_secret' => 'fake-app-secret',
            'webhook_verify_token' => 'fake-verify-token',
        ] as $column => $plain) {
            $this->assertNotSame($plain, $row[$column], $column);
            $this->assertStringNotContainsString($plain, $row[$column], $column);
            $this->assertSame($plain, Crypt::decryptString($row[$column]), $column);
        }
    }

    public function test_secrets_never_appear_in_array_or_json_output(): void
    {
        $setting = WhatsAppSetting::factory()->create([
            'test_app_secret' => 'fake-app-secret',
            'webhook_verify_token' => 'fake-verify-token',
        ]);

        $output = json_encode($setting).json_encode($setting->toArray()).json_encode($setting->fresh());

        foreach (WhatsAppSetting::SECRETS as $column) {
            $this->assertStringNotContainsString($column, $output);
        }
        $this->assertStringNotContainsString('fake-test-token-0001', $output);
        $this->assertStringNotContainsString('fake-production-token-0002', $output);
        $this->assertStringNotContainsString('fake-app-secret', $output);
    }

    public function test_the_resolved_settings_never_expose_the_token(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $config = $this->settings();

        $output = print_r($config, true).json_encode($config).$config->describe();
        ob_start();
        var_dump($config);
        $output .= ob_get_clean();

        $this->assertStringNotContainsString('fake-production-token-0002', $output);
        $this->assertStringContainsString('[hidden]', $output);
    }

    public function test_the_resolved_settings_cannot_be_serialised_or_cached(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->expectException(LogicException::class);

        serialize($this->settings());
    }

    public function test_blocked_errors_never_contain_the_token(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(['production_template_name' => null]);

        try {
            User::factory()->patient()->create()->notify(new LoginCodeNotification('123456'));
            $this->fail('Sending should have been blocked.');
        } catch (WhatsAppUnavailableException $exception) {
            $this->assertStringNotContainsString('fake-production-token-0002', $exception->getMessage());
            $this->assertStringContainsString(WhatsAppConfig::BLOCKED_INCOMPLETE, $exception->getMessage());
        }
    }

    private function settings(): WhatsAppConfig
    {
        return app(WhatsAppSettings::class)->current();
    }
}
