<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ModeChangeRefusedException;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\UserRole;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.3: switching the mode from the panel (REQ-WA-09, REQ-WA-11, REQ-WA-13,
 * REQ-WA-16, REQ-WA-42, REQ-WA-44, REQ-WA-65, REQ-WA-71; AC-WA-05, AC-WA-49, AC-WA-53).
 */
class WhatsAppModeSwitchTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Switching the mode never calls Meta.
        Http::preventStrayRequests();
    }

    // --- Allowed switches: only the mode changes (AC-WA-05) ---------------------------

    /**
     * @return array<string, array{WhatsAppMode, WhatsAppMode}>
     */
    public static function allowedSwitches(): array
    {
        return [
            'production to disabled' => [WhatsAppMode::Production, WhatsAppMode::Disabled],
            'production to test' => [WhatsAppMode::Production, WhatsAppMode::Test],
            'disabled to test' => [WhatsAppMode::Disabled, WhatsAppMode::Test],
            'test to disabled' => [WhatsAppMode::Test, WhatsAppMode::Disabled],
            'disabled to log' => [WhatsAppMode::Disabled, WhatsAppMode::Log],
            'log to test' => [WhatsAppMode::Log, WhatsAppMode::Test],
        ];
    }

    #[DataProvider('allowedSwitches')]
    public function test_a_switch_changes_only_the_mode_and_is_audited(WhatsAppMode $from, WhatsAppMode $to): void
    {
        $setting = WhatsAppSetting::factory()->mode($from)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->switch($to, $from, $admin)
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'تغيّر وضع الإرسال إلى: '.$to->label().'.');

        $after = $setting->fresh()->getAttributes();
        $this->assertSame($to->value, $after['mode']);
        $this->assertSame('2026-11-19 09:00:00', $after['database_source_activated_at']);
        unset($before['mode'], $before['updated_at'], $after['mode'], $after['updated_at']);
        $this->assertSame($before, $after);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::ModeChanged, $audit->action);
        $this->assertSame(['mode' => ['old' => $from->value, 'new' => $to->value]], $audit->changes);
        $this->assertSame($to->environment(), $audit->environment);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertStringNotContainsString('fake-', json_encode($audit->getAttributes()));
    }

    public function test_log_is_allowed_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(PreventRequestForgery::class);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();

        $this->switch(WhatsAppMode::Log, WhatsAppMode::Disabled)->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::Log, WhatsAppSetting::sole()->mode);
    }

    // --- Refused switches: nothing written, refusal audited -----------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function serverEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('serverEnvironments')]
    public function test_log_is_refused_outside_local_and_testing_on_the_server(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();

        $this->review(WhatsAppMode::Log)->assertSessionHasErrors(['mode' => 'وضع «تسجيل فقط» متاح في بيئة التطوير والاختبار فقط.'], errorBag: 'mode');
        $this->switch(WhatsAppMode::Log, WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
        $this->assertRefusalAudited('log_not_allowed', 'disabled', 'log');

        // Not even offered.
        $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))
            ->assertSee('data-mode-switch', false)
            ->assertDontSee('<option value="log">', false);
    }

    public function test_production_is_never_accepted_from_the_panel(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $before = $setting->fresh()->getAttributes();

        // The review only reads: refused without an audit.
        $this->review(WhatsAppMode::Production)->assertSessionHasErrors('mode', errorBag: 'mode');
        $this->assertSame(0, WhatsAppSettingsAudit::count());

        $this->switch(WhatsAppMode::Production, WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertRefusalAudited('production_not_allowed', 'disabled', 'production');
        $this->assertSame(1, WhatsAppSettingsAudit::count());

        // Even calling the service directly.
        try {
            app(WhatsAppSettingsManager::class)->switchMode(WhatsAppMode::Production, WhatsAppMode::Disabled, User::factory()->admin()->create(), request());
            $this->fail('Production was accepted.');
        } catch (ModeChangeRefusedException $exception) {
            $this->assertSame(ModeChangeRefusedException::PRODUCTION, $exception->reason);
        }

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertRefusalAudited('production_not_allowed', 'disabled', 'production');

        $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))
            ->assertDontSee('<option value="production">', false);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function productionInputs(): array
    {
        return ['exact' => ['production'], 'upper case' => ['PRODUCTION'], 'padded' => ['  Production ']];
    }

    #[DataProvider('productionInputs')]
    public function test_a_production_attempt_is_audited_once_without_sensitive_data(mixed $mode): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.mode'), [
                'mode' => $mode,
                'expected_mode' => 'test',
                'acknowledge_impact' => '1',
                '_token' => 'csrf-token-sample',
                'password' => 'secret-password-sample',
                'production_access_token' => 'fake-production-token',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(WhatsAppMode::Test, $setting->fresh()->mode);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::ModeChangeRefused, $audit->action);
        $this->assertSame(['mode' => ['current' => 'test', 'requested' => 'production'], 'reason' => 'production_not_allowed'], $audit->changes);
        $this->assertSame(WhatsAppEnvironment::Production, $audit->environment);
        $this->assertSame($admin->id, $audit->user_id);

        $stored = json_encode($audit->getAttributes());
        foreach (['csrf-token-sample', 'secret-password-sample', 'fake-', 'acknowledge', 'expected_mode', 'access_token'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }
    }

    public function test_a_production_attempt_with_other_errors_is_still_audited_once(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();

        $this->switch(WhatsAppMode::Production, WhatsAppMode::Disabled, acknowledge: false)
            ->assertSessionHasErrors(['mode', 'acknowledge_impact'], errorBag: 'mode');

        $this->assertSame(1, WhatsAppSettingsAudit::count());
        $this->assertRefusalAudited('production_not_allowed', 'disabled', 'production');
        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
    }

    public function test_a_production_attempt_without_a_settings_row_is_audited_without_creating_one(): void
    {
        $this->switch(WhatsAppMode::Production, WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame(0, WhatsAppSetting::count());
        $this->assertSame(['mode' => ['current' => null, 'requested' => 'production'], 'reason' => 'production_not_allowed'], WhatsAppSettingsAudit::sole()->changes);
    }

    public function test_other_validation_failures_are_not_audited(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        foreach ([
            ['mode' => 'unknown', 'expected_mode' => 'disabled', 'acknowledge_impact' => '1'],
            ['mode' => ['production'], 'expected_mode' => 'disabled', 'acknowledge_impact' => '1'],
            ['mode' => 'test', 'expected_mode' => 'bogus', 'acknowledge_impact' => '1'],
            ['mode' => 'test', 'expected_mode' => 'disabled'],
            ['expected_mode' => 'disabled', 'acknowledge_impact' => '1'],
        ] as $payload) {
            $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
                ->put(route('staff.whatsapp.mode'), $payload)
                ->assertSessionHasErrors(errorBag: 'mode');
        }

        $this->assertSame(0, WhatsAppSettingsAudit::count());
        $this->assertSame($before, $setting->fresh()->getAttributes());
    }

    public function test_a_production_attempt_is_not_audited_without_permission_or_a_recent_password(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create();
        $payload = $this->payload(WhatsAppMode::Production, WhatsAppMode::Disabled);

        $this->actingAs(User::factory()->role(UserRole::Guide)->create())->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.mode'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->patient()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.mode'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->put(route('staff.whatsapp.mode'), $payload)->assertRedirect(route('password.confirm'));
        auth()->logout();
        $this->put(route('staff.whatsapp.mode'), $payload)->assertRedirect();

        $this->assertSame(0, WhatsAppSettingsAudit::count());
        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
    }

    public function test_test_is_refused_when_the_test_environment_is_incomplete(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create(['test_access_token' => null, 'test_template_name' => null]);

        $this->review(WhatsAppMode::Test)
            ->assertSessionHasErrors(['mode' => 'إعدادات بيئة الاختبار ناقصة (Access Token، اسم القالب)، فلا يمكن التبديل إليها.'], errorBag: 'mode');
        $this->switch(WhatsAppMode::Test, WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
        $this->assertRefusalAudited('test_incomplete', 'disabled', 'test');
    }

    public function test_switching_is_refused_while_the_row_is_being_prepared(): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Disabled)->create();
        $before = $setting->fresh()->getAttributes();

        $this->review(WhatsAppMode::Test)
            ->assertSessionHasErrors(['mode' => 'تبديل الوضع غير متاح: السجل قيد التحضير، ومصدر رموز الدخول الفعّال ما زال ملف .env.'], errorBag: 'mode');
        $this->switch(WhatsAppMode::Test, WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertRefusalAudited('preparing', 'disabled', 'test');

        $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))
            ->assertSee('data-mode-switch-unavailable', false)
            ->assertDontSee('data-mode-switch>', false)
            ->assertDontSee(route('staff.whatsapp.mode.review'), false);
    }

    public function test_switching_to_the_current_mode_changes_nothing(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();

        $this->switch(WhatsAppMode::Test, WhatsAppMode::Test)->assertSessionHasErrors(['mode' => 'هذا هو الوضع الحالي، فلم يُغيَّر شيء.'], errorBag: 'mode');

        $this->assertRefusalAudited('unchanged', 'test', 'test');
    }

    public function test_switching_without_a_settings_row_is_refused(): void
    {
        $this->review(WhatsAppMode::Disabled)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame(0, WhatsAppSetting::count());
    }

    // --- Concurrent changes: nothing partial -------------------------------------------

    public function test_a_mode_changed_since_the_review_is_refused_without_writing(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $admin = User::factory()->admin()->create();
        $this->reviewAs($admin, WhatsAppMode::Disabled)->assertOk()->assertSee('value="production"', false);

        // Another administrator switches to test in the meantime.
        $setting->forceFill(['mode' => WhatsAppMode::Test])->save();
        $before = $setting->fresh()->getAttributes();

        $this->switch(WhatsAppMode::Disabled, WhatsAppMode::Production, $admin)
            ->assertSessionHasErrors(['mode' => 'تغيّر الوضع أو حالة المصدر منذ عرض المراجعة، فلم يُغيَّر شيء. راجع الحالة الحالية وأعد المحاولة.'], errorBag: 'mode');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertRefusalAudited('stale', 'test', 'disabled');
    }

    public function test_a_source_returned_to_env_since_the_review_is_refused_without_writing(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $admin = User::factory()->admin()->create();
        $this->reviewAs($admin, WhatsAppMode::Test)->assertOk();

        $setting->forceFill(['database_source_activated_at' => null])->save();
        $before = $setting->fresh()->getAttributes();

        $this->switch(WhatsAppMode::Test, WhatsAppMode::Production, $admin)->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertRefusalAudited('preparing', 'production', 'test');
    }

    // --- Warnings and acknowledgements (REQ-WA-71, REQ-WA-16, AC-WA-53) -----------------

    /**
     * @return array<string, array{WhatsAppMode, string}>
     */
    public static function impacts(): array
    {
        return [
            'disabled' => [WhatsAppMode::Disabled, 'تتوقف كل رموز الدخول فورًا'],
            'test' => [WhatsAppMode::Test, 'لا تصل إلا إلى المستلمين المسموح لهم'],
            'log' => [WhatsAppMode::Log, 'لا يُرسل أي رمز دخول'],
        ];
    }

    #[DataProvider('impacts')]
    public function test_the_review_shows_both_modes_and_the_impact_on_login_codes(WhatsAppMode $to, string $impact): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->review($to)
            ->assertOk()
            ->assertSee('الوضع الحالي: <b>'.WhatsAppMode::Production->label().'</b>', false)
            ->assertSee('الوضع المطلوب: <b>'.$to->label().'</b>', false)
            ->assertSee($impact)
            ->assertSee('فقد لا تستطيع الدخول إلى اللوحة')
            ->assertSee('name="acknowledge_impact"', false)
            ->assertDontSee('data-test-recipients-warning', false);

        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_the_impact_must_be_acknowledged_on_the_server(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->switch(WhatsAppMode::Disabled, WhatsAppMode::Production, acknowledge: false)
            ->assertSessionHasErrors(['acknowledge_impact' => 'أقرّ بأثر التبديل على رموز الدخول، ومنها دخولك أنت.'], errorBag: 'mode');

        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_test_in_a_production_application_needs_its_own_acknowledgement(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->review(WhatsAppMode::Test)
            ->assertOk()
            ->assertSee('data-test-recipients-warning', false)
            ->assertSee('فلن تصل رموز الدخول إلى المستفيدين')
            ->assertSee('name="acknowledge_test_recipients"', false);

        $this->switch(WhatsAppMode::Test, WhatsAppMode::Production)
            ->assertSessionHasErrors('acknowledge_test_recipients', errorBag: 'mode');
        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);

        $this->switch(WhatsAppMode::Test, WhatsAppMode::Production, extra: ['acknowledge_test_recipients' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppMode::Test, WhatsAppSetting::sole()->mode);
    }

    // --- Password confirmation, permission and CSRF ---------------------------------------

    public function test_review_and_switch_need_a_password_confirmed_within_15_minutes(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->post(route('staff.whatsapp.mode.review'), ['mode' => 'disabled'])
            ->assertRedirect(route('password.confirm'));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->put(route('staff.whatsapp.mode'), $this->payload(WhatsAppMode::Disabled, WhatsAppMode::Production))
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
            ->put(route('staff.whatsapp.mode'), $this->payload(WhatsAppMode::Disabled, WhatsAppMode::Production))
            ->assertSessionHasNoErrors();
        $this->assertSame(WhatsAppMode::Disabled, WhatsAppSetting::sole()->mode);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdministrators(): array
    {
        return ['patient' => ['patient'], 'guide' => ['guide'], 'medical' => ['medical']];
    }

    #[DataProvider('nonAdministrators')]
    public function test_only_administrators_may_review_or_switch(string $who): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $user = $who === 'patient' ? User::factory()->patient()->create() : User::factory()->role(UserRole::from($who))->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('staff.whatsapp.mode.review'), ['mode' => 'disabled'])->assertForbidden();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.mode'), $this->payload(WhatsAppMode::Disabled, WhatsAppMode::Production))->assertForbidden();

        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_a_switch_without_a_csrf_token_is_refused(): void
    {
        // CSRF checks are skipped only in the "testing" environment.
        $this->app->detectEnvironment(fn () => 'local');
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();

        $this->switch(WhatsAppMode::Disabled, WhatsAppMode::Production)->assertStatus(419);

        $this->assertSame(WhatsAppMode::Production, WhatsAppSetting::sole()->mode);
    }

    private function review(WhatsAppMode $to): TestResponse
    {
        return $this->reviewAs(User::factory()->admin()->create(), $to);
    }

    private function reviewAs(User $admin, WhatsAppMode $to): TestResponse
    {
        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('staff.whatsapp.mode.review'), ['mode' => $to->value]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function switch(WhatsAppMode $to, WhatsAppMode $expected, ?User $admin = null, bool $acknowledge = true, array $extra = []): TestResponse
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.mode'), $this->payload($to, $expected, $acknowledge) + $extra);
    }

    /**
     * @return array<string, string>
     */
    private function payload(WhatsAppMode $to, WhatsAppMode $expected, bool $acknowledge = true): array
    {
        return ['mode' => $to->value, 'expected_mode' => $expected->value] + ($acknowledge ? ['acknowledge_impact' => '1'] : []);
    }

    private function assertRefusalAudited(string $reason, string $current, string $requested): void
    {
        $audit = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ModeChangeRefused)->latest('id')->first();

        $this->assertNotNull($audit, 'The refusal was not audited.');
        $this->assertSame(['mode' => ['current' => $current, 'requested' => $requested], 'reason' => $reason], $audit->changes);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ModeChanged)->count());
        $this->assertStringNotContainsString('fake-', json_encode($audit->getAttributes()));
    }
}
