<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\ModeChangeRefusedException;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-6.8: whatsapp:mode (REQ-WA-69, REQ-WA-71, AC-WA-51, AC-WA-53). Every refusal
 * writes nothing at all, not even an audit entry (decision 1). Tokens are
 * placeholders; no request ever reaches Meta.
 */
class WhatsAppModeCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REFERENCE = 'ملف التحقق من الملكية NO-2026-117';

    /** @var list<string> Paths of every request to the Graph API. */
    private array $requests = [];

    /** @var list<string> Every log line, with its context. */
    private array $logged = [];

    private ?Closure $duringMeta = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-env-token-9911',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->requests[] = $path;

            if ($this->duringMeta) {
                [$callback, $this->duringMeta] = [$this->duringMeta, null];
                $callback();
            }

            return match (true) {
                str_ends_with($path, '/messages') => Http::response(['messages' => [['id' => 'wamid.MODE'.count($this->requests)]]]),
                str_ends_with($path, '/debug_token') => Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]),
                str_ends_with($path, '/message_templates') => Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION', 'components' => []]]]),
                default => Http::response(['display_phone_number' => '+966 50 000 0002']),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
    }

    // --- Allowed switches ----------------------------------------------------------------

    /**
     * @return array<string, array{WhatsAppMode}>
     */
    public static function fromModes(): array
    {
        return ['test' => [WhatsAppMode::Test], 'production' => [WhatsAppMode::Production], 'log' => [WhatsAppMode::Log]];
    }

    #[DataProvider('fromModes')]
    public function test_disabled_is_reached_from_any_mode_and_only_the_mode_changes(WhatsAppMode $from): void
    {
        $setting = $this->active($from);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        [$status, $output] = $this->runMode('disabled', ['--confirm' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('بيئة التطبيق: testing', $output);
        $this->assertStringContainsString('سيتوقف إرسال كل رموز الدخول والتحقق الثنائي', $output);

        $after = WhatsAppActivation::storedValues($setting->fresh());
        $this->assertSame('disabled', $after['mode']);
        unset($before['mode'], $after['mode']);
        $this->assertSame($before, $after);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::ModeChanged, $audit->action);
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->environment);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertSame(['mode' => ['old' => $from->value, 'new' => 'disabled'], 'via' => 'server'], $audit->changes);
        $this->assertSame([], $this->requests);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonProductionEnvironments(): array
    {
        return ['testing' => ['testing'], 'local' => ['local'], 'staging' => ['staging']];
    }

    #[DataProvider('nonProductionEnvironments')]
    public function test_test_is_reached_without_the_acknowledgement_outside_production(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $setting = $this->active(WhatsAppMode::Disabled);

        [$status, $output] = $this->runMode('test', ['--confirm' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('بيئة التطبيق: '.$environment, $output);
        $this->assertStringContainsString('لا تصل إلا إلى المستلمين المسموحين فيها', $output);
        $this->assertSame(WhatsAppMode::Test, $setting->fresh()->mode);
        $this->assertSame(WhatsAppEnvironment::Test, WhatsAppSettingsAudit::sole()->environment);
        $this->assertSame(['mode' => ['old' => 'disabled', 'new' => 'test'], 'via' => 'server'], WhatsAppSettingsAudit::sole()->changes);
    }

    public function test_test_in_production_needs_the_acknowledgement(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $setting = $this->active(WhatsAppMode::Production);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        [$status, $output] = $this->runMode('test', ['--confirm' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('--acknowledge-login-impact', $output);
        $this->assertImpactBefore('--acknowledge-login-impact', $output);
        $this->assertNothingWritten($setting, $before);

        [$status, $output] = $this->runMode('test', ['--confirm' => true, '--acknowledge-login-impact' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('بيئة التطبيق: production', $output);
        $this->assertSame(WhatsAppMode::Test, $setting->fresh()->mode);
        $this->assertSame(['mode' => ['old' => 'production', 'new' => 'test'], 'via' => 'server'], WhatsAppSettingsAudit::sole()->changes);
    }

    public function test_disabled_in_production_needs_no_acknowledgement(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $setting = $this->active(WhatsAppMode::Production);

        [$status, $output] = $this->runMode('disabled', ['--confirm' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertSame(WhatsAppMode::Disabled, $setting->fresh()->mode);
    }

    // --- Refusals: nothing is written, not even an audit entry (AC-WA-51, decision 1) ------------

    /**
     * @return array<string, array{string, array<string, mixed>, Closure, string}>
     */
    public static function refusals(): array
    {
        $active = fn (WhatsAppMode $mode) => fn () => WhatsAppSetting::factory()->mode($mode)->create();

        return [
            '--env' => ['disabled', ['--confirm' => true, '--env' => 'testing'], $active(WhatsAppMode::Test), 'لا يُقبل الخيار --env'],
            '--env without a value' => ['disabled', ['--confirm' => true, '--env' => null], $active(WhatsAppMode::Test), 'لا يُقبل الخيار --env'],
            'no --confirm' => ['disabled', [], $active(WhatsAppMode::Test), 'أضف --confirm'],
            'no settings row' => ['disabled', ['--confirm' => true], fn () => null, 'لا توجد إعدادات واتساب'],
            'a row being prepared' => ['disabled', ['--confirm' => true], fn () => WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Test)->create(), 'السجل قيد التحضير'],
            'production' => ['production', ['--confirm' => true, '--acknowledge-login-impact' => true], $active(WhatsAppMode::Test), 'لا يفعّل هذا الأمر وضع الإنتاج'],
            'log, even in testing' => ['log', ['--confirm' => true], $active(WhatsAppMode::Disabled), 'وضع log غير متاح من الخادم'],
            'an unknown mode' => ['paused', ['--confirm' => true], $active(WhatsAppMode::Disabled), 'وضع غير معروف'],
            'a mode in capitals' => ['TEST', ['--confirm' => true], $active(WhatsAppMode::Disabled), 'وضع غير معروف'],
            'the current mode' => ['disabled', ['--confirm' => true], $active(WhatsAppMode::Disabled), 'الوضع المطلوب هو الوضع الحالي'],
            'an incomplete test environment' => ['test', ['--confirm' => true], fn () => WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create(['test_access_token' => null]), 'إعدادات بيئة الاختبار ناقصة'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_writes_nothing(string $mode, array $options, Closure $create, string $message): void
    {
        $setting = $create();
        $before = $setting ? WhatsAppActivation::storedValues($setting->fresh()) : null;

        [$status, $output] = $this->runMode($mode, $options);

        $this->assertSame(1, $status);
        $this->assertStringContainsString($message, $output);
        $this->assertStringContainsString('لم يُكتب شيء', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertSame([], $this->requests);
    }

    public function test_the_impact_and_the_environment_are_shown_before_the_missing_confirmation(): void
    {
        $this->active(WhatsAppMode::Test);

        [$status, $output] = $this->runMode('disabled');

        $this->assertSame(1, $status);
        $this->assertImpactBefore('أضف --confirm', $output);
    }

    public function test_the_mode_is_checked_again_under_the_lock(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        // The operator saw "production", the row now says "test".
        try {
            app(WhatsAppSettingsManager::class)->switchModeFromServer(WhatsAppMode::Disabled, WhatsAppMode::Production, false);
            $this->fail('A mode changed since it was shown was switched.');
        } catch (ModeChangeRefusedException $exception) {
            $this->assertSame(ModeChangeRefusedException::STALE, $exception->reason);
        }

        // And the acknowledgement is checked there too, not only by the command.
        $this->app->detectEnvironment(fn () => 'production');
        $setting->update(['mode' => WhatsAppMode::Disabled]);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        try {
            app(WhatsAppSettingsManager::class)->switchModeFromServer(WhatsAppMode::Test, WhatsAppMode::Disabled, false);
            $this->fail('Test was switched to in production without the acknowledgement.');
        } catch (ModeChangeRefusedException $exception) {
            $this->assertSame(ModeChangeRefusedException::ACKNOWLEDGEMENT_REQUIRED, $exception->reason);
        }

        $this->assertNothingWritten($setting, $before);
    }

    // --- Database failures: everything is rolled back -----------------------------------------

    public function test_a_write_stopped_without_an_exception_changes_nothing(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        Event::listen('eloquent.saving: '.WhatsAppSetting::class, fn () => false);

        [$status, $output] = $this->runMode('disabled', ['--confirm' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('خطأ في قاعدة البيانات', $output);
        $this->assertStringNotContainsString('بُدّل الوضع', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertLoggedClassOnly(RuntimeException::class);
    }

    public function test_a_failed_audit_rolls_the_mode_back(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        Event::listen('eloquent.creating: '.WhatsAppSettingsAudit::class, fn () => throw new RuntimeException('audit storage unavailable 1122334455'));

        [$status, $output] = $this->runMode('disabled', ['--confirm' => true]);

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('بُدّل الوضع', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertLoggedClassOnly(RuntimeException::class);
    }

    // --- Concurrency with activation (AC-WA-48) -------------------------------------------------

    public function test_a_switch_during_the_activation_checks_makes_the_activation_refuse(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $this->evidenceFor($setting);
        $this->duringMeta = fn () => $this->assertSame(0, $this->runMode('disabled', ['--confirm' => true])[0]);

        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Test, WhatsAppActivation::ACTIVE, self::REFERENCE, User::factory()->admin()->create(), request());
            $this->fail('A mode switched during the checks was activated.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::SNAPSHOT_CHANGED, $exception->reason);
        }

        $this->assertSame(WhatsAppMode::Disabled, $setting->fresh()->mode);
        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ModeChanged)->count());
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
    }

    // --- OTP codes after the switch -------------------------------------------------------------

    public function test_disabled_stops_every_otp_code_without_any_request(): void
    {
        $this->active(WhatsAppMode::Test);
        $this->assertSame(0, $this->runMode('disabled', ['--confirm' => true])[0]);

        $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone]);

        $this->assertSame([], $this->requests);
        $this->assertSame('blocked', WhatsAppMessageLog::sole()->send_status->value);
    }

    public function test_test_sends_the_next_otp_code_through_the_test_environment(): void
    {
        $this->active(WhatsAppMode::Disabled);
        $this->assertSame(0, $this->runMode('test', ['--confirm' => true])[0]);

        $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone])->assertSessionHasNoErrors();

        $this->assertSame(['/v23.0/100000000000001/messages'], $this->requests);
        $this->assertSame(WhatsAppEnvironment::Test, WhatsAppMessageLog::sole()->environment);
    }

    // --- No secret, no phone number ---------------------------------------------------------------

    public function test_nothing_secret_is_printed_audited_or_logged(): void
    {
        $setting = $this->active(WhatsAppMode::Production);

        [, $output] = $this->runMode('test', ['--confirm' => true]);
        [, $refused] = $this->runMode('disabled');

        $all = $output.$refused.json_encode(WhatsAppSettingsAudit::all()->toArray(), JSON_UNESCAPED_UNICODE).implode("\n", $this->logged);

        foreach ([$setting->test_access_token, $setting->production_access_token, 'fake-env-token-9911', '100000000000001', '100000000000002', '15550000001', '966500000002', '1122334455'] as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    // --- Helpers ---------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function runMode(string $mode, array $options = []): array
    {
        $status = Artisan::call('whatsapp:mode', ['mode' => $mode] + $options);

        return [$status, Artisan::output()];
    }

    private function active(WhatsAppMode $mode): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode($mode)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
    }

    private function evidenceFor(WhatsAppSetting $setting): void
    {
        WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestVerification, User::factory()->admin()->create(), WhatsAppEnvironment::Production, [
            'origin' => 'panel',
            'recipient_last4' => '4567',
            'outcome' => 'verified',
            'fingerprint' => app(SettingsFingerprint::class)->production($setting),
        ]);
    }

    /**
     * @param  ?array<string, mixed>  $before
     */
    private function assertNothingWritten(?WhatsAppSetting $setting, ?array $before): void
    {
        if ($setting === null) {
            $this->assertSame(0, WhatsAppSetting::count());
        } else {
            $this->assertSame($before, WhatsAppActivation::storedValues($setting->fresh()));
        }

        $this->assertSame(0, WhatsAppSettingsAudit::whereIn('action', [WhatsAppAuditAction::ModeChanged, WhatsAppAuditAction::ModeChangeRefused])->count());
    }

    private function assertImpactBefore(string $refusal, string $output): void
    {
        $environment = mb_strpos($output, 'بيئة التطبيق:');
        $impact = mb_strpos($output, 'لا يوجد أي تجاوز للتحقق الثنائي');

        $this->assertNotFalse($environment);
        $this->assertNotFalse($impact);
        $this->assertLessThan(mb_strpos($output, $refusal), $impact);
        $this->assertLessThan($impact, $environment);
    }

    private function assertLoggedClassOnly(string $class): void
    {
        $line = collect($this->logged)->first(fn (string $line) => str_starts_with($line, 'whatsapp:mode: database error'));

        $this->assertNotNull($line);
        $this->assertStringContainsString(json_encode($class), $line);
        $this->assertStringNotContainsString('1122334455', $line);
        $this->assertStringNotContainsString('audit storage', $line);
    }
}
