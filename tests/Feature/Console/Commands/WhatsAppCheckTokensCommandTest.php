<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\Services\WhatsApp\WhatsAppTokenMonitor;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-7: whatsapp:check-tokens and the order of token results (REQ-WA-21, REQ-WA-22,
 * AC-WA-40, decisions 2 and 3). Tokens are placeholders; no request reaches Meta.
 */
class WhatsAppCheckTokensCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TEST_TOKEN = 'fake-test-token-0001';

    private const PRODUCTION_TOKEN = 'fake-production-token-0002';

    private const ENV_TOKEN = 'fake-env-token-9911';

    private const T0 = '2026-11-21 03:17:00.000001';

    /** @var array<string, Closure|array{0: mixed, 1: int}> Meta's answer to debug_token, by token. */
    private array $answers = [];

    /** @var list<string> The token of every debug_token request, kept in memory only. */
    private array $inspected = [];

    /** @var array<string, Closure> Run once while Meta answers about a token. */
    private array $during = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'phone_number_id' => '1122334455',
            'access_token' => self::ENV_TOKEN,
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        Carbon::setTestNow(self::T0);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! str_ends_with($path, '/debug_token')) {
                return match (true) {
                    str_ends_with($path, '/message_templates') => Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION', 'components' => []]]]),
                    default => Http::response(['display_phone_number' => '+966 50 000 0002']),
                };
            }

            $token = (string) ($request->data()['input_token'] ?? '');
            $this->inspected[] = $token;

            if (isset($this->during[$token])) {
                $callback = $this->during[$token];
                unset($this->during[$token]);
                $callback();
            }

            $answer = $this->answers[$token] ?? self::valid(time() + 30 * 86400);

            return $answer instanceof Closure ? $answer() : Http::response(...$answer);
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
    }

    // --- Confirmed results (REQ-WA-21, REQ-WA-22, AC-WA-40) -----------------------------------------

    public function test_both_saved_tokens_are_checked_and_their_state_stored(): void
    {
        $expiry = Carbon::parse('2026-12-21 10:00:00')->getTimestamp();
        $this->answers[self::TEST_TOKEN] = self::valid(0);
        $this->answers[self::PRODUCTION_TOKEN] = self::valid($expiry);
        $setting = $this->saved();

        [$status, $output] = $this->check();

        $this->assertSame(0, $status, $output);
        $this->assertSame([self::TEST_TOKEN, self::PRODUCTION_TOKEN], $this->inspected);

        $row = $this->raw();
        $this->assertSame('valid', $row->test_token_status);
        $this->assertSame(self::T0, $this->micro($row->test_token_status_started_at));
        $this->assertNull($row->test_token_check_failed_at);
        $this->assertTrue((bool) $row->test_token_never_expires);
        $this->assertSame('meta', $row->test_token_expiry_source);

        $fresh = $setting->fresh();
        $this->assertSame('2026-11-21 03:17:00', $fresh->production_token_checked_at->toDateTimeString());
        $this->assertSame('2026-12-21 10:00:00', $fresh->production_token_expires_at->toDateTimeString());
        $this->assertFalse($fresh->production_token_never_expires);
        $this->assertSame('valid', $fresh->production_token_status);
    }

    public function test_a_token_without_a_known_expiry_is_still_valid_with_an_unknown_source(): void
    {
        $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging']]], 200];
        $this->saved();

        $this->assertSame(0, $this->check()[0]);

        $this->assertSame('unknown', $this->raw()->production_token_expiry_source);
        $this->assertNull($this->raw()->production_token_expires_at);
    }

    /**
     * @return array<string, array{array{0: mixed, 1: int}}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'is_valid false' => [[['data' => ['is_valid' => false, 'scopes' => []]], 200]],
            'OAuth 190 in a 400' => [[['error' => ['code' => 190, 'type' => 'OAuthException', 'message' => 'Error validating access token']], 400]],
            'OAuth 190 in a 401' => [[['error' => ['code' => '190', 'error_subcode' => 463]], 401]],
        ];
    }

    #[DataProvider('invalidAnswers')]
    public function test_an_invalid_token_is_marked_invalid_and_the_other_one_is_still_checked(array $answer): void
    {
        $this->answers[self::TEST_TOKEN] = $answer;
        $this->saved();

        [$status, $output] = $this->check();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('test: أفادت Meta بأن الرمز غير صالح', $output);
        $this->assertStringContainsString('production: الرمز صالح', $output);
        $this->assertSame('invalid', $this->raw()->test_token_status);
        $this->assertSame('valid', $this->raw()->production_token_status);
        $this->assertStringNotContainsString('Error validating', $output.implode("\n", $this->logged));
    }

    // --- A failed check never changes the confirmed state (decision 2) ------------------------------

    /**
     * @return array<string, array{Closure}>
     */
    public static function failedChecks(): array
    {
        return [
            'no connection' => [fn () => throw new ConnectionException('cURL error 28: timed out')],
            'HTTP 429' => [fn () => Http::response(['error' => ['code' => 4]], 429)],
            'HTTP 500' => [fn () => Http::response(['error' => ['code' => 1]], 500)],
            'HTTP 503' => [fn () => Http::response('', 503)],
            'a 400 that is not 190' => [fn () => Http::response(['error' => ['code' => 100]], 400)],
            'a 403 permission error' => [fn () => Http::response(['error' => ['code' => 10]], 403)],
            '190 in a 500' => [fn () => Http::response(['error' => ['code' => 190]], 500)],
            '200 without data' => [fn () => Http::response(['ok' => true], 200)],
            'not JSON' => [fn () => Http::response('<html>gateway</html>', 200)],
        ];
    }

    #[DataProvider('failedChecks')]
    public function test_a_failed_check_keeps_the_last_confirmed_state(Closure $answer): void
    {
        $this->saved();
        $this->assertSame(0, $this->check()[0]);
        $confirmed = (array) $this->raw();

        Carbon::setTestNow('2026-11-22 03:17:00.000002');
        $this->answers[self::PRODUCTION_TOKEN] = $answer;

        [$status, $output] = $this->check();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('production: تعذّر الفحص لدى Meta', $output);

        $row = (array) $this->raw();
        $this->assertSame('2026-11-22 03:17:00.000002', $this->micro($row['production_token_check_failed_at']));

        foreach (['production_token_status', 'production_token_status_started_at', 'production_token_checked_at', 'production_token_expires_at', 'production_token_never_expires', 'production_token_expiry_source'] as $column) {
            $this->assertSame($confirmed[$column], $row[$column], $column);
        }

        // A later confirmed check clears the failure.
        Carbon::setTestNow('2026-11-23 03:17:00.000003');
        unset($this->answers[self::PRODUCTION_TOKEN]);
        $this->assertSame(0, $this->check()[0]);
        $this->assertNull($this->raw()->production_token_check_failed_at);
    }

    // --- Which tokens are checked (decision 3) -----------------------------------------------------

    public function test_without_a_settings_row_nothing_is_checked_or_created(): void
    {
        [$status, $output] = $this->check();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('whatsapp:test --legacy-env', $output);
        $this->assertSame([], $this->inspected);
        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_empty_tokens_are_skipped_and_the_env_token_is_never_checked(): void
    {
        WhatsAppSetting::factory()->create(['test_access_token' => null, 'production_access_token' => null]);

        [$status, $output] = $this->check();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('test: لا يوجد رمز محفوظ', $output);
        $this->assertSame([], $this->inspected);
    }

    public function test_a_row_being_prepared_is_checked_and_otp_codes_stay_on_env(): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->create();

        $this->assertSame(0, $this->check()[0]);

        $this->assertSame([self::TEST_TOKEN, self::PRODUCTION_TOKEN], $this->inspected);
        $this->assertNotContains(self::ENV_TOKEN, $this->inspected);
        $this->assertFalse($setting->fresh()->isDatabaseSource());
        $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);
        Http::assertNotSent(fn (Request $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/messages'));
    }

    public function test_env_is_refused(): void
    {
        $this->saved();

        $status = Artisan::call('whatsapp:check-tokens', ['--env' => 'testing']);

        $this->assertSame(1, $status);
        $this->assertSame([], $this->inspected);
    }

    public function test_an_error_in_one_environment_does_not_stop_the_other(): void
    {
        $this->saved();
        DB::table('whatsapp_settings')->update(['test_access_token' => 'not-a-ciphertext']);

        [$status, $output] = $this->check();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('test: تعذّر الفحص بسبب خطأ غير متوقع', $output);
        $this->assertSame([self::PRODUCTION_TOKEN], $this->inspected);
        $this->assertSame('valid', $this->raw()->production_token_status);

        $line = collect($this->logged)->first(fn (string $line) => str_starts_with($line, 'whatsapp:check-tokens: the check failed.'));
        $this->assertNotNull($line);
        $this->assertStringContainsString('DecryptException', $line);
        $this->assertStringNotContainsString('not-a-ciphertext', $line);
    }

    // --- Order of results: an older attempt never overwrites a newer one ------------------------

    public function test_an_older_result_arriving_late_does_not_overwrite_a_newer_one(): void
    {
        $this->saved();

        // While Meta answers the first check (started at T0), a second one starts later and finds it invalid.
        $this->during[self::PRODUCTION_TOKEN] = function () {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
            $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->answers[self::PRODUCTION_TOKEN] = self::valid(0);
        };

        $outer = app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production);

        $this->assertSame(['result' => 'valid', 'applied' => false], $outer);
        $this->assertSame('invalid', $this->raw()->production_token_status);
        $this->assertSame('2026-11-21 03:17:00.000002', $this->micro($this->raw()->production_token_status_started_at));
    }

    public function test_an_attempt_that_started_at_the_same_time_cannot_overwrite_a_stored_result(): void
    {
        $this->saved();

        $this->during[self::PRODUCTION_TOKEN] = function () {
            // Same start time, T0: the one stored first stays.
            $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
            $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->answers[self::PRODUCTION_TOKEN] = self::valid(0);
        };

        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
        $this->assertSame('invalid', $this->raw()->production_token_status);

        // A failed check at that same time is not newer either.
        $this->answers[self::PRODUCTION_TOKEN] = [['error' => ['code' => 1]], 500];
        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
        $this->assertNull($this->raw()->production_token_check_failed_at);
    }

    public function test_an_older_failed_check_does_not_hide_a_newer_confirmed_one(): void
    {
        $this->saved();
        $this->answers[self::PRODUCTION_TOKEN] = [['error' => ['code' => 2]], 503];
        $this->during[self::PRODUCTION_TOKEN] = function () {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            $answer = $this->answers[self::PRODUCTION_TOKEN];
            unset($this->answers[self::PRODUCTION_TOKEN]);
            $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->answers[self::PRODUCTION_TOKEN] = $answer;
        };

        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);

        $this->assertSame('valid', $this->raw()->production_token_status);
        $this->assertNull($this->raw()->production_token_check_failed_at);
    }

    public function test_a_newer_failed_check_stays_when_an_older_confirmed_one_arrives(): void
    {
        $this->saved();
        $this->during[self::PRODUCTION_TOKEN] = function () {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            $this->answers[self::PRODUCTION_TOKEN] = fn () => throw new ConnectionException('timed out');
            $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            unset($this->answers[self::PRODUCTION_TOKEN]);
        };

        $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);

        $row = $this->raw();
        $this->assertSame('valid', $row->production_token_status);
        $this->assertSame(self::T0, $this->micro($row->production_token_status_started_at));
        $this->assertSame('2026-11-21 03:17:00.000002', $this->micro($row->production_token_check_failed_at));
    }

    public function test_an_older_failed_check_does_not_replace_a_newer_failed_one(): void
    {
        $this->saved();
        $this->answers[self::PRODUCTION_TOKEN] = [['error' => ['code' => 2]], 503];
        $this->during[self::PRODUCTION_TOKEN] = function () {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            $this->assertTrue(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
        };

        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);

        $this->assertSame('2026-11-21 03:17:00.000002', $this->micro($this->raw()->production_token_check_failed_at));
    }

    // --- Rotation and saving the same token again ------------------------------------------------

    public function test_a_token_replaced_without_any_new_result_gets_no_result(): void
    {
        $setting = $this->saved();
        $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
        $this->during[self::PRODUCTION_TOKEN] = function () use ($setting) {
            $fresh = $setting->fresh();
            $fresh->setSecret('production_access_token', 'fake-production-token-0003', null);
            $fresh->save();
        };

        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);

        $this->assertNull($this->raw()->production_token_status);
        $this->assertNull($this->raw()->production_token_status_started_at);
    }

    public function test_a_result_about_a_replaced_token_is_dropped(): void
    {
        $setting = $this->saved();
        $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
        $this->during[self::PRODUCTION_TOKEN] = function () use ($setting) {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            app(WhatsAppSettingsManager::class)->replaceSecrets($setting->fresh(), WhatsAppEnvironment::Production, ['production_access_token' => 'fake-production-token-0003'], User::factory()->admin()->create(), request());
        };

        [$status, $output] = $this->check();

        $this->assertStringContainsString('production: لم تُحفظ النتيجة', $output);
        $this->assertSame(0, $status);
        $this->assertSame('valid', $this->raw()->production_token_status);
        $this->assertSame('2026-11-21 03:17:00.000002', $this->micro($this->raw()->production_token_status_started_at));
    }

    public function test_saving_the_same_token_again_drops_older_results(): void
    {
        $setting = $this->saved();
        $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
        $this->during[self::PRODUCTION_TOKEN] = function () use ($setting) {
            Carbon::setTestNow('2026-11-21 03:17:00.000002');
            $answer = $this->answers[self::PRODUCTION_TOKEN];
            unset($this->answers[self::PRODUCTION_TOKEN]);
            $before = $setting->fresh()->getRawOriginal('production_access_token');
            app(WhatsAppSettingsManager::class)->replaceSecrets($setting->fresh(), WhatsAppEnvironment::Production, ['production_access_token' => self::PRODUCTION_TOKEN], User::factory()->admin()->create(), request());
            // The same token keeps its ciphertext: only the order protects the saved result.
            $this->assertSame($before, $setting->fresh()->getRawOriginal('production_access_token'));
            $this->answers[self::PRODUCTION_TOKEN] = $answer;
        };

        $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);

        $this->assertSame('valid', $this->raw()->production_token_status);
        $this->assertSame('2026-11-21 03:17:00.000002', $this->micro($this->raw()->production_token_status_started_at));
    }

    public function test_saving_the_same_token_does_not_override_a_newer_result_but_a_new_token_does(): void
    {
        $setting = $this->saved();
        $admin = User::factory()->admin()->create();

        // A check that started after the save's own debug_token finds the token invalid.
        $this->answers[self::PRODUCTION_TOKEN] = function () {
            if (count(array_keys($this->inspected, self::PRODUCTION_TOKEN, true)) === 1) {
                Carbon::setTestNow('2026-11-21 03:17:00.000002');
                $this->answers[self::PRODUCTION_TOKEN] = [['data' => ['is_valid' => false]], 200];
                app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production);
            }

            return Http::response(self::valid(0)[0]);
        };

        app(WhatsAppSettingsManager::class)->replaceSecrets($setting->fresh(), WhatsAppEnvironment::Production, ['production_access_token' => self::PRODUCTION_TOKEN], $admin, request());

        $this->assertSame('invalid', $this->raw()->production_token_status);

        // A new token starts a new history, whatever was stored for the old one.
        unset($this->answers[self::PRODUCTION_TOKEN]);
        Carbon::setTestNow(self::T0);
        app(WhatsAppSettingsManager::class)->replaceSecrets($setting->fresh(), WhatsAppEnvironment::Production, ['production_access_token' => 'fake-production-token-0004'], $admin, request());

        $row = $this->raw();
        $this->assertSame('valid', $row->production_token_status);
        $this->assertSame(self::T0, $this->micro($row->production_token_status_started_at));
        $this->assertNull($row->production_token_check_failed_at);
        $this->assertNotNull($setting->fresh()->production_token_checked_at);
    }

    // --- Concurrency with activation (REQ-WA-67) ------------------------------------------------

    public function test_a_check_during_the_activation_checks_makes_the_activation_refuse(): void
    {
        $setting = $this->saved(WhatsAppMode::Test);
        WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestVerification, User::factory()->admin()->create(), WhatsAppEnvironment::Production, [
            'origin' => 'panel', 'recipient_last4' => '4567', 'outcome' => 'verified',
            'fingerprint' => app(SettingsFingerprint::class)->production($setting),
        ]);
        $this->during[self::PRODUCTION_TOKEN] = fn () => app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Test);

        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active', 'ملف التحقق من الملكية NO-2026-117', User::factory()->admin()->create(), request());
            $this->fail('An activation went through a row changed during its checks.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::SNAPSHOT_CHANGED, $exception->reason);
        }

        $this->assertSame(WhatsAppMode::Test, $setting->fresh()->mode);
        $this->assertSame('valid', $this->raw()->test_token_status);
    }

    // --- Scheduling (EXT-WA-06) ---------------------------------------------------------------

    public function test_the_check_is_scheduled_daily_on_one_server_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'whatsapp:check-tokens'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('17 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    // --- No secret ------------------------------------------------------------------------------

    public function test_nothing_secret_or_from_meta_is_printed_stored_or_logged(): void
    {
        $this->answers[self::TEST_TOKEN] = [['error' => ['code' => 190, 'message' => 'Error validating access token '.self::TEST_TOKEN]], 400];
        $this->answers[self::PRODUCTION_TOKEN] = fn () => throw new ConnectionException('timed out for '.self::PRODUCTION_TOKEN);
        $this->saved();

        [, $output] = $this->check();

        $row = (array) $this->raw();
        unset($row['test_access_token'], $row['production_access_token'], $row['test_app_secret'], $row['production_app_secret'], $row['webhook_verify_token']);
        $all = $output.json_encode($row).implode("\n", $this->logged).json_encode(WhatsAppSettingsAudit::all()->toArray());

        foreach ([self::TEST_TOKEN, self::PRODUCTION_TOKEN, self::ENV_TOKEN, 'Error validating', 'timed out'] as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    // --- Helpers --------------------------------------------------------------------------------

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function valid(int $expiresAt): array
    {
        return [['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => $expiresAt]], 200];
    }

    private function saved(WhatsAppMode $mode = WhatsAppMode::Production): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode($mode)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
    }

    /**
     * @return array{int, string}
     */
    private function check(): array
    {
        $status = Artisan::call('whatsapp:check-tokens');

        return [$status, Artisan::output()];
    }

    private function raw(): object
    {
        return DB::table('whatsapp_settings')->first();
    }

    /**
     * The stored attempt time, to the microsecond, whatever the database returns.
     */
    private function micro(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s.u');
    }
}
