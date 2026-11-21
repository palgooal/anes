<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\AdminTestCodes;
use App\Services\WhatsApp\MetaGraphClient;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppReadiness;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\UserRole;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.5: readiness checks of the production environment (REQ-WA-24, REQ-WA-25)
 * and the settings fingerprint of the send-test evidence (REQ-WA-68, AC-WA-50, AC-WA-55).
 */
class WhatsAppReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '+966551234567';

    private const CODE = '246801';

    private const ROTATED_TOKEN = 'EAAG-rotated-fake-production-token-7777';

    /** debug_token "data", or 'unreachable' / 'http-error'. */
    private array|string $token = ['is_valid' => true, 'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'], 'expires_at' => 0];

    /** Phone number answer, or 'refused' / 'unreachable'. */
    private array|string $phone = ['id' => '100000000000002', 'display_phone_number' => '+966 50 000 0002', 'verified_name' => 'ANIS'];

    /** Templates "data", or 'refused'. */
    private array|string $templates;

    /** @var list<string> */
    private array $paths = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->templates = [$this->template()];

        AdminTestCodes::generateUsing(fn () => self::CODE);
        $this->beforeApplicationDestroyed(fn () => AdminTestCodes::generateUsing(null));

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->paths[] = $path.'?'.parse_url($request->url(), PHP_URL_QUERY);

            if (str_ends_with($path, '/messages')) {
                return Http::response(['messages' => [['id' => 'wamid.READY']]]);
            }

            if (str_ends_with($path, '/debug_token')) {
                return match ($this->token) {
                    'unreachable' => throw new ConnectionException('cURL error 7 for '.$request->url()),
                    'http-error' => Http::response(['error' => ['code' => 190]], 400),
                    default => Http::response(['data' => $this->token]),
                };
            }

            if (str_ends_with($path, '/message_templates')) {
                return $this->templates === 'refused'
                    ? Http::response(['error' => ['code' => 100]], 400)
                    : Http::response(['data' => $this->templates]);
            }

            return match ($this->phone) {
                'refused' => Http::response(['error' => ['code' => 100]], 400),
                'unreachable' => throw new ConnectionException('cURL error 28 for '.$request->url()),
                default => Http::response($this->phone),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- All six together ------------------------------------------------------------------

    public function test_five_conditions_pass_and_the_acknowledgement_waits_for_the_activation(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $result = $this->readiness()->check($setting);

        foreach (range(1, 5) as $condition) {
            $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][$condition]['status'], "Condition {$condition}");
        }

        $this->assertSame(WhatsAppReadiness::PENDING, $result['checks'][6]['status']);
        $this->assertFalse($result['ready']);
    }

    public function test_all_six_pass_when_the_reference_text_is_given(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $result = $this->readiness()->check($setting, 'Number ownership file NO-2026-117');

        $this->assertTrue($result['ready']);
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][6]['status']);
        $this->assertFalse($this->readiness()->check($setting, '   ')['ready']);
    }

    // --- (1) Fields (AC-WA-55) -----------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'Phone Number ID' => ['production_phone_number_id'],
            'WABA ID' => ['production_waba_id'],
            'display number' => ['production_display_number'],
            'access token' => ['production_access_token'],
            'template name' => ['production_template_name'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_missing_required_field_fails_condition_1(string $column): void
    {
        $result = $this->readiness()->check($this->setting([$column => null]));

        $this->assertSame(WhatsAppReadiness::FAILED, $result['checks'][1]['status']);
        $this->assertStringContainsString(str_replace('production_', '', $column), $result['checks'][1]['details']['missing']);
        $this->assertFalse($result['ready']);
    }

    public function test_the_template_language_may_use_its_default(): void
    {
        $result = $this->readiness()->check($this->setting(['production_template_language' => null]));

        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][1]['status']);
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][4]['status']);
    }

    // --- (2) Token ------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>|string, string}>
     */
    public static function refusedTokens(): array
    {
        $scopes = ['whatsapp_business_messaging'];

        return [
            'invalid' => [['is_valid' => false, 'scopes' => []], 'غير صالح'],
            'no messaging scope' => [['is_valid' => true, 'scopes' => ['whatsapp_business_management'], 'expires_at' => 0], 'whatsapp_business_messaging'],
            'expired' => [['is_valid' => true, 'scopes' => $scopes, 'expires_at' => now()->subDay()->getTimestamp()], 'أقل من 7'],
            'expiry unknown' => [['is_valid' => true, 'scopes' => $scopes], 'غير معروفة'],
            'expiry not a number' => [['is_valid' => true, 'scopes' => $scopes, 'expires_at' => 'soon'], 'غير معروفة'],
            'six days left' => [['is_valid' => true, 'scopes' => $scopes, 'expires_at' => now()->addDays(6)->getTimestamp()], 'أقل من 7'],
            'Meta unreachable' => ['unreachable', 'تعذّر'],
            'Meta error' => ['http-error', 'تعذّر'],
        ];
    }

    #[DataProvider('refusedTokens')]
    public function test_condition_2_refuses_the_token(array|string $answer, string $reason): void
    {
        $this->token = $answer;
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $result = $this->readiness()->check($setting);

        $this->assertSame(WhatsAppReadiness::FAILED, $result['checks'][2]['status']);
        $this->assertStringContainsString($reason, $result['checks'][2]['message']);
        // The others are judged on their own.
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][3]['status']);
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][5]['status']);
    }

    public function test_condition_2_accepts_seven_days_left_or_no_expiry(): void
    {
        $this->token = ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => now()->addDays(7)->addMinute()->getTimestamp()];
        $this->assertSame(WhatsAppReadiness::PASSED, $this->readiness()->check($this->setting())['checks'][2]['status']);

        $this->token['expires_at'] = 0;
        $result = $this->readiness()->check(WhatsAppSetting::sole());
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][2]['status']);
        $this->assertSame(['expiry' => 'never'], $result['checks'][2]['details']);
    }

    // --- (3) Phone number ------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>|string}>
     */
    public static function refusedNumbers(): array
    {
        return [
            'other display number' => [['id' => '100000000000002', 'display_phone_number' => '+966 50 000 0009']],
            'no display number' => [['id' => '100000000000002', 'verified_name' => 'ANIS']],
            'refused by Meta' => ['refused'],
            'Meta unreachable' => ['unreachable'],
        ];
    }

    #[DataProvider('refusedNumbers')]
    public function test_condition_3_fails_without_a_matching_display_number(array|string $answer): void
    {
        $this->phone = $answer;

        $result = $this->readiness()->check($this->setting());

        $this->assertSame(WhatsAppReadiness::FAILED, $result['checks'][3]['status']);
        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][2]['status']);
    }

    public function test_condition_3_queries_the_saved_phone_number_id_and_keeps_only_last_digits(): void
    {
        $result = $this->readiness()->check($this->setting());

        $this->assertSame(['meta_last4' => '0002', 'saved_last4' => '0002'], $result['checks'][3]['details']);
        $this->assertContains('/v23.0/100000000000002?fields=display_phone_number%2Cverified_name%2Cquality_rating%2Ccode_verification_status', $this->paths);
    }

    // --- (4) Template (VER-AUTH-03) ----------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedTemplates(): array
    {
        return [
            'not approved' => [['status' => 'PENDING'], 'غير معتمد'],
            'rejected' => [['status' => 'REJECTED'], 'غير معتمد'],
            'marketing' => [['category' => 'MARKETING'], 'AUTHENTICATION'],
            'utility' => [['category' => 'UTILITY'], 'AUTHENTICATION'],
            'other language only' => [['language' => 'en_US'], 'بلغة ar'],
            'footer 10 minutes' => [['components' => [['type' => 'BODY'], ['type' => 'FOOTER', 'code_expiration_minutes' => 10]]], 'ليست 5'],
            'footer in an odd form' => [['components' => [['type' => 'FOOTER', 'code_expiration_minutes' => '5 min']]], 'غير متوقعة'],
            'components not returned' => [['components' => null], 'مكونات القالب'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('refusedTemplates')]
    public function test_condition_4_refuses_the_template(array $override, string $reason): void
    {
        $this->templates = [$this->template($override)];

        $result = $this->readiness()->check($this->setting());

        $this->assertSame(WhatsAppReadiness::FAILED, $result['checks'][4]['status']);
        $this->assertStringContainsString($reason, $result['checks'][4]['message']);
    }

    public function test_condition_4_refuses_a_missing_template_or_a_meta_error(): void
    {
        $this->templates = [$this->template(['name' => 'login_code_old'])];
        $this->assertSame(WhatsAppReadiness::FAILED, $this->readiness()->check($this->setting())['checks'][4]['status']);

        $this->templates = 'refused';
        $this->assertSame(WhatsAppReadiness::FAILED, $this->readiness()->check(WhatsAppSetting::sole())['checks'][4]['status']);
    }

    /**
     * @return array<string, array{list<array<string, mixed>>, string}>
     */
    public static function acceptedFooters(): array
    {
        return [
            'no footer' => [[['type' => 'BODY', 'add_security_recommendation' => true]], 'not_set'],
            'no components at all' => [[], 'not_set'],
            'footer without expiry' => [[['type' => 'FOOTER', 'text' => 'x']], 'not_set'],
            'footer of 5 minutes' => [[['type' => 'BODY'], ['type' => 'FOOTER', 'code_expiration_minutes' => 5]], '5'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    #[DataProvider('acceptedFooters')]
    public function test_condition_4_accepts_no_footer_expiry_or_5_minutes(array $components, string $expiry): void
    {
        $this->templates = [$this->template(['language' => 'en_US']), $this->template(['components' => $components])];

        $result = $this->readiness()->check($this->setting());

        $this->assertSame(WhatsAppReadiness::PASSED, $result['checks'][4]['status']);
        $this->assertSame($expiry, $result['checks'][4]['details']['code_expiration_minutes']);
        $this->assertTrue(collect($this->paths)->contains(fn (string $path) => str_contains($path, '/200000000000002/message_templates')
            && str_contains($path, 'fields='.urlencode(MetaGraphClient::TEMPLATE_FIELDS_WITH_COMPONENTS))));
    }

    // --- (5) Send-test evidence (REQ-WA-68, AC-WA-50) ---------------------------------------------

    public function test_a_panel_test_confirmed_by_the_administrator_is_the_evidence(): void
    {
        $setting = $this->setting();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('staff.whatsapp.send-test', 'production'), ['phone' => self::PHONE])
            ->assertSessionHasNoErrors();

        // Meta accepting the message is not delivery: no evidence yet.
        $this->assertNull($this->readiness()->evidence($setting));
        $this->assertSame(WhatsAppReadiness::FAILED, $this->readiness()->check($setting)['checks'][5]['status']);

        $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE])->assertSessionHasNoErrors();

        $evidence = $this->readiness()->evidence($setting);
        $this->assertNotNull($evidence);
        $this->assertSame($admin->id, $evidence->user_id);
        $this->assertSame(app(SettingsFingerprint::class)->production($setting), $evidence->changes['fingerprint']);
        $this->assertSame(WhatsAppReadiness::PASSED, $this->readiness()->check($setting)['checks'][5]['status']);
    }

    public function test_a_wrong_code_is_no_evidence(): void
    {
        $setting = $this->setting();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('staff.whatsapp.send-test', 'production'), ['phone' => self::PHONE]);
        $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => '000000']);

        $this->assertNull($this->readiness()->evidence($setting));
    }

    public function test_a_test_environment_send_is_no_evidence_even_when_confirmed(): void
    {
        $setting = $this->setting();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('staff.whatsapp.send-test', 'test'), ['phone' => self::PHONE]);
        $this->actingAs($admin)->post(route('staff.whatsapp.send-test.verify'), ['code' => self::CODE]);

        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SendTestVerification)->count());
        $this->assertNull($this->readiness()->evidence($setting));
    }

    /**
     * Each case on its own (AC-WA-50).
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedEvidence(): array
    {
        return [
            'older than 24 hours' => [['age_minutes' => 24 * 60 + 1]],
            'not confirmed: wrong code' => [['outcome' => 'wrong']],
            'not confirmed: expired' => [['outcome' => 'expired']],
            'not confirmed: attempts exhausted' => [['outcome' => 'exhausted']],
            'test environment' => [['environment' => WhatsAppEnvironment::Test]],
            'no environment' => [['environment' => null]],
            'whatsapp:test' => [['origin' => 'console', 'user' => false]],
            'whatsapp:test run by a known user id' => [['origin' => 'console']],
            'no known administrator' => [['user' => false]],
            'administrator deleted' => [['user' => 'deleted']],
            'no fingerprint' => [['fingerprint' => null]],
            'other fingerprint' => [['fingerprint' => str_repeat('a', 64)]],
            'malformed fingerprint' => [['fingerprint' => ['x']]],
            'only an attempt, not a confirmation' => [['action' => WhatsAppAuditAction::SendTestAttempted]],
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('refusedEvidence')]
    public function test_evidence_is_refused(array $case): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting, $case);

        $this->assertNull($this->readiness()->evidence($setting));
        $this->assertSame(WhatsAppReadiness::FAILED, $this->readiness()->check($setting)['checks'][5]['status']);
    }

    public function test_evidence_just_within_24_hours_is_accepted(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting, ['age_minutes' => 24 * 60 - 1]);

        $this->assertNotNull($this->readiness()->evidence($setting));
    }

    /**
     * Every value in the fingerprint (REQ-WA-68).
     *
     * @return array<string, array{string, string}>
     */
    public static function fingerprintedValues(): array
    {
        return [
            'Graph API version' => ['api_version', 'v24.0'],
            'Phone Number ID' => ['production_phone_number_id', '100000000000009'],
            'WABA ID' => ['production_waba_id', '200000000000009'],
            'template name' => ['production_template_name', 'other_login_code'],
            'template language' => ['production_template_language', 'en'],
            'access token' => ['production_access_token', self::ROTATED_TOKEN],
        ];
    }

    #[DataProvider('fingerprintedValues')]
    public function test_changing_a_fingerprinted_value_drops_the_evidence(string $column, string $value): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);
        $this->assertNotNull($this->readiness()->evidence($setting));

        $setting->forceFill([$column => $value])->save();

        $this->assertNull($this->readiness()->evidence($setting->fresh()));
    }

    public function test_a_rotated_token_with_the_same_last_four_drops_the_evidence(): void
    {
        $setting = $this->setting(['production_access_token' => 'EAAG-first-fake-token-1234']);
        $this->evidenceFor($setting);

        $setting->forceFill(['production_access_token' => 'EAAG-other-fake-token-1234'])->save();

        $this->assertNull($this->readiness()->evidence($setting->fresh()));
    }

    public function test_rotating_the_token_from_the_panel_drops_the_evidence(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $this->actingAs(User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::ROTATED_TOKEN])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->readiness()->evidence($setting->fresh()));
    }

    public function test_values_outside_the_fingerprint_keep_the_evidence(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $setting->forceFill(['test_phone_number_id' => '100000000000007', 'mode' => WhatsAppMode::Test])->save();

        $this->assertNotNull($this->readiness()->evidence($setting->fresh()));
    }

    public function test_a_default_and_the_same_value_written_out_give_the_same_fingerprint(): void
    {
        $fingerprint = app(SettingsFingerprint::class);
        $setting = $this->setting(['api_version' => null, 'production_template_language' => null]);
        $before = $fingerprint->production($setting);

        $setting->forceFill(['api_version' => 'v23.0', 'production_template_language' => 'ar']);

        $this->assertSame($before, $fingerprint->production($setting));
    }

    public function test_rotating_the_application_key_drops_the_evidence(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNull($this->readiness()->evidence($setting));
    }

    public function test_the_fingerprint_reveals_no_value(): void
    {
        $setting = $this->setting();
        $fingerprint = app(SettingsFingerprint::class)->production($setting);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        // Not a plain hash of the values: it needs the application key.
        $this->assertNotSame(hash('sha256', json_encode(['v23.0', '100000000000002', '200000000000002', 'login_code', 'ar', 'fake-production-token-0002'])), $fingerprint);
    }

    // --- The page --------------------------------------------------------------------------------

    public function test_the_page_runs_the_checks_on_request_only_and_changes_nothing(): void
    {
        $setting = $this->setting(['mode' => WhatsAppMode::Test]);
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('data-readiness-form', false);
        Http::assertNothingSent();

        $this->actingAs($admin)->post(route('staff.whatsapp.readiness'))
            ->assertOk()
            ->assertSee('data-readiness-overall="technical-failed"', false)
            ->assertDontSee('data-readiness-acknowledgement-pending', false)
            ->assertSee('data-readiness-check="1" data-status="passed"', false)
            ->assertSee('data-readiness-check="5" data-status="failed"', false)
            ->assertSee('data-readiness-check="6" data-status="pending"', false)
            ->assertSee('التفعيل لا يتم من هذه الصفحة')
            ->assertDontSee(route('staff.whatsapp.mode'), false);

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
        $this->assertFalse(collect($this->paths)->contains(fn (string $path) => str_contains($path, '/messages')));
    }

    public function test_five_technical_passes_are_shown_apart_from_the_acknowledgement_due_at_activation(): void
    {
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.readiness'))
            ->assertOk()
            ->assertSee('data-readiness-overall="technical-passed"', false)
            ->assertSee('الشروط التقنية الخمسة مستوفاة')
            ->assertSee('data-readiness-acknowledgement-pending', false)
            ->assertSee('data-readiness-check="6" data-status="pending"', false)
            ->assertDontSee('data-readiness-overall="ready"', false)
            ->assertDontSee('الشروط الستة مستوفاة')
            ->assertDontSee('data-readiness-overall="technical-failed"', false)
            ->assertDontSee('data-status="failed"', false);
    }

    public function test_a_failed_technical_check_is_not_hidden_behind_the_pending_acknowledgement(): void
    {
        $this->token = ['is_valid' => false, 'scopes' => []];
        $setting = $this->setting();
        $this->evidenceFor($setting);

        $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.readiness'))
            ->assertSee('data-readiness-overall="technical-failed"', false)
            ->assertSee('data-readiness-check="2" data-status="failed"', false)
            ->assertDontSee('data-readiness-acknowledgement-pending', false)
            ->assertDontSee('الشروط التقنية الخمسة مستوفاة');
    }

    public function test_the_checks_change_neither_the_mode_nor_the_source_in_any_state(): void
    {
        foreach ([[WhatsAppMode::Production, true], [WhatsAppMode::Disabled, false]] as [$mode, $active]) {
            WhatsAppSetting::query()->delete();
            $setting = $this->setting(['mode' => $mode, 'database_source_activated_at' => $active ? '2026-11-19 09:00:00' : null]);
            $before = $setting->fresh()->getAttributes();

            $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.readiness'))->assertOk();

            $this->assertSame($before, $setting->fresh()->getAttributes());
        }
    }

    public function test_no_secret_or_full_number_reaches_the_page_the_result_or_the_logs(): void
    {
        $this->setting();
        $setting = WhatsAppSetting::sole();
        $this->evidenceFor($setting);
        $fingerprint = app(SettingsFingerprint::class)->production($setting);

        $page = $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.readiness'))->getContent();
        $result = json_encode($this->readiness()->check($setting));

        foreach (['fake-production-token-0002', 'fake-test-token-0001', '966500000002', '966551234567', $fingerprint] as $leak) {
            $this->assertStringNotContainsString($leak, $page);
            $this->assertStringNotContainsString($leak, $result);
        }

        $this->assertStringNotContainsString('fake-production-token', implode("\n", $this->logged));
        $this->assertStringNotContainsString(self::CODE, json_encode(WhatsAppSettingsAudit::all()->toArray()));
    }

    public function test_only_administrators_run_the_checks(): void
    {
        $this->setting();

        $this->post(route('staff.whatsapp.readiness'))->assertRedirect();
        $this->actingAs(User::factory()->patient()->create())->post(route('staff.whatsapp.readiness'))->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::Medical)->create())->post(route('staff.whatsapp.readiness'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_checks_need_a_settings_row(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('staff.whatsapp.readiness'))->assertRedirect(route('staff.whatsapp'));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSetting::count());
    }

    /**
     * REQ-WA-64 is lifted (PH-WA-6.9): the checks on a row taken over in production
     * change neither the source nor the mode (AC-WA-46).
     */
    public function test_the_checks_after_a_take_over_in_production_change_nothing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $admin = User::factory()->admin()->create();
        $setting = app(WhatsAppSettingsManager::class)->takeOver($admin, request());

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('staff.whatsapp.readiness'))
            ->assertOk()
            ->assertSee('data-status="failed"', false);

        $this->assertFalse($setting->fresh()->isDatabaseSource());
        $this->assertSame(WhatsAppMode::Disabled, $setting->fresh()->mode);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
    }

    // --- Helpers ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function setting(array $attributes = []): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create($attributes);
    }

    private function readiness(): WhatsAppReadiness
    {
        return app(WhatsAppReadiness::class);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function template(array $override = []): array
    {
        return array_replace([
            'name' => 'login_code',
            'language' => 'ar',
            'status' => 'APPROVED',
            'category' => 'AUTHENTICATION',
            'components' => [['type' => 'BODY', 'add_security_recommendation' => true], ['type' => 'FOOTER', 'code_expiration_minutes' => 5]],
        ], $override);
    }

    /**
     * A confirmed panel send test on production with the current fingerprint, or one
     * case away from it.
     *
     * @param  array<string, mixed>  $case
     */
    private function evidenceFor(WhatsAppSetting $setting, array $case = []): void
    {
        $user = ($case['user'] ?? true) === false ? null : User::factory()->admin()->create();

        $changes = [
            'origin' => $case['origin'] ?? 'panel',
            'recipient_last4' => '4567',
            'outcome' => $case['outcome'] ?? 'verified',
            'fingerprint' => array_key_exists('fingerprint', $case) ? $case['fingerprint'] : app(SettingsFingerprint::class)->production($setting),
        ];

        $audit = WhatsAppSettingsAudit::record(
            $case['action'] ?? WhatsAppAuditAction::SendTestVerification,
            $user,
            array_key_exists('environment', $case) ? $case['environment'] : WhatsAppEnvironment::Production,
            array_filter($changes, fn ($value) => $value !== null),
        );

        if (($case['user'] ?? true) === 'deleted') {
            // The administrator confirmed, then was deleted: the entry keeps no known administrator.
            $user->delete();
            $this->assertNull($audit->fresh()->user_id);
        }

        if (isset($case['age_minutes'])) {
            WhatsAppSettingsAudit::query()->whereKey($audit->getKey())->toBase()->update(['created_at' => now()->subMinutes($case['age_minutes'])]);
        }
    }
}
