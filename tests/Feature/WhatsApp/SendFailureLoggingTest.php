<?php

namespace Tests\Feature\WhatsApp;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Notifications\LoginCodeNotification;
use App\WhatsAppAuditAction;
use App\WhatsAppMode;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Security review after PH-WA-7: a failed WhatsApp send never writes Meta's answer,
 * the request URL, a phone number, a code or a token to the application log, even
 * when Meta echoes them back. Only the HTTP status and Meta's numeric error code are
 * kept. What the user sees, the number of requests and the invalidation of the code
 * do not change. Every value here is a placeholder.
 */
class SendFailureLoggingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '966551234567';

    private const TOKEN_LIKE = 'EAAGm0PX4ZCpsBAKZCZBfakefakefake0000';

    private const PHONE_NUMBER_ID = '1122334455';

    private const SEND_FAILED = 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.';

    private string $logFile;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    /** @var list<string> The codes found in the messages sent, kept in memory only. */
    private array $codes = [];

    private Closure $answer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'fake-env-token-9911',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        // The real formatter of the application log, written to a file of this test.
        $this->logFile = tempnam(sys_get_temp_dir(), 'anis-log-');
        config(['logging.channels.probe' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'], 'logging.default' => 'probe']);
        Log::forgetChannel('probe');
        $this->beforeApplicationDestroyed(fn () => @unlink($this->logFile));

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = [$event->message, $event->context];
        });

        // Meta "echoes" everything it was sent: the number in both forms, the code and a token-like string.
        $this->answer = fn () => Http::response(['error' => [
            'code' => 131030,
            'message' => 'Recipient +'.self::PHONE.' ('.self::PHONE.') not allowed; code '.end($this->codes).'; token '.self::TOKEN_LIKE.'; id '.self::PHONE_NUMBER_ID,
        ]], 400);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            // The code is kept in a property, never passed as an argument: frames of this
            // test must not put it in the stack trace under test.
            $this->codes[] = (string) data_get($request->data(), 'template.components.0.parameters.0.text');

            return ($this->answer)();
        }]);
    }

    // --- OTP codes ---------------------------------------------------------------------------------

    public function test_a_refused_code_logs_only_the_http_status_and_meta_code(): void
    {
        $patient = User::factory()->patient()->create(['phone' => self::PHONE]);

        $this->from(route('login'))
            ->post(route('login'), ['phone' => '+'.self::PHONE])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertCount(1, $this->codes);
        $this->assertContains(['WhatsApp: the code could not be sent.', ['http_status' => 400, 'meta_code' => 131030]], $this->logged);
        // The code is invalidated as before, so no usable code is left.
        $this->assertNull(OneTimeCode::latestFor($patient, CodePurpose::Login));
        $this->assertNotNull(OneTimeCode::where('user_id', $patient->id)->sole()->invalidated_at);
        $this->assertNothingSensitiveLogged($this->codes[0]);
    }

    public function test_a_refusal_without_a_meta_code_keeps_the_status_only(): void
    {
        $this->answer = fn () => Http::response('<html>bad gateway '.self::PHONE.'</html>', 502);
        User::factory()->patient()->create(['phone' => self::PHONE]);

        $this->post(route('login'), ['phone' => '+'.self::PHONE])->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertContains(['WhatsApp: the code could not be sent.', ['http_status' => 502, 'meta_code' => null]], $this->logged);
        $this->assertNothingSensitiveLogged($this->codes[0]);
    }

    public function test_a_lost_connection_logs_neither_the_message_nor_the_request_url(): void
    {
        $this->answer = fn () => throw new ConnectionException('cURL error 28: timed out for https://graph.facebook.com/v23.0/'.self::PHONE_NUMBER_ID.'/messages (code '.end($this->codes).')');
        $patient = User::factory()->patient()->create(['phone' => self::PHONE]);

        $this->post(route('login'), ['phone' => '+'.self::PHONE])->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertCount(1, $this->codes);
        $this->assertContains(['WhatsApp: the code could not be sent.', ['error' => 'connection_error']], $this->logged);
        // The code is invalidated as before, so no usable code is left.
        $this->assertNull(OneTimeCode::latestFor($patient, CodePurpose::Login));
        $this->assertNotNull(OneTimeCode::where('user_id', $patient->id)->sole()->invalidated_at);
        $this->assertNothingSensitiveLogged($this->codes[0], ['graph.facebook.com', 'cURL']);
    }

    public function test_blocked_sending_is_still_reported_without_any_value(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
        User::factory()->patient()->create(['phone' => self::PHONE]);

        $this->post(route('login'), ['phone' => '+'.self::PHONE])->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertSame([], $this->codes);
        $this->assertStringContainsString('WhatsApp sending is blocked (disabled)', (string) file_get_contents($this->logFile));
        $this->assertNothingSensitiveLogged(null);
    }

    // --- The stack trace -----------------------------------------------------------------------------

    public function test_the_stack_trace_never_shows_the_phone_number_or_the_code(): void
    {
        $previous = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];

        // As in php.ini-development: arguments in traces, strings cut at 15 characters.
        $this->assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
        $this->assertNotFalse(ini_set('zend.exception_string_param_max_len', '15'));

        try {
            $this->answer = fn () => throw new RuntimeException('unexpected');
            $user = User::factory()->patient()->create(['phone' => self::PHONE]);

            try {
                $user->notify(new LoginCodeNotification('482913'));
                $this->fail('The unexpected error was swallowed.');
            } catch (RuntimeException $exception) {
                $trace = $exception->getTraceAsString();
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous[0]);
            ini_set('zend.exception_string_param_max_len', (string) $previous[1]);
        }

        $this->assertStringContainsString('WhatsAppChannel->post(Object(App\Services\WhatsApp\WhatsAppConfig), Object(SensitiveParameterValue), Object(SensitiveParameterValue))', $trace);
        $this->assertStringNotContainsString(self::PHONE, $trace);
        $this->assertStringNotContainsString('482913', $trace);
    }

    // --- Test sends from the panel and the server -----------------------------------------------------

    public function test_a_test_send_from_the_server_logs_no_connection_detail(): void
    {
        $this->answer = fn () => throw new ConnectionException('cURL error 7: failed for https://graph.facebook.com/v23.0/'.self::PHONE_NUMBER_ID.'/messages');

        $status = Artisan::call('whatsapp:test', ['phone' => '+'.self::PHONE, '--confirm-production' => true, '--no-interaction' => true]);

        $this->assertSame(1, $status);
        $this->assertCount(1, $this->codes);
        $this->assertContains(['WhatsApp: the test code could not be sent.', ['error' => 'connection_error']], $this->logged);
        $this->assertSame('uncertain', WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestAttempted)->sole()->changes['send_status']);
        $this->assertNothingSensitiveLogged($this->codes[0], ['graph.facebook.com', 'cURL']);
    }

    public function test_a_test_send_refused_by_meta_is_not_logged_and_is_audited_as_before(): void
    {
        $status = Artisan::call('whatsapp:test', ['phone' => '+'.self::PHONE, '--confirm-production' => true, '--no-interaction' => true]);

        $this->assertSame(1, $status);
        $this->assertSame('131030', WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestAttempted)->sole()->changes['error_code']);
        $this->assertNothingSensitiveLogged($this->codes[0]);
    }

    public function test_an_unexpected_test_send_error_is_still_reported_without_the_phone_or_the_code(): void
    {
        Exceptions::fake();
        $previous = [ini_get('zend.exception_ignore_args'), ini_get('zend.exception_string_param_max_len')];
        $this->assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
        $this->assertNotFalse(ini_set('zend.exception_string_param_max_len', '15'));

        try {
            $this->answer = fn () => throw new RuntimeException('unexpected');

            Artisan::call('whatsapp:test', ['phone' => '+'.self::PHONE, '--confirm-production' => true, '--no-interaction' => true]);

            // Traces are written out with the settings in force when they are read.
            Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getTraceAsString(), "'legacy-env'")
                && ! str_contains($exception->getTraceAsString(), self::PHONE)
                && ! str_contains($exception->getTraceAsString(), $this->codes[0])
                && str_contains($exception->getTraceAsString(), 'SensitiveParameterValue'));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous[0]);
            ini_set('zend.exception_string_param_max_len', (string) $previous[1]);
        }
    }

    // --- Helpers --------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $more  Other fragments that must not appear.
     */
    private function assertNothingSensitiveLogged(?string $code, array $more = []): void
    {
        $file = (string) file_get_contents($this->logFile);
        $events = json_encode($this->logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach (array_filter([self::PHONE, self::TOKEN_LIKE, self::PHONE_NUMBER_ID, 'fake-env-token-9911', 'not allowed', $code, ...$more]) as $value) {
            $this->assertStringNotContainsString($value, $file, 'log file: '.$value);
            $this->assertStringNotContainsString($value, (string) $events, 'log events: '.$value);
        }
    }
}
