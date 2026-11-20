<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\EnvRestoreRefusedException;
use App\Services\WhatsApp\ModeChangeRefusedException;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
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
 * PH-WA-6.8: whatsapp:restore-env (REQ-WA-70, REQ-WA-71, AC-WA-52, AC-WA-53). The
 * check of .env is local and never sends anything to Meta. Every refusal writes
 * nothing at all, not even an audit entry (decision 1). Tokens are placeholders.
 */
class WhatsAppRestoreEnvCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REFERENCE = 'ملف التحقق من الملكية NO-2026-117';

    private const ENV_TOKEN = 'fake-env-token-9911';

    /** @var list<string> Paths of every request to the Graph API. */
    private array $requests = [];

    /** @var list<string> Login codes found in the messages sent. */
    private array $codes = [];

    /** @var list<string> Every log line, with its context. */
    private array $logged = [];

    private ?Closure $duringMeta = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useEnv();

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->requests[] = $path;

            if ($this->duringMeta) {
                [$callback, $this->duringMeta] = [$this->duringMeta, null];
                $callback();
            }

            if (str_ends_with($path, '/messages')) {
                $this->codes[] = (string) data_get($request->data(), 'template.components.0.parameters.0.text');

                return Http::response(['messages' => [['id' => 'wamid.ENV'.count($this->requests)]]]);
            }

            return match (true) {
                str_ends_with($path, '/debug_token') => Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]),
                str_ends_with($path, '/message_templates') => Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION', 'components' => []]]]),
                default => Http::response(['display_phone_number' => '+966 50 000 0002']),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
    }

    // --- Successful restores (AC-WA-52) -----------------------------------------------------------

    /**
     * @return array<string, array{WhatsAppMode}>
     */
    public static function activeModes(): array
    {
        return ['production' => [WhatsAppMode::Production], 'test' => [WhatsAppMode::Test], 'disabled' => [WhatsAppMode::Disabled]];
    }

    #[DataProvider('activeModes')]
    public function test_only_the_source_state_changes_and_the_restore_is_audited(WhatsAppMode $mode): void
    {
        $setting = $this->active($mode);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        [$status, $output] = $this->restore(['--confirm' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertFalse($setting->fresh()->isDatabaseSource());

        $after = WhatsAppActivation::storedValues($setting->fresh());
        $this->assertNull($after['database_source_activated_at']);
        unset($before['database_source_activated_at'], $after['database_source_activated_at']);
        $this->assertSame($before, $after);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::EnvRestored, $audit->action);
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->environment);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertSame([
            'source' => ['old' => 'active', 'new' => 'preparing'],
            'mode' => $mode->value,
            'env_driver' => 'meta',
            'via' => 'server',
        ], $audit->changes);
        $this->assertSame([], $this->requests);
    }

    public function test_every_warning_comes_before_the_options_are_checked(): void
    {
        $this->active(WhatsAppMode::Production);

        [$status, $output] = $this->restore();

        $this->assertSame(1, $status);

        $refusal = mb_strpos($output, 'أضف --confirm');
        $this->assertNotFalse($refusal);

        foreach ([
            'بيئة التطبيق: testing',
            'الوضع production',
            'التحقق من .env مبدئي',
            'لا يضمن نجاح الإرسال الفعلي',
            'تتوقف رموز الدخول للمستفيدين والفريق والمدير نفسه',
            'whatsapp:test --legacy-env --confirm-production قبل الاستعادة وبعدها',
            'أبقِ جلسة مدير مفتوحة',
            'التراجع يتم بالتفعيل من اللوحة وحده',
            'لا تبقى معرّفات بيئة الإنتاج مجمّدة',
        ] as $warning) {
            $position = mb_strpos($output, $warning);
            $this->assertNotFalse($position, $warning);
            $this->assertLessThan($refusal, $position, $warning);
        }

        $this->assertStringNotContainsString('اسم قالب الدخول', $output);
        $this->assertStringNotContainsString('مخزّنة مؤقتًا', $output);
    }

    public function test_an_empty_login_template_is_a_warning_not_a_refusal(): void
    {
        $this->useEnv(['login_template' => '']);
        $setting = $this->active(WhatsAppMode::Production);

        [$status, $output] = $this->restore(['--confirm' => true]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('اسم قالب الدخول في إعدادات .env فارغ', $output);
        $this->assertFalse($setting->fresh()->isDatabaseSource());
        $this->assertSame([], $this->requests);
    }

    public function test_log_is_accepted_in_local_and_testing(): void
    {
        foreach (['testing', 'local'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            WhatsAppSetting::query()->delete();
            $this->useEnv(['driver' => 'log', 'phone_number_id' => null, 'access_token' => null]);
            $setting = $this->active(WhatsAppMode::Test);

            [$status, $output] = $this->restore(['--confirm' => true]);

            $this->assertSame(0, $status, $output);
            $this->assertFalse($setting->fresh()->isDatabaseSource());
            $this->assertSame('log', WhatsAppSettingsAudit::latest('id')->first()->changes['env_driver']);
        }
    }

    // --- Refusals: nothing is written, not even an audit entry (AC-WA-52, decision 1) -----------

    /**
     * @return array<string, array{array<string, mixed>, Closure, string, string}>
     */
    public static function refusals(): array
    {
        $active = fn () => WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        return [
            '--env' => [['--confirm' => true, '--env' => 'testing'], $active, 'لا يُقبل الخيار --env', 'testing'],
            'no --confirm' => [[], $active, 'أضف --confirm', 'testing'],
            'no settings row' => [['--confirm' => true], fn () => null, 'لا توجد إعدادات واتساب', 'testing'],
            'a row being prepared' => [['--confirm' => true], fn () => WhatsAppSetting::factory()->preparing()->create(), 'السجل قيد التحضير', 'testing'],
            'an unknown driver' => [['--confirm' => true, 'env' => ['driver' => 'whatever']], $active, 'ليس meta ولا log', 'testing'],
            'no driver' => [['--confirm' => true, 'env' => ['driver' => null]], $active, 'ليس meta ولا log', 'testing'],
            'log in staging' => [['--confirm' => true, 'env' => ['driver' => 'log']], $active, 'لا يُقبل إلا في بيئة local أو testing', 'staging'],
            'log in production' => [['--confirm' => true, 'env' => ['driver' => 'log']], $active, 'لا يُقبل إلا في بيئة local أو testing', 'production'],
            'no Phone Number ID' => [['--confirm' => true, 'env' => ['phone_number_id' => null]], $active, 'إعدادات .env ناقصة: WHATSAPP_PHONE_NUMBER_ID.', 'production'],
            'a blank access token' => [['--confirm' => true, 'env' => ['access_token' => '   ']], $active, 'إعدادات .env ناقصة: WHATSAPP_ACCESS_TOKEN.', 'testing'],
            'neither' => [['--confirm' => true, 'env' => ['phone_number_id' => '', 'access_token' => null]], $active, 'WHATSAPP_PHONE_NUMBER_ID، WHATSAPP_ACCESS_TOKEN', 'testing'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_writes_nothing(array $options, Closure $create, string $message, string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->useEnv($options['env'] ?? []);
        unset($options['env']);
        $setting = $create();
        $before = $setting ? WhatsAppActivation::storedValues($setting->fresh()) : null;

        [$status, $output] = $this->restore($options);

        $this->assertSame(1, $status);
        $this->assertStringContainsString($message, $output);
        $this->assertStringContainsString('لم يُكتب شيء', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertSame([], $this->requests);
    }

    public function test_the_conditions_are_checked_again_under_the_lock(): void
    {
        $setting = $this->active(WhatsAppMode::Production);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->useEnv(['access_token' => null]);

        try {
            app(WhatsAppSettingsManager::class)->restoreEnv();
            $this->fail('An incomplete .env was restored.');
        } catch (EnvRestoreRefusedException $exception) {
            $this->assertSame(EnvRestoreRefusedException::ENV_INCOMPLETE, $exception->reason);
            $this->assertSame(['WHATSAPP_ACCESS_TOKEN'], $exception->missing);
        }

        $this->assertNothingWritten($setting, $before);
    }

    // --- Database failures: everything is rolled back -----------------------------------------

    public function test_a_write_stopped_without_an_exception_changes_nothing(): void
    {
        $setting = $this->active(WhatsAppMode::Production);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        Event::listen('eloquent.saving: '.WhatsAppSetting::class, fn () => false);

        [$status, $output] = $this->restore(['--confirm' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('خطأ في قاعدة البيانات', $output);
        $this->assertStringNotContainsString('أصبح .env مصدر', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertLoggedClassOnly(RuntimeException::class);
    }

    public function test_a_failed_audit_rolls_the_restore_back(): void
    {
        $setting = $this->active(WhatsAppMode::Production);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        Event::listen('eloquent.creating: '.WhatsAppSettingsAudit::class, fn () => throw new RuntimeException('audit storage unavailable 1122334455'));

        [$status, $output] = $this->restore(['--confirm' => true]);

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('أصبح .env مصدر', $output);
        $this->assertNothingWritten($setting, $before);
        $this->assertTrue($setting->fresh()->isDatabaseSource());
        $this->assertLoggedClassOnly(RuntimeException::class);
    }

    // --- Concurrency with activation and the panel (AC-WA-48) ------------------------------------

    public function test_a_restore_during_the_activation_checks_makes_the_activation_refuse(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $this->evidenceFor($setting);
        $this->duringMeta = fn () => $this->assertSame(0, $this->restore(['--confirm' => true])[0]);

        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Test, WhatsAppActivation::ACTIVE, self::REFERENCE, User::factory()->admin()->create(), request());
            $this->fail('A source restored during the checks was activated.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::SNAPSHOT_CHANGED, $exception->reason);
        }

        $this->assertFalse($setting->fresh()->isDatabaseSource());
        $this->assertSame(WhatsAppMode::Test, $setting->fresh()->mode);
        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::EnvRestored)->count());
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
    }

    public function test_after_a_restore_the_panel_cannot_switch_the_mode_and_activation_runs_every_check_again(): void
    {
        $setting = $this->active(WhatsAppMode::Production);
        $this->evidenceFor($setting);
        $this->assertSame(0, $this->restore(['--confirm' => true])[0]);
        $admin = User::factory()->admin()->create();

        try {
            app(WhatsAppSettingsManager::class)->switchMode(WhatsAppMode::Disabled, WhatsAppMode::Production, $admin, request());
            $this->fail('The panel switched the mode of a row being prepared.');
        } catch (ModeChangeRefusedException $exception) {
            $this->assertSame(ModeChangeRefusedException::PREPARING, $exception->reason);
        }

        // The state the operator last saw no longer holds.
        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Production, WhatsAppActivation::ACTIVE, self::REFERENCE, $admin, request());
            $this->fail('An activation reviewed before the restore went through.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::STALE, $exception->reason);
        }

        $this->requests = [];
        app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Production, WhatsAppActivation::PREPARING, self::REFERENCE, $admin, request());

        $this->assertCount(3, $this->requests);
        $this->assertTrue($setting->fresh()->isDatabaseSource());
        $this->assertSame(['old' => 'preparing', 'new' => 'active'], WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->sole()->changes['source']);
    }

    // --- OTP codes after the restore ---------------------------------------------------------------

    public function test_the_next_otp_code_goes_through_env_and_a_code_sent_before_still_works(): void
    {
        $this->active(WhatsAppMode::Test);
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasNoErrors();
        $this->assertSame(['/v23.0/100000000000001/messages'], $this->requests);
        $code = end($this->codes);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->assertSame(0, $this->restore(['--confirm' => true])[0]);

        // A code sent through the test environment before the restore is still valid.
        $this->post(route('login.verify'), ['code' => $code]);
        $this->assertAuthenticatedAs($patient);

        // The next one goes through .env, logged without an environment (REQ-WA-48).
        auth()->logout();
        $this->travel(2)->minutes();
        $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone])->assertSessionHasNoErrors();

        $this->assertSame('/v23.0/1122334455/messages', end($this->requests));
        $this->assertNull(WhatsAppMessageLog::latest('id')->first()->environment);
        $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);
    }

    public function test_with_the_log_driver_the_next_otp_code_is_written_to_the_log(): void
    {
        $this->useEnv(['driver' => 'log', 'phone_number_id' => null, 'access_token' => null]);
        $this->active(WhatsAppMode::Test);
        $this->assertSame(0, $this->restore(['--confirm' => true])[0]);

        $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone])->assertSessionHasNoErrors();

        $this->assertSame([], $this->requests);
        $this->assertTrue(collect($this->logged)->contains(fn (string $line) => str_starts_with($line, 'WhatsApp login code (log driver, not sent)')));
    }

    // --- No secret, no phone number ---------------------------------------------------------------

    public function test_nothing_secret_is_printed_audited_or_logged(): void
    {
        $setting = $this->active(WhatsAppMode::Production);

        [, $refused] = $this->restore();
        [, $output] = $this->restore(['--confirm' => true]);

        $this->useEnv(['access_token' => null]);
        $setting->forceFill(['database_source_activated_at' => now()])->save();
        [, $incomplete] = $this->restore(['--confirm' => true]);

        $all = $refused.$output.$incomplete.json_encode(WhatsAppSettingsAudit::all()->toArray(), JSON_UNESCAPED_UNICODE).implode("\n", $this->logged);

        foreach ([$setting->test_access_token, $setting->production_access_token, self::ENV_TOKEN, '100000000000001', '100000000000002', '15550000001', '966500000002', '1122334455', '966539564429'] as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    // --- Helpers ---------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function useEnv(array $overrides = []): void
    {
        config(['services.whatsapp' => $overrides + [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => self::ENV_TOKEN,
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function restore(array $options = []): array
    {
        $status = Artisan::call('whatsapp:restore-env', $options);

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

        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::EnvRestored)->count());
    }

    private function assertLoggedClassOnly(string $class): void
    {
        $line = collect($this->logged)->first(fn (string $line) => str_starts_with($line, 'whatsapp:restore-env: database error'));

        $this->assertNotNull($line);
        $this->assertStringContainsString(json_encode($class), $line);
        $this->assertStringNotContainsString('1122334455', $line);
        $this->assertStringNotContainsString('audit storage', $line);
    }
}
