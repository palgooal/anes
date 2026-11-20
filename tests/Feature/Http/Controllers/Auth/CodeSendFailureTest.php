<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Notifications\LoginCodeNotification;
use App\WhatsAppMode;
use App\WhatsAppSendStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-2 of docs/ANIS-WHATSAPP-SETTINGS-001.md: fail-safe OTP sending
 * (AC-WA-16, AC-WA-19, AC-WA-20, and AC-WA-17 partially). No request ever reaches Meta.
 *
 * AC-WA-17 is covered on the resend path only (/login/resend, /staff/resend). The first
 * request from /login and /staff/login has no 60-second interval today: that is
 * GAP-AUTH-17 / VER-AUTH-09 in docs/ANIS-AUTH-OTP-001.md, not authorised and not tested here.
 */
class CodeSendFailureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SEND_FAILED = 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.';

    private const MESSAGES_URL = 'graph.facebook.com/v23.0/1122334455/messages';

    /** Plain codes captured from the (fake) requests to Meta, in order. */
    private array $sentCodes = [];

    /** What the fake Meta answers next: 'accept', 'reject', 'server-error', 'timeout' or 'crash'. */
    private string $meta = 'accept';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-test-token',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        Http::preventStrayRequests();
        Http::fake([self::MESSAGES_URL => function (Request $request) {
            $this->sentCodes[] = $request['template']['components'][0]['parameters'][0]['text'];

            return match ($this->meta) {
                'accept' => Http::response(['messages' => [['id' => 'wamid.1']]]),
                'reject' => Http::response(['error' => ['message' => 'invalid parameter']], 400),
                'server-error' => Http::response(['error' => ['message' => 'service unavailable']], 503),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                'crash' => throw new RuntimeException('unexpected'),
            };
        }]);
    }

    /**
     * @return array<string, array{string, WhatsAppSendStatus}>
     */
    public static function failures(): array
    {
        return [
            'rejected (4xx)' => ['reject', WhatsAppSendStatus::Rejected],
            'uncertain (5xx)' => ['server-error', WhatsAppSendStatus::Uncertain],
            'uncertain (timeout)' => ['timeout', WhatsAppSendStatus::Uncertain],
        ];
    }

    // --- AC-WA-16: every failure invalidates the code ------------------------

    #[DataProvider('failures')]
    public function test_failed_first_send_invalidates_the_code_and_shows_the_current_message(string $meta, WhatsAppSendStatus $status): void
    {
        $this->meta = $meta;
        $patient = User::factory()->patient()->create();

        $this->from(route('login'))
            ->post(route('login'), ['phone' => $patient->phone])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertInvalidated($patient, CodePurpose::Login, $status);
        $this->assertGuest();
    }

    public function test_blocked_send_invalidates_the_code_without_calling_meta(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $patient = User::factory()->patient()->create();

        $this->from(route('login'))
            ->post(route('login'), ['phone' => $patient->phone])
            ->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertInvalidated($patient, CodePurpose::Login, WhatsAppSendStatus::Blocked);
        Http::assertNothingSent();
    }

    #[DataProvider('failures')]
    public function test_code_from_a_failed_resend_is_refused_even_if_it_arrives(string $meta, WhatsAppSendStatus $status): void
    {
        $patient = $this->beneficiaryOnCodeScreen();
        $firstCode = $this->sentCodes[0];

        $this->travel(61)->seconds();
        $this->meta = $meta;
        $this->post(route('login.resend'))
            ->assertRedirect(route('login.verify'))
            ->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertInvalidated($patient, CodePurpose::Login, $status);

        // The code of the failed request (which may still reach the phone) and the earlier code are both refused.
        foreach ([$this->sentCodes[1], $firstCode] as $code) {
            $this->from(route('login.verify'))
                ->post(route('login.verify'), ['code' => $code])
                ->assertRedirect(route('login.verify'))
                ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        }

        $this->assertGuest();
    }

    public function test_unexpected_send_errors_still_invalidate_the_code(): void
    {
        $this->meta = 'crash';
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone])->assertServerError();

        $this->assertInvalidated($patient, CodePurpose::Login, WhatsAppSendStatus::Uncertain);
    }

    public function test_accepted_code_is_not_invalidated_and_signs_in(): void
    {
        $patient = $this->beneficiaryOnCodeScreen();

        $this->assertNull(OneTimeCode::latestFor($patient, CodePurpose::Login)->invalidated_at);

        $this->post(route('login.verify'), ['code' => $this->sentCodes[0]])->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($patient);
    }

    // --- AC-WA-17: the failed attempt counts for the resend interval ---------

    public function test_failed_resend_counts_for_the_one_minute_interval(): void
    {
        $patient = $this->beneficiaryOnCodeScreen();

        $this->travel(61)->seconds();
        $this->meta = 'timeout';
        $this->post(route('login.resend'))->assertSessionHasErrors('send');
        $this->assertCount(2, $this->sentCodes);

        // Right after the failure: no new request reaches Meta, and the screen shows the countdown.
        $this->meta = 'accept';
        $this->post(route('login.resend'))->assertRedirect(route('login.verify'))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->sentCodes);
        $this->assertGreaterThan(0, $this->get(route('login.verify'))->viewData('resendIn'));

        $this->travel(61)->seconds();

        $this->post(route('login.resend'))->assertRedirect(route('login.verify'))->assertSessionHas('status');
        $this->assertCount(3, $this->sentCodes);
        $this->post(route('login.verify'), ['code' => $this->sentCodes[2]])->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($patient);
    }

    // --- AC-WA-19: same policy for the staff two-factor code -----------------

    #[DataProvider('failures')]
    public function test_staff_two_factor_failure_invalidates_the_code(string $meta, WhatsAppSendStatus $status): void
    {
        $this->meta = $meta;
        $guide = User::factory()->create();

        $this->from(route('staff.login'))
            ->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors(['send' => self::SEND_FAILED]);

        $this->assertInvalidated($guide, CodePurpose::TwoFactor, $status);
        $this->assertGuest();
    }

    public function test_staff_code_from_a_failed_resend_is_refused_and_the_interval_applies(): void
    {
        $guide = User::factory()->create();
        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.verify'));

        $this->travel(61)->seconds();
        $this->meta = 'server-error';
        $this->post(route('staff.resend'))->assertSessionHasErrors(['send' => self::SEND_FAILED]);
        $this->assertInvalidated($guide, CodePurpose::TwoFactor, WhatsAppSendStatus::Uncertain);

        $this->post(route('staff.verify'), ['code' => $this->sentCodes[1]])
            ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        $this->assertGuest();

        $this->meta = 'accept';
        $this->post(route('staff.resend'));
        $this->assertCount(2, $this->sentCodes);
    }

    // --- AC-WA-20: the log driver hides the code outside local and testing ---

    /**
     * @return array<string, array{string, string}>
     */
    public static function environments(): array
    {
        return [
            'local' => ['local', '123456'],
            'testing' => ['testing', '123456'],
            'staging' => ['staging', '****'],
            'production' => ['production', '****'],
        ];
    }

    #[DataProvider('environments')]
    public function test_log_driver_shows_the_code_only_locally_and_in_tests(string $environment, string $logged): void
    {
        config(['services.whatsapp.driver' => 'log']);
        $this->app->detectEnvironment(fn () => $environment);
        Log::spy();

        User::factory()->patient()->create(['phone' => '966551234567'])->notify(new LoginCodeNotification('123456'));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context === ['to' => '966551234567', 'code' => $logged]);
        Http::assertNothingSent();
    }

    // --- Unchanged behaviour --------------------------------------------------

    public function test_the_meta_request_format_and_code_lifetime_are_unchanged(): void
    {
        $patient = $this->beneficiaryOnCodeScreen();
        $code = OneTimeCode::latestFor($patient, CodePurpose::Login);

        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $code->expires_at->timestamp, 2);
        Http::assertSent(fn (Request $request) => $request['template']['components'] === [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $this->sentCodes[0]]]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $this->sentCodes[0]]]],
        ]);
    }

    private function beneficiaryOnCodeScreen(): User
    {
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone])->assertRedirect(route('login.verify'));
        $this->assertCount(1, $this->sentCodes);

        return $patient;
    }

    private function assertInvalidated(User $user, CodePurpose $purpose, WhatsAppSendStatus $reason): void
    {
        $latest = OneTimeCode::latestIssuedFor($user, $purpose);

        $this->assertNotNull($latest);
        $this->assertNotNull($latest->invalidated_at);
        $this->assertSame($reason->value, $latest->invalidation_reason);
        $this->assertNull(OneTimeCode::latestFor($user, $purpose));
    }
}
