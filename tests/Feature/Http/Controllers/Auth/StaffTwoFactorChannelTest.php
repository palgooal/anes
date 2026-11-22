<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\CodeChannel;
use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Notifications\LoginCodeNotification;
use App\Notifications\TwoFactorCodeMail;
use App\WhatsAppMode;
use App\WhatsAppSendStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DEC-AUTH-009 in docs/ANIS-AUTH-OTP-001.md: the staff two-factor code goes by email
 * while WhatsApp cannot really send, the channel is chosen before the code exists and
 * kept for the whole code screen, and nothing changes for beneficiaries.
 * No request reaches Meta and no message leaves the machine.
 */
class StaffTwoFactorChannelTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MESSAGES_URL = 'graph.facebook.com/v23.0/1122334455/messages';

    private const WHATSAPP_FAILED = 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.';

    private const MAIL_FAILED = 'تعذّر إرسال الرمز إلى بريدك الإلكتروني الآن. حاول بعد قليل، وإن تكرر ذلك تواصل مع الإدارة.';

    private const NO_CHANNEL = 'تعذّر إرسال رمز التحقق الآن: لا تتوفر قناة إرسال. تواصل مع الإدارة.';

    /** What the fake Meta answers: 'accept', 'reject' or 'timeout'. */
    private string $meta = 'accept';

    /** Calls the fake Meta received (a thrown timeout is not recorded by Http::assertSentCount). */
    private int $metaCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([self::MESSAGES_URL => function (Request $request) {
            $this->metaCalls++;

            return match ($this->meta) {
                'accept' => Http::response(['messages' => [['id' => 'wamid.1']]]),
                'reject' => Http::response(['error' => ['message' => 'invalid parameter']], 400),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
            };
        }]);
    }

    /**
     * Production as the code path sees it. CSRF checking is skipped only while running
     * unit tests, which production is not, so it is turned off here for the request.
     */
    private function inProduction(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function whatsAppSendsToMeta(): void
    {
        config(['services.whatsapp' => [
            'driver' => 'meta',
            'number' => '966539564429',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-test-token',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);
    }

    private function signIn(User $user): TestResponse
    {
        return $this->post(route('staff.login'), ['email' => $user->email, 'password' => 'password']);
    }

    private function mailedCode(User $user): string
    {
        return Notification::sent($user, TwoFactorCodeMail::class)->last()->code;
    }

    // --- 1. email when WhatsApp does not really send -------------------------

    public function test_log_driver_sends_the_staff_code_by_email_and_the_code_signs_in(): void
    {
        Notification::fake();
        $guide = User::factory()->create();

        $this->signIn($guide)->assertRedirect(route('staff.verify'));

        Notification::assertSentToTimes($guide, TwoFactorCodeMail::class, 1);
        Notification::assertNotSentTo($guide, LoginCodeNotification::class);
        $this->assertSame(CodeChannel::Mail->value, session('auth.pending.channel'));
        $this->assertSame(0, WhatsAppMessageLog::count());
        Http::assertNothingSent();

        $this->post(route('staff.verify'), ['code' => $this->mailedCode($guide)])->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($guide);
    }

    /**
     * @return array<string, array{WhatsAppMode, bool}>
     */
    public static function blockedSettings(): array
    {
        return [
            'disabled' => [WhatsAppMode::Disabled, true],
            'log mode in the panel' => [WhatsAppMode::Log, true],
            'test environment incomplete' => [WhatsAppMode::Test, false],
        ];
    }

    #[DataProvider('blockedSettings')]
    public function test_blocked_or_log_database_settings_send_the_staff_code_by_email(WhatsAppMode $mode, bool $complete): void
    {
        Notification::fake();
        $factory = WhatsAppSetting::factory()->mode($mode);
        $setting = $complete ? $factory->create() : $factory->create(['test_access_token' => null]);
        $this->assertTrue($setting->isDatabaseSource());
        $guide = User::factory()->create();

        $this->signIn($guide)->assertRedirect(route('staff.verify'));

        Notification::assertSentTo($guide, TwoFactorCodeMail::class);
        Notification::assertNotSentTo($guide, LoginCodeNotification::class);
    }

    // --- 2. WhatsApp when it really sends, and no email ----------------------

    public function test_whatsapp_that_sends_to_meta_carries_the_staff_code_and_no_email_is_sent(): void
    {
        $this->whatsAppSendsToMeta();
        Notification::fake();
        $guide = User::factory()->create();

        $this->signIn($guide)->assertRedirect(route('staff.verify'));

        Notification::assertSentTo($guide, LoginCodeNotification::class);
        Notification::assertNotSentTo($guide, TwoFactorCodeMail::class);
        $this->assertSame(CodeChannel::WhatsApp->value, session('auth.pending.channel'));
    }

    // --- 3. no automatic switch to email after an attempt at Meta ------------

    /**
     * @return array<string, array{string, WhatsAppSendStatus}>
     */
    public static function metaFailures(): array
    {
        return [
            'rejected (4xx)' => ['reject', WhatsAppSendStatus::Rejected],
            'uncertain (timeout)' => ['timeout', WhatsAppSendStatus::Uncertain],
        ];
    }

    #[DataProvider('metaFailures')]
    public function test_a_failed_attempt_at_meta_never_falls_back_to_email(string $meta, WhatsAppSendStatus $status): void
    {
        $this->whatsAppSendsToMeta();
        $this->meta = $meta;
        Mail::fake();
        $guide = User::factory()->create();

        $this->from(route('staff.login'))->signIn($guide)
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors(['send' => self::WHATSAPP_FAILED]);

        Mail::assertNothingOutgoing();
        $this->assertSame(1, $this->metaCalls);
        $code = OneTimeCode::sole();
        $this->assertSame($status->value, $code->invalidation_reason);
        $this->assertNull(session('auth.pending'));
        $this->assertGuest();
    }

    // --- 4. the channel is kept for the whole code screen --------------------

    public function test_a_resend_keeps_email_even_after_whatsapp_becomes_ready(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide)->assertRedirect(route('staff.verify'));

        $this->whatsAppSendsToMeta();
        $this->travel(61)->seconds();
        $this->post(route('staff.resend'))->assertRedirect(route('staff.verify'))->assertSessionHas('status');

        Notification::assertSentToTimes($guide, TwoFactorCodeMail::class, 2);
        Notification::assertNotSentTo($guide, LoginCodeNotification::class);
        Http::assertNothingSent();
        $this->assertSame(CodeChannel::Mail->value, session('auth.pending.channel'));
    }

    public function test_a_resend_never_moves_a_whatsapp_screen_to_email_when_whatsapp_stops(): void
    {
        $this->whatsAppSendsToMeta();
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide)->assertRedirect(route('staff.verify'));
        $issued = OneTimeCode::sole()->id;

        config(['services.whatsapp.driver' => 'log']);
        $this->travel(61)->seconds();
        $this->post(route('staff.resend'))
            ->assertRedirect(route('staff.verify'))
            ->assertSessionHasErrors(['send' => 'قناة إرسال الرمز غير متاحة الآن. حاول بعد قليل، أو سجّل الدخول من جديد.']);

        Notification::assertNotSentTo($guide, TwoFactorCodeMail::class);
        Notification::assertSentToTimes($guide, LoginCodeNotification::class, 1);
        $this->assertSame($issued, OneTimeCode::sole()->id);
    }

    public function test_a_session_from_before_the_change_is_never_sent_a_code_on_another_channel(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->withSession(['auth.pending' => ['user_id' => $guide->id, 'purpose' => CodePurpose::TwoFactor->value, 'remember' => false]]);

        $this->get(route('staff.verify'))->assertOk()->assertSee('على واتساب');

        $this->post(route('staff.resend'))
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors(['send' => 'انتهت جلسة التحقق. سجّل الدخول من جديد.']);

        Notification::assertNothingSent();
        $this->assertSame(0, OneTimeCode::count());
        $this->assertNull(session('auth.pending'));
    }

    public function test_only_the_last_code_works_across_resends_and_the_60_second_interval_holds(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide);
        $first = $this->mailedCode($guide);

        $this->post(route('staff.resend'))->assertRedirect(route('staff.verify'));
        Notification::assertSentToTimes($guide, TwoFactorCodeMail::class, 1);

        $this->travel(61)->seconds();
        $this->post(route('staff.resend'));
        $second = $this->mailedCode($guide);
        $this->assertSame(1, OneTimeCode::count());

        if ($first !== $second) {
            $this->post(route('staff.verify'), ['code' => $first])->assertSessionHasErrors('code');
        }
        $this->post(route('staff.verify'), ['code' => $second])->assertRedirect(route('staff.dashboard'));
    }

    // --- 5. production refuses fake mail transports --------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function fakeProductionMailers(): array
    {
        return [
            'log' => [['mail.default' => 'log']],
            'array' => [['mail.default' => 'array']],
            'smtp without host' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null]],
            'failover through log' => [['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']]],
            'no sender address' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.from.address' => null]],
        ];
    }

    #[DataProvider('fakeProductionMailers')]
    public function test_production_refuses_the_code_when_no_real_channel_exists(array $mail): void
    {
        $this->inProduction();
        config($mail);
        Notification::fake();
        $guide = User::factory()->create();

        $this->from(route('staff.login'))->signIn($guide)
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors(['send' => self::NO_CHANNEL]);

        Notification::assertNothingSent();
        $this->assertSame(0, OneTimeCode::count());
        $this->assertNull(session('auth.pending'));
        $this->assertGuest();
    }

    public function test_production_uses_a_configured_smtp_mailer(): void
    {
        $this->inProduction();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'no-reply@example.com']);
        Notification::fake();
        $guide = User::factory()->create();

        $this->signIn($guide)->assertRedirect(route('staff.verify'));

        Notification::assertSentTo($guide, TwoFactorCodeMail::class);
    }

    // --- 6 and 9. an SMTP failure invalidates the code and logs no secret ----

    public function test_an_smtp_failure_invalidates_the_code_and_logs_neither_the_code_nor_the_address(): void
    {
        $logFile = storage_path('logs/two-factor-mail-test-'.bin2hex(random_bytes(4)).'.log');
        config([
            'logging.channels.two_factor_test' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug'],
            'logging.default' => 'two_factor_test',
            // Port 9 refuses at once: a real transport error, and nothing leaves the machine.
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 9,
            'mail.mailers.smtp.timeout' => 2,
        ]);
        $captured = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$captured) {
            preg_match('/\b(\d{6})\b/', (string) $event->message->getTextBody().' '.$event->message->getHtmlBody(), $m);
            $captured = $m[1] ?? null;
        });
        $guide = User::factory()->create(['email' => 'guide.secret.address@example.com']);

        try {
            $this->from(route('staff.login'))->signIn($guide)
                ->assertRedirect(route('staff.login'))
                ->assertSessionHasErrors(['send' => self::MAIL_FAILED]);

            $code = OneTimeCode::sole();
            $this->assertSame(OneTimeCode::MAIL_FAILED, $code->invalidation_reason);
            $this->assertNotNull($code->invalidated_at);
            $this->assertNotNull($captured, 'the message was built with a 6-digit code');

            $log = (string) @file_get_contents($logFile);
            $this->assertStringContainsString('Two-factor code: the email could not be sent.', $log);
            $this->assertStringNotContainsString($captured, $log);
            $this->assertStringNotContainsString('guide.secret.address', $log);
            $this->assertStringNotContainsString('password', strtolower($log));
            $this->assertGuest();
        } finally {
            @unlink($logFile);
        }
    }

    // --- 7. validity, attempts and single use are unchanged ------------------

    public function test_mailed_code_expires_after_five_minutes(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide);

        $this->travel(301)->seconds();
        $this->post(route('staff.verify'), ['code' => $this->mailedCode($guide)])
            ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        $this->assertGuest();
    }

    public function test_five_wrong_mailed_codes_lock_the_account(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide);
        $wrong = $this->mailedCode($guide) === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('staff.verify'), ['code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->post(route('staff.verify'), ['code' => $wrong])->assertViewIs('auth.locked');
        $this->post(route('staff.verify'), ['code' => $this->mailedCode($guide)])->assertViewIs('auth.locked');
        $this->assertGuest();
    }

    public function test_a_mailed_code_works_only_once(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->signIn($guide);
        $code = $this->mailedCode($guide);

        $this->post(route('staff.verify'), ['code' => $code])->assertRedirect(route('staff.dashboard'));
        $this->post(route('logout'));

        $this->withSession(['auth.pending' => ['user_id' => $guide->id, 'purpose' => CodePurpose::TwoFactor->value, 'remember' => false, 'channel' => 'mail']]);
        $this->post(route('staff.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    // --- 8. the masked destination -------------------------------------------

    public function test_the_code_screen_shows_the_masked_email_for_email_and_the_phone_ending_for_whatsapp(): void
    {
        Notification::fake();
        $guide = User::factory()->create(['email' => 'hazem.test@example.com']);
        $this->signIn($guide);

        $this->get(route('staff.verify'))
            ->assertOk()
            ->assertSee('بريدك الإلكتروني')
            ->assertSee('h•••@example.com')
            ->assertDontSee('hazem.test@example.com')
            ->assertDontSee('على واتساب');

        $this->post(route('logout'));
        $this->flushSession();
        $this->whatsAppSendsToMeta();
        $other = User::factory()->create();
        $this->signIn($other);

        $this->get(route('staff.verify'))
            ->assertOk()
            ->assertSee('على واتساب')
            ->assertSee('••'.substr((string) $other->phone, -2))
            ->assertDontSee('بريدك الإلكتروني');
    }

    // --- 10. beneficiaries are unchanged -------------------------------------

    public function test_beneficiaries_still_get_their_code_on_whatsapp_with_the_log_driver(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone])->assertRedirect(route('login.verify'));

        Notification::assertSentTo($patient, LoginCodeNotification::class);
        Notification::assertNotSentTo($patient, TwoFactorCodeMail::class);
        $this->assertArrayNotHasKey('channel', session('auth.pending'));

        $code = Notification::sent($patient, LoginCodeNotification::class)->last()->code;
        $this->post(route('login.verify'), ['code' => $code])->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($patient);
    }

    public function test_beneficiaries_get_no_email_even_when_whatsapp_is_blocked(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        Mail::fake();
        $patient = User::factory()->patient()->create();

        $this->from(route('login'))->post(route('login'), ['phone' => $patient->phone])
            ->assertSessionHasErrors(['send' => self::WHATSAPP_FAILED]);

        Mail::assertNothingOutgoing();
        $this->assertSame(WhatsAppSendStatus::Blocked->value, OneTimeCode::sole()->invalidation_reason);
    }
}
