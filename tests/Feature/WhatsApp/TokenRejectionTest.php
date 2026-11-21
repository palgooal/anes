<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\MetaGraphClient;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppTokenMonitor;
use App\WhatsAppMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-7, decision 4: Meta refusing a saved token while sending (REQ-WA-22, AC-WA-15).
 * Only OAuth error 190 in a 4xx answer marks that token invalid, at once and without
 * any other request; the send itself is unchanged. Tokens are placeholders.
 */
class TokenRejectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const T0 = '2026-11-21 10:00:00.000001';

    private Closure $answer;

    /** @var list<string> Paths of every request to the Graph API. */
    private array $requests = [];

    private ?Closure $during = null;

    /** @var list<string> */
    private array $logged = [];

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

        Carbon::setTestNow(self::T0);
        AdminTestCodes::generateUsing(fn () => '246810');
        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        $this->answer = self::rejected(190, 401);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $this->requests[] = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($this->during) {
                [$callback, $this->during] = [$this->during, null];
                $callback();
            }

            return ($this->answer)();
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
    }

    public function test_otp_codes_refused_with_190_mark_the_active_token_invalid_at_once(): void
    {
        $this->active(WhatsAppMode::Production);

        $this->sendLoginCode();

        // One request only: no debug_token, no second send.
        $this->assertSame(['/v23.0/100000000000002/messages'], $this->requests);
        $row = DB::table('whatsapp_settings')->first();
        $this->assertSame('invalid', $row->production_token_status);
        $this->assertSame(self::T0, Carbon::parse($row->production_token_status_started_at)->format('Y-m-d H:i:s.u'));
        $this->assertNull($row->test_token_status);
        $this->assertSame('190', WhatsAppMessageLog::sole()->error_code);
    }

    public function test_the_user_sees_the_same_failure_with_or_without_the_token_update(): void
    {
        $this->active(WhatsAppMode::Production);
        $first = $this->sendLoginCode();

        // The token state cannot be written: the send ends exactly the same way.
        app(ChannelManager::class)->forgetDrivers();
        $this->app->bind(WhatsAppTokenMonitor::class, fn ($app) => new class($app->make(MetaGraphClient::class)) extends WhatsAppTokenMonitor
        {
            public function recordRejection(WhatsAppConfig $config, Response $response, CarbonImmutable $started): void
            {
                throw new RuntimeException('storage down 100000000000002');
            }
        });
        DB::table('whatsapp_settings')->update(['production_token_status' => null, 'production_token_status_started_at' => null]);
        $second = $this->sendLoginCode(later: true);

        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertCount(2, $this->requests);
        $this->assertNull(DB::table('whatsapp_settings')->value('production_token_status'));

        $line = collect($this->logged)->first(fn (string $line) => str_starts_with($line, 'WhatsApp: the token state could not be updated'));
        $this->assertNotNull($line);
        $this->assertStringContainsString('RuntimeException', $line);
        $this->assertStringNotContainsString('storage down', $line);
    }

    /**
     * @return array<string, array{Closure}>
     */
    public static function otherFailures(): array
    {
        return [
            'permission (10)' => [self::rejected(10, 403)],
            'permission (200)' => [self::rejected(200, 403)],
            'recipient not allowed (131030)' => [self::rejected(131030, 400)],
            'template missing (132001)' => [self::rejected(132001, 404)],
            'rate limit (429)' => [self::rejected(80007, 429)],
            '190 in a 500' => [self::rejected(190, 500)],
            'server error' => [fn () => Http::response('', 503)],
            'no connection' => [fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('otherFailures')]
    public function test_other_failures_never_change_the_token_state(Closure $answer): void
    {
        $this->answer = $answer;
        $this->active(WhatsAppMode::Production);

        $this->sendLoginCode();

        $this->assertSame(['/v23.0/100000000000002/messages'], $this->requests);
        $this->assertNull(DB::table('whatsapp_settings')->value('production_token_status'));
    }

    public function test_a_send_test_from_the_server_marks_only_that_environment(): void
    {
        $this->active(WhatsAppMode::Test);

        Artisan::call('whatsapp:test', ['phone' => '+966551234567', '--environment' => 'production', '--confirm-production' => true, '--no-interaction' => true]);

        $row = DB::table('whatsapp_settings')->first();
        $this->assertSame('invalid', $row->production_token_status);
        $this->assertNull($row->test_token_status);
        $this->assertSame(['/v23.0/100000000000002/messages'], $this->requests);
    }

    /**
     * @return array<string, array{?Closure, array<string, mixed>}>
     */
    public static function envSends(): array
    {
        return [
            'no settings row' => [null, []],
            'a row being prepared' => [fn () => WhatsAppSetting::factory()->preparing()->create(), []],
            '--legacy-env on an active row' => [fn () => WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(), ['--legacy-env' => true]],
        ];
    }

    #[DataProvider('envSends')]
    public function test_a_refused_env_token_writes_nothing_and_creates_no_row(?Closure $create, array $command): void
    {
        $create && $create();

        if ($command === []) {
            $this->sendLoginCode();
        } else {
            Artisan::call('whatsapp:test', ['phone' => '+966551234567', '--confirm-production' => true, '--no-interaction' => true] + $command);
        }

        $this->assertSame(['/v23.0/1122334455/messages'], $this->requests);
        $this->assertSame($create ? 1 : 0, WhatsAppSetting::count());
        $this->assertNull(DB::table('whatsapp_settings')->value('production_token_status'));
        $this->assertNull(WhatsAppMessageLog::sole()->environment);
        $this->assertSame('190', WhatsAppMessageLog::sole()->error_code);
    }

    public function test_a_token_replaced_while_sending_is_not_marked(): void
    {
        $setting = $this->active(WhatsAppMode::Production);
        $this->during = function () use ($setting) {
            $fresh = $setting->fresh();
            $fresh->setSecret('production_access_token', 'fake-production-token-0003', null);
            $fresh->save();
        };

        $this->sendLoginCode();

        $this->assertNull(DB::table('whatsapp_settings')->value('production_token_status'));
    }

    public function test_an_older_rejection_does_not_overwrite_a_newer_check(): void
    {
        $this->active(WhatsAppMode::Production);
        $this->during = fn () => DB::table('whatsapp_settings')->update([
            'production_token_status' => 'valid',
            'production_token_status_started_at' => '2026-11-21 10:00:00.000002',
        ]);

        $this->sendLoginCode();

        $this->assertSame('valid', DB::table('whatsapp_settings')->value('production_token_status'));
    }

    public function test_nothing_secret_is_stored_or_logged(): void
    {
        $this->answer = fn () => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token fake-production-token-0002']], 401);
        $this->active(WhatsAppMode::Production);

        $this->sendLoginCode();

        $row = json_encode(DB::table('whatsapp_settings')->first(['production_token_status', 'production_token_status_started_at', 'production_token_check_failed_at', 'production_token_checked_at']));

        // Only what this phase writes: the existing report of the failed send is unchanged.
        $this->assertStringNotContainsString('fake-production-token-0002', $row);
        $this->assertStringNotContainsString('Error validating', $row);
        $this->assertFalse(collect($this->logged)->contains(fn (string $line) => str_contains($line, 'token state')));
    }

    // --- Helpers --------------------------------------------------------------------------------

    private static function rejected(int $code, int $status): Closure
    {
        return fn () => Http::response(['error' => ['code' => $code, 'type' => 'OAuthException', 'message' => 'Rejected by Meta']], $status);
    }

    private function active(WhatsAppMode $mode): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode($mode)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
    }

    private function sendLoginCode(bool $later = false): TestResponse
    {
        auth()->logout();

        if ($later) {
            $this->travel(2)->minutes();
        }

        return $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone]);
    }
}
