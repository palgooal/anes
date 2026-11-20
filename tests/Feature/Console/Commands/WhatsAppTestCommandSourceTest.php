<?php

namespace Tests\Feature\Console\Commands;

use App\Models\OneTimeCode;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\WhatsAppAuditAction;
use App\WhatsAppMode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.2: which settings whatsapp:test tests, and --legacy-env
 * (REQ-WA-39, AC-WA-45). Tokens are placeholders; no request reaches Meta.
 */
class WhatsAppTestCommandSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '+966551234567';

    private const ENV_URL = 'https://graph.facebook.com/v23.0/1122334455/messages';

    private const TEST_URL = 'https://graph.facebook.com/v23.0/100000000000001/messages';

    private const PRODUCTION_URL = 'https://graph.facebook.com/v23.0/100000000000002/messages';

    /** @var list<string> */
    private array $sent = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-env-token',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        AdminTestCodes::generateUsing(fn () => '246810');
        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*/messages' => function (Request $request) {
            $this->sent[] = $request->url();

            return Http::response(['messages' => [['id' => 'wamid.SRC'.count($this->sent)]]]);
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- Which settings are tested ---------------------------------------------------

    public function test_without_a_row_env_is_tested(): void
    {
        $this->runCommand(['--confirm-production' => true])->expectsOutputToContain('Quota: legacy-env')->assertSuccessful();

        $this->assertSame([self::ENV_URL], $this->sent);
    }

    public function test_without_a_row_an_environment_cannot_be_tested(): void
    {
        $this->runCommand(['--environment' => 'test'])
            ->expectsOutputToContain('لا توجد إعدادات محفوظة في اللوحة')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    public function test_while_preparing_env_is_tested_by_default(): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Production)->create();
        $before = $setting->fresh()->getAttributes();

        $this->runCommand(['--confirm-production' => true])->expectsOutputToContain('Quota: legacy-env')->assertSuccessful();

        $this->assertSame([self::ENV_URL], $this->sent);
        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);
    }

    public function test_while_preparing_a_named_environment_is_tested(): void
    {
        WhatsAppSetting::factory()->preparing()->create();

        $this->runCommand(['--environment' => 'test'])->expectsOutputToContain('Quota: test')->assertSuccessful();
        $this->runCommand(['--environment' => 'production'])->expectsOutputToContain('--confirm-production')->assertFailed();
        $this->runCommand(['--environment' => 'production', '--confirm-production' => true])->expectsOutputToContain('Quota: production')->assertSuccessful();

        $this->assertSame([self::TEST_URL, self::PRODUCTION_URL], $this->sent);
        $this->assertFalse(WhatsAppSetting::sole()->isDatabaseSource());
    }

    public function test_once_the_database_is_the_source_an_environment_or_legacy_env_is_required(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->runCommand(['--confirm-production' => true])
            ->expectsOutputToContain('--legacy-env')
            ->assertFailed();
        $this->assertNothingHappened();

        $this->runCommand(['--environment' => 'test'])->assertSuccessful();
        $this->assertSame([self::TEST_URL], $this->sent);
    }

    public function test_an_unknown_environment_is_refused(): void
    {
        WhatsAppSetting::factory()->create();

        $this->runCommand(['--environment' => 'prod', '--confirm-production' => true])
            ->expectsOutputToContain('قيمة --environment غير معروفة')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    // --- --legacy-env ------------------------------------------------------------------

    /**
     * @return array<string, array{?string}>
     */
    public static function rowStates(): array
    {
        return ['no row' => [null], 'preparing' => ['preparing'], 'active' => ['active']];
    }

    #[DataProvider('rowStates')]
    public function test_legacy_env_tests_env_in_every_state_and_changes_nothing(?string $state): void
    {
        $setting = match ($state) {
            null => null,
            'preparing' => WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Production)->create(),
            'active' => WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(),
        };
        $before = $setting?->fresh()->getAttributes();
        $sourceBefore = app(WhatsAppSettings::class)->current()->source;

        $this->runCommand(['--legacy-env' => true, '--confirm-production' => true])
            ->expectsOutputToContain('Quota: legacy-env')
            ->assertSuccessful();

        $this->assertSame([self::ENV_URL], $this->sent);
        $this->assertSame($before, $setting?->fresh()->getAttributes());
        $this->assertSame($sourceBefore, app(WhatsAppSettings::class)->current()->source);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SendTestAttempted, $audit->action);
        $this->assertNull($audit->environment);
        $this->assertNull($audit->user_id);
        $this->assertSame(['origin' => 'console', 'quota' => 'legacy-env', 'source' => 'env', 'recipient_last4' => '4567', 'send_status' => 'accepted'], $audit->changes);
        $this->assertNull(WhatsAppMessageLog::sole()->environment);
        $this->assertSame(0, OneTimeCode::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function appEnvironments(): array
    {
        return ['testing' => ['testing'], 'local' => ['local'], 'production' => ['production']];
    }

    #[DataProvider('appEnvironments')]
    public function test_legacy_env_always_needs_confirm_production(string $appEnvironment): void
    {
        $this->app->detectEnvironment(fn () => $appEnvironment);
        WhatsAppSetting::factory()->create();

        $this->runCommand(['--legacy-env' => true])
            ->expectsOutputToContain('لا يعني أن الرقم تابع للإنتاج')
            ->assertFailed();

        $this->assertNothingHappened();
    }

    public function test_legacy_env_and_environment_together_are_refused_without_counting(): void
    {
        WhatsAppSetting::factory()->create();

        foreach (['test', 'production'] as $environment) {
            $this->runCommand(['--legacy-env' => true, '--environment' => $environment, '--confirm-production' => true])
                ->expectsOutputToContain('لا يجتمع --legacy-env مع --environment')
                ->assertFailed();
        }

        $this->assertNothingHappened();

        // The refusals were not counted: the legacy-env quota still has its 5 sends.
        for ($i = 0; $i < 5; $i++) {
            $this->runCommand(['--legacy-env' => true, '--confirm-production' => true])->assertSuccessful();
        }

        $this->runCommand(['--legacy-env' => true, '--confirm-production' => true])->expectsOutputToContain('حصة legacy-env')->assertFailed();
        $this->assertCount(5, $this->sent);
    }

    public function test_the_three_quotas_stay_separate_once_the_database_is_the_source(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        for ($i = 0; $i < 5; $i++) {
            $this->runCommand(['--legacy-env' => true, '--confirm-production' => true])->assertSuccessful();
        }

        $this->runCommand(['--legacy-env' => true, '--confirm-production' => true])->assertFailed();

        for ($i = 0; $i < 5; $i++) {
            $this->runCommand(['--environment' => 'test'])->assertSuccessful();
            $this->runCommand(['--environment' => 'production', '--confirm-production' => true])->assertSuccessful();
        }

        $this->runCommand(['--environment' => 'test'])->assertFailed();
        $this->runCommand(['--environment' => 'production', '--confirm-production' => true])->assertFailed();

        $this->assertSame(5, count(array_keys($this->sent, self::ENV_URL)));
        $this->assertSame(5, count(array_keys($this->sent, self::TEST_URL)));
        $this->assertSame(5, count(array_keys($this->sent, self::PRODUCTION_URL)));
        $this->assertTrue(WhatsAppSetting::sole()->isDatabaseSource());
        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);
    }

    public function test_a_confirmed_legacy_env_test_is_audited_without_an_environment(): void
    {
        WhatsAppSetting::factory()->create();

        $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--legacy-env' => true, '--confirm-production' => true])
            ->expectsQuestion('اكتب الرمز الذي وصلك على واتساب (لديك 5 دقائق)', '246810')
            ->expectsOutputToContain('تأكد استلام رمز الاختبار')
            ->assertSuccessful();

        $verification = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestVerification)->sole();
        $this->assertNull($verification->environment);
        $this->assertNull($verification->user_id);
        $this->assertSame(['origin' => 'console', 'quota' => 'legacy-env', 'recipient_last4' => '4567', 'outcome' => 'verified'], $verification->changes);
    }

    // --- AC-WA-22: nothing sensitive in the terminal, the logs or the trails -------------

    public function test_the_terminal_and_trails_never_show_the_code_token_or_full_number(): void
    {
        WhatsAppSetting::factory()->create();

        $this->assertSame(0, Artisan::call('whatsapp:test', ['phone' => self::PHONE, '--legacy-env' => true, '--confirm-production' => true, '--no-interaction' => true]));
        $terminal = Artisan::output();
        $trails = json_encode([WhatsAppSettingsAudit::all()->toArray(), WhatsAppMessageLog::all()->toArray(), $this->logged]);

        $this->assertStringContainsString('••••4567', $terminal);

        foreach (['246810', '966551234567', '55 123 4567', 'fake-env-token', 'fake-test-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $terminal, $secret);
            $this->assertStringNotContainsString($secret, $trails, $secret);
        }
    }

    private function runCommand(array $options): PendingCommand
    {
        return $this->artisan('whatsapp:test', ['phone' => self::PHONE, '--no-interaction' => true] + $options);
    }

    private function assertNothingHappened(): void
    {
        Http::assertNothingSent();
        $this->assertSame([], $this->sent);
        $this->assertSame(0, WhatsAppMessageLog::count());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }
}
