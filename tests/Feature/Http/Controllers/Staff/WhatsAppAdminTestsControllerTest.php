<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\WhatsAppSendTester;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMessagePurpose;
use App\WhatsAppMode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-5 of docs/ANIS-WHATSAPP-SETTINGS-001.md: the administrator's connection
 * and send tests (REQ-WA-35 to REQ-WA-38, AC-WA-21 to AC-WA-24, AC-WA-43, AC-WA-44).
 * Tokens are placeholders; no request ever reaches Meta.
 */
class WhatsAppAdminTestsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '+966551234567';

    private const PHONE_DIGITS = '966551234567';

    private const CODE = '135790';

    /** Fake Meta answers, per call. */
    private array $meta = [
        'debug_token' => 'valid',
        'phone' => 'ok',
        'templates' => 'approved',
        'messages' => 'accept',
    ];

    /** @var list<Request> */
    private array $messageRequests = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        AdminTestCodes::generateUsing(fn () => self::CODE);
        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/messages')) {
                $this->messageRequests[] = $request;

                return match ($this->meta['messages']) {
                    'accept' => Http::response(['messages' => [['id' => 'wamid.PANEL'.count($this->messageRequests)]]]),
                    'reject' => Http::response(['error' => ['message' => '(#131030) Recipient '.self::PHONE_DIGITS.' not in allowed list', 'code' => 131030]], 400),
                    'server-error' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2]], 503),
                    'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                };
            }

            if (str_ends_with($path, '/debug_token')) {
                return match ($this->meta['debug_token']) {
                    'valid' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]),
                    'invalid' => Http::response(['data' => ['is_valid' => false, 'scopes' => []]]),
                    'unreachable' => throw new ConnectionException('cURL error 7 for '.$request->url()),
                };
            }

            if (str_ends_with($path, '/message_templates')) {
                return match ($this->meta['templates']) {
                    'approved' => Http::response(['data' => [
                        ['name' => 'login_code', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION'],
                        ['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION'],
                    ]]),
                    'pending' => Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'PENDING', 'category' => 'AUTHENTICATION']]]),
                    'missing' => Http::response(['data' => [['name' => 'login_code_old', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION']]]),
                };
            }

            return match ($this->meta['phone']) {
                'ok' => Http::response(['display_phone_number' => '+1 555-000-0001', 'verified_name' => 'ANIS', 'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED', 'id' => '100000000000001']),
                'refused' => Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- AC-WA-21: connection test --------------------------------------------------------

    public function test_the_connection_test_checks_token_number_and_template_without_sending_or_a_password(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.connection-test', 'test'))
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHas('connection_test', fn (array $result) => $result['environment'] === 'test'
                && collect($result['checks'])->map(fn (array $check) => $check['status'])->all() === ['token' => 'passed', 'phone_number' => 'passed', 'template' => 'passed']
                && $result['checks']['phone_number']['details']['matches_saved_display_number'] === 'yes');

        $this->assertSame([], $this->messageRequests);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '200000000000001/message_templates') && $request['name'] === 'login_code');
        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(0, WhatsAppMessageLog::count());

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::ConnectionTested, $audit->action);
        $this->assertSame(WhatsAppEnvironment::Test, $audit->environment);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('passed', $audit->changes['token']['status']);
        $this->assertSame('never', $audit->changes['token']['expiry']);
    }

    public function test_the_connection_test_reports_each_failure_on_its_own(): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta = ['debug_token' => 'invalid', 'phone' => 'refused', 'templates' => 'pending', 'messages' => 'accept'];

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.whatsapp.connection-test', 'production'))
            ->assertSessionHas('connection_test', fn (array $result) => collect($result['checks'])->map(fn (array $check) => $check['status'])->all() === ['token' => 'failed', 'phone_number' => 'failed', 'template' => 'failed']
                && $result['checks']['template']['details']['status'] === 'PENDING');

        $this->meta['templates'] = 'missing';
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.whatsapp.connection-test', 'production'))
            ->assertSessionHas('connection_test', fn (array $result) => $result['checks']['template']['message'] === 'لا يوجد قالب بهذا الاسم وهذه اللغة.');

        $this->assertSame([], $this->messageRequests);
    }

    public function test_checks_without_the_fields_they_need_are_skipped(): void
    {
        WhatsAppSetting::factory()->create(['test_waba_id' => null, 'test_access_token' => null]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.whatsapp.connection-test', 'test'))
            ->assertSessionHas('connection_test', fn (array $result) => collect($result['checks'])->pluck('status')->unique()->all() === ['skipped']);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_meta_leaks_no_token(): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta['debug_token'] = 'unreachable';

        $response = $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.connection-test', 'test'));
        $page = $this->get(route('staff.whatsapp'))->assertOk()->assertSee('تعذّر فحص الرمز لدى Meta.');

        $everything = json_encode([session()->all(), WhatsAppSettingsAudit::all()->toArray(), $this->logged]).$page->getContent().$response->getContent();
        $this->assertStringNotContainsString('fake-test-token-0001', $everything);
    }

    public function test_the_connection_test_needs_the_settings_to_be_managed_from_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.whatsapp.connection-test', 'test'))
            ->assertSessionHasErrors('whatsapp');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    // --- AC-WA-44, AC-WA-26: review, then a recent password, then send ---------------------

    public function test_the_review_shows_the_environment_and_full_number_and_sends_nothing(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test.review', 'production'), ['phone' => '0551234567'])
            ->assertOk()
            ->assertSee('بيئة الإنتاج')
            ->assertSee('+966 55 123 4567')
            ->assertSee('value="'.self::PHONE_DIGITS.'"', false)
            ->assertSee('ستُرسل الرسالة من رقم الإنتاج الرسمي.')
            ->assertSee(route('staff.whatsapp.send-test', 'production'), false);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
        $this->assertNull(app(AdminTestCodes::class)->pending(WhatsAppSendTester::panelKey($admin)));
    }

    public function test_review_and_send_need_a_password_confirmed_within_15_minutes(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach (['staff.whatsapp.send-test.review', 'staff.whatsapp.send-test'] as $route) {
            $this->actingAs($admin)
                ->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
                ->post(route($route, 'test'), ['phone' => self::PHONE])
                ->assertRedirect(route('password.confirm'));
        }

        Http::assertNothingSent();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
            ->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->messageRequests);
    }

    public function test_an_invalid_number_is_refused_without_repeating_it(): void
    {
        WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()
            ->post(route('staff.whatsapp.send-test.review', 'test'), ['phone' => '12345'])
            ->assertSessionHasErrors(['phone' => 'رقم الجوال غير صحيح، أو دولته غير مسموحة.'], errorBag: 'send_test_test');

        Http::assertNothingSent();
    }

    // --- REQ-WA-36, REQ-WA-37: the send itself ---------------------------------------------

    public function test_a_send_test_uses_the_chosen_environment_whatever_the_mode_and_changes_nothing(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test', 'production'), ['phone' => self::PHONE])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'هذا لا يعني وصول الرسالة') && str_contains($status, '••••4567'));

        $request = $this->messageRequests[0];
        $this->assertStringContainsString('100000000000002/messages', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer fake-production-token-0002'));
        $this->assertSame(self::PHONE_DIGITS, $request['to']);
        $this->assertSame(self::CODE, $request['template']['components'][0]['parameters'][0]['text']);

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(0, OneTimeCode::count());

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppMessagePurpose::AdminTest, $log->purpose);
        $this->assertSame(WhatsAppEnvironment::Production, $log->environment);
        $this->assertNull($log->user_id);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SendTestAttempted, $audit->action);
        $this->assertSame(['origin' => 'panel', 'source' => 'database', 'recipient_last4' => '4567', 'send_status' => 'accepted'], $audit->changes);
        $this->assertSame($admin->id, $audit->user_id);
    }

    public function test_the_page_asks_for_the_received_code_without_showing_it(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);

        $this->actingAs($admin)
            ->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('تأكيد استلام رمز الاختبار')
            ->assertSee('قبول Meta للطلب لا يعني وصول الرسالة')
            ->assertSee('4567')
            ->assertDontSee(self::CODE)
            ->assertDontSee(self::PHONE_DIGITS);
    }

    public function test_an_incomplete_environment_is_refused_before_sending_and_not_counted(): void
    {
        WhatsAppSetting::factory()->create(['test_template_name' => null]);

        $this->asConfirmedAdmin()
            ->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])
            ->assertSessionHasErrors(['phone' => 'إعدادات بيئة الاختبار ناقصة: اسم القالب.'], errorBag: 'send_test_test');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessageLog::count());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'refused (4xx)' => ['reject', 'rejected'],
            'server error (5xx)' => ['server-error', 'uncertain'],
            'timeout' => ['timeout', 'uncertain'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_send_meta_did_not_accept_cancels_the_code(string $meta, string $status): void
    {
        WhatsAppSetting::factory()->create();
        $this->meta['messages'] = $meta;
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])
            ->assertSessionHasErrors('phone', errorBag: 'send_test_test');

        $this->assertSame($status, WhatsAppSettingsAudit::sole()->changes['send_status']);
        $this->assertSame($status, WhatsAppMessageLog::sole()->send_status->value);

        // Even the right code is refused afterwards.
        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors(['code' => 'لا يوجد رمز اختبار صالح: انتهت مدته أو لم يُرسل. أرسل اختباراً جديداً.'], errorBag: 'verify_send_test');
    }

    // --- AC-WA-23, AC-WA-43: confirming the code ------------------------------------------

    public function test_the_right_code_confirms_receipt_once(): void
    {
        $admin = $this->adminWithSentTest();

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'تأكد استلام رمز الاختبار'));

        $verification = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestVerification)->sole();
        $this->assertSame(['origin' => 'panel', 'recipient_last4' => '4567', 'outcome' => 'verified'], $verification->changes);
        $this->assertSame(WhatsAppEnvironment::Test, $verification->environment);

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test');
    }

    public function test_four_wrong_codes_still_allow_the_right_one(): void
    {
        $admin = $this->adminWithSentTest();

        for ($i = 4; $i >= 1; $i--) {
            $this->actingAs($admin)
                ->post(route('staff.whatsapp.send-test.verify'), ['code' => '000000'])
                ->assertSessionHasErrors(['code' => 'الرمز غير صحيح. المحاولات المتبقية: '.$i.'.'], errorBag: 'verify_send_test');
        }

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasNoErrors();
    }

    public function test_five_wrong_codes_cancel_the_test_even_for_the_right_code(): void
    {
        $admin = $this->adminWithSentTest();

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => '000000']);
        }

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => '000000'])
            ->assertSessionHasErrors(['code' => 'استُنفدت محاولات التحقق وأُلغي رمز الاختبار. أرسل اختباراً جديداً.'], errorBag: 'verify_send_test');

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test');

        $outcomes = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestVerification)->get()->map(fn ($audit) => $audit->changes['outcome'])->all();
        $this->assertSame(['wrong', 'wrong', 'wrong', 'wrong', 'exhausted'], $outcomes);
    }

    public function test_the_code_expires_after_5_minutes(): void
    {
        $admin = $this->adminWithSentTest();

        $this->travel(5 * 60 + 1)->seconds();

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test')
            ->assertSessionMissing('status');
    }

    public function test_the_code_is_still_valid_just_before_5_minutes(): void
    {
        $admin = $this->adminWithSentTest();

        $this->travel(4 * 60 + 50)->seconds();

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasNoErrors();
    }

    public function test_a_new_send_replaces_the_earlier_code(): void
    {
        $admin = $this->adminWithSentTest();
        AdminTestCodes::generateUsing(fn () => '864200');
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test');

        $this->actingAs($admin)
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => '864200'])
            ->assertSessionHasNoErrors();
    }

    public function test_one_administrator_cannot_confirm_another_s_code(): void
    {
        $this->adminWithSentTest();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test');
    }

    // --- AC-WA-24: 5 sends an hour per administrator ----------------------------------------

    public function test_the_sixth_send_of_the_hour_is_refused_whatever_meta_answered(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->meta['messages'] = 'reject';

        for ($i = 0; $i < 5; $i++) {
            $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        }

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test.review', 'production'), ['phone' => self::PHONE])
            ->assertSessionHasErrors('phone', errorBag: 'send_test_production');

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test', 'production'), ['phone' => self::PHONE])
            ->assertSessionHasErrors(['phone' => 'بلغت حد 5 اختبارات إرسال في الساعة. حاول بعد 60 دقيقة.'], errorBag: 'send_test_production');

        $this->assertCount(5, $this->messageRequests);
        $this->assertSame(5, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestAttempted)->count());

        // Another administrator has their own limit.
        $this->meta['messages'] = 'accept';
        $this->asConfirmedAdmin()->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])->assertSessionHasNoErrors();
        $this->assertCount(6, $this->messageRequests);
    }

    public function test_refusals_before_the_send_are_not_counted(): void
    {
        WhatsAppSetting::factory()->create(['production_access_token' => null]);
        $admin = User::factory()->admin()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'production'), ['phone' => self::PHONE]);
            $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => '123']);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])->assertSessionHasNoErrors();
        }

        $this->assertCount(5, $this->messageRequests);
    }

    // --- AC-WA-22, AC-WA-44: nothing sensitive is kept -----------------------------------------

    public function test_no_code_token_or_full_number_reaches_the_trails_logs_or_error_messages(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => '000000']);
        $this->meta['messages'] = 'reject';
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        $page = $this->actingAs($admin)->get(route('staff.whatsapp'));

        $stored = json_encode([
            WhatsAppSettingsAudit::all()->toArray(),
            WhatsAppMessageLog::all()->toArray(),
            $this->logged,
            session('errors')?->getBags(),
        ]);

        foreach ([self::CODE, self::PHONE_DIGITS, 'fake-test-token-0001'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, $secret);
            $this->assertStringNotContainsString($secret, $page->getContent(), $secret);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typedCodes(): array
    {
        return [
            'bad format' => ['91x8273'],
            'too long' => ['918273645501928'],
            'wrong code' => ['918273'],
        ];
    }

    #[DataProvider('typedCodes')]
    public function test_a_typed_test_code_is_never_kept_in_the_session_or_shown_again(string $typed): void
    {
        $admin = $this->adminWithSentTest();

        $this->actingAs($admin)
            ->from(route('staff.whatsapp'))
            ->post(route('staff.whatsapp.send-test.verify'), ['code' => $typed])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasErrors('code', errorBag: 'verify_send_test')
            ->assertSessionMissing('_old_input');

        $this->assertStringNotContainsString($typed, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all()));

        $page = $this->actingAs($admin)->get(route('staff.whatsapp'))->assertOk();
        $page->assertSee('name="code" value=""', false);
        $this->assertStringNotContainsString($typed, $page->getContent());
        $this->assertStringNotContainsString($typed, json_encode([$this->logged, WhatsAppSettingsAudit::all()->toArray()]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedNumbers(): array
    {
        return [
            'too short' => ['+96655123', '96655123'],
            'country not allowed' => ['+201001234567', '201001234567'],
        ];
    }

    #[DataProvider('refusedNumbers')]
    public function test_a_refused_number_is_not_kept_in_the_session_or_shown_again(string $typed, string $digits): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach (['staff.whatsapp.send-test.review', 'staff.whatsapp.send-test'] as $route) {
            $this->asConfirmedAdmin($admin)
                ->from(route('staff.whatsapp'))
                ->post(route($route, 'test'), ['phone' => $typed])
                ->assertRedirect(route('staff.whatsapp'))
                ->assertSessionHasErrors('phone', errorBag: 'send_test_test')
                ->assertSessionMissing('_old_input');

            $this->assertStringNotContainsString($digits, json_encode(session()->all()));
        }

        $page = $this->actingAs($admin)->get(route('staff.whatsapp'))->assertOk();
        $page->assertSee('name="phone" value=""', false);
        $this->assertStringNotContainsString($digits, $page->getContent());
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_the_full_number_is_shown_only_on_the_review_screen(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test.review', 'test'), ['phone' => self::PHONE])
            ->assertOk()
            ->assertSee('+966 55 123 4567');
        $this->assertStringNotContainsString(self::PHONE_DIGITS, json_encode(session()->all()));

        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        $this->assertStringNotContainsString(self::PHONE_DIGITS, json_encode(session()->all()));

        $everything = json_encode([WhatsAppSettingsAudit::all()->toArray(), WhatsAppMessageLog::all()->toArray(), $this->logged])
            .$this->actingAs($admin)->get(route('staff.whatsapp'))->getContent();
        $this->assertStringNotContainsString(self::PHONE_DIGITS, $everything);
        $this->assertStringNotContainsString('55 123 4567', $everything);
    }

    public function test_login_codes_keep_following_the_mode(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $admin = User::factory()->admin()->create();
        $this->asConfirmedAdmin($admin)->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        auth()->logout();

        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasErrors('send');

        $this->assertCount(1, $this->messageRequests);
        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
    }

    private function adminWithSentTest(): User
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE])
            ->assertSessionHasNoErrors();

        return $admin;
    }

    private function asConfirmedAdmin(?User $admin = null): static
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time()]);
    }
}
