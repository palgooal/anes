<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\UserRole;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-4 of docs/ANIS-WHATSAPP-SETTINGS-001.md: the WhatsApp settings panel
 * (AC-WA-03 "log" branch only, AC-WA-11 to AC-WA-14, AC-WA-25 to AC-WA-28, AC-WA-41, AC-WA-42).
 * Tokens here are placeholders; no request ever reaches Meta.
 */
class WhatsAppSettingsControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NEW_TOKEN = 'EAAG-new-fake-access-token-9876';

    private const DEBUG_TOKEN_URL = 'graph.facebook.com/v23.0/debug_token*';

    /** What the fake debug_token answers: 'valid', 'no-expiry', 'unknown-expiry', 'invalid', 'missing-scope' or 'unreachable'. */
    private string $meta = 'valid';

    /** Everything written to the application log during the test. */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([self::DEBUG_TOKEN_URL => function () {
            $scopes = ['whatsapp_business_management', 'whatsapp_business_messaging'];

            return match ($this->meta) {
                'valid' => Http::response(['data' => ['is_valid' => true, 'scopes' => $scopes, 'expires_at' => 1798761600]]),
                'no-expiry' => Http::response(['data' => ['is_valid' => true, 'scopes' => $scopes, 'expires_at' => 0]]),
                'unknown-expiry' => Http::response(['data' => ['is_valid' => true, 'scopes' => $scopes]]),
                'invalid' => Http::response(['data' => ['is_valid' => false, 'scopes' => []]]),
                'missing-scope' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_management'], 'expires_at' => 0]]),
                'unreachable' => throw new ConnectionException('cURL error 28 for https://graph.facebook.com/v23.0/debug_token?input_token='.self::NEW_TOKEN),
            };
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- AC-WA-25: access ------------------------------------------------------

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function routes(): array
    {
        return [
            'show' => ['get', 'staff.whatsapp', []],
            'take over' => ['post', 'staff.whatsapp.take-over', []],
            'general' => ['put', 'staff.whatsapp.general', []],
            'environment' => ['put', 'staff.whatsapp.environment', ['environment' => 'production']],
            'secrets' => ['put', 'staff.whatsapp.secrets', ['environment' => 'production']],
            'webhook token' => ['put', 'staff.whatsapp.webhook-token', []],
            'connection test' => ['post', 'staff.whatsapp.connection-test', ['environment' => 'production']],
            'send test review' => ['post', 'staff.whatsapp.send-test.review', ['environment' => 'production']],
            'send test' => ['post', 'staff.whatsapp.send-test', ['environment' => 'production']],
            'send test verify' => ['post', 'staff.whatsapp.send-test.verify', []],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     */
    #[DataProvider('routes')]
    public function test_guests_are_sent_to_the_staff_login(string $method, string $route, array $parameters): void
    {
        $this->{$method}(route($route, $parameters))->assertRedirect(route('staff.login'));
    }

    /**
     * @param  array<string, string>  $parameters
     */
    #[DataProvider('routes')]
    public function test_every_non_administrator_gets_403(string $method, string $route, array $parameters): void
    {
        WhatsAppSetting::factory()->create();
        $users = [
            User::factory()->patient()->create(),
            ...collect([UserRole::Guide, UserRole::Fiqh, UserRole::Medical, UserRole::Legal])->map(fn (UserRole $role) => User::factory()->role($role)->create()),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->{$method}(route($route, $parameters), ['confirm' => '1', 'access_token' => self::NEW_TOKEN, 'phone' => '+966551234567', 'code' => '123456'])
                ->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_the_settings_tab_is_shown_only_with_the_permission(): void
    {
        $this->actingAs($admin = User::factory()->admin()->create())
            ->get(route('staff.team'))
            ->assertSee('إعدادات واتساب')
            ->assertSee(route('staff.whatsapp'));

        $this->actingAs(User::factory()->create());
        $html = $this->blade('<x-staff-layout title="t" active="team">x</x-staff-layout>');
        $html->assertDontSee('إعدادات واتساب');
        $this->assertTrue($admin->can('manage-whatsapp'));
    }

    public function test_manage_whatsapp_is_separate_from_manage_team(): void
    {
        foreach (['staff.whatsapp', 'staff.whatsapp.take-over', 'staff.whatsapp.general', 'staff.whatsapp.environment', 'staff.whatsapp.secrets', 'staff.whatsapp.webhook-token', 'staff.whatsapp.connection-test', 'staff.whatsapp.send-test.review', 'staff.whatsapp.send-test', 'staff.whatsapp.send-test.verify'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('can:manage-whatsapp', $middleware, $name);
            $this->assertNotContains('can:manage-team', $middleware, $name);
        }
    }

    // --- AC-WA-03: moving management to the panel --------------------------------

    public function test_viewing_the_page_never_creates_the_settings_row(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('الإعدادات تُقرأ حالياً من ملف')
            ->assertSee('نقل الإدارة إلى اللوحة');

        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_take_over_requires_a_password_confirmation(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('staff.whatsapp'))
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_take_over_requires_the_explicit_confirmation(): void
    {
        $this->asConfirmedAdmin()
            ->from(route('staff.whatsapp'))
            ->post(route('staff.whatsapp.take-over'))
            ->assertSessionHasErrors(['confirm' => 'أكّد أنك تفهم أثر نقل الإدارة إلى اللوحة.']);

        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_take_over_creates_an_empty_row_in_log_mode_locally_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        config(['services.whatsapp' => ['driver' => 'meta', 'phone_number_id' => '999999999999999', 'access_token' => 'env-token-not-copied', 'login_template' => 'env_template', 'template_language' => 'en', 'api_version' => 'v99.0', 'number' => '1']]);

        $this->asConfirmedAdmin($admin)
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHas('status');

        $setting = WhatsAppSetting::sole();
        $this->assertSame(WhatsAppMode::Log, $setting->mode);
        $this->assertNull($setting->production_phone_number_id);
        $this->assertNull($setting->production_access_token);
        $this->assertNull($setting->api_version);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::TakeOver, $audit->action);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['old' => null, 'new' => 'log'], $audit->changes['mode']);
        $this->assertSame('127.0.0.1', $audit->ip_address);
        $this->assertStringNotContainsString('env-token-not-copied', json_encode($audit->getAttributes()));
    }

    public function test_take_over_is_available_in_the_local_environment_too(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        // CSRF checks are only skipped in the "testing" environment.
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->asConfirmedAdmin()->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])->assertRedirect(route('staff.whatsapp'));

        $this->assertSame(WhatsAppMode::Log, WhatsAppSetting::sole()->mode);
        $this->assertSame(1, WhatsAppSettingsAudit::count());
    }

    // --- AC-WA-03, AC-WA-46: the take-over in every environment (REQ-WA-64 lifted, PH-WA-6.9) ---

    /**
     * @return array<string, array{string, WhatsAppMode}>
     */
    public static function environments(): array
    {
        return [
            'testing' => ['testing', WhatsAppMode::Log],
            'local' => ['local', WhatsAppMode::Log],
            'production' => ['production', WhatsAppMode::Disabled],
            'staging' => ['staging', WhatsAppMode::Disabled],
        ];
    }

    #[DataProvider('environments')]
    public function test_the_take_over_creates_a_row_being_prepared_with_the_initial_mode(string $environment, WhatsAppMode $mode): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->assertSame($mode, WhatsAppSettingsManager::initialMode());

        $this->asConfirmedAdmin()
            ->from(route('staff.whatsapp'))
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'قيد التحضير'));

        $setting = WhatsAppSetting::sole();
        $this->assertSame($mode, $setting->mode);
        $this->assertNull($setting->database_source_activated_at);
        $this->assertFalse($setting->isDatabaseSource());

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::TakeOver, $audit->action);
        $this->assertSame(['state' => ['old' => null, 'new' => 'preparing'], 'mode' => ['old' => null, 'new' => $mode->value]], $audit->changes);
    }

    #[DataProvider('environments')]
    public function test_the_take_over_form_shows_the_initial_mode_of_the_environment(string $environment, WhatsAppMode $mode): void
    {
        $this->app->detectEnvironment(fn () => $environment);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee(route('staff.whatsapp.take-over'), false)
            ->assertSee('data-initial-mode="'.$mode->value.'"', false)
            ->assertDontSee('نقل الإدارة غير متاح في هذه البيئة حاليًا.');

        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_take_over_happens_only_once(): void
    {
        WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertSessionHasErrors('whatsapp');

        $this->assertSame(1, WhatsAppSetting::count());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_nothing_can_be_edited_before_the_take_over(): void
    {
        $this->asConfirmedAdmin()
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => '123456789'])
            ->assertSessionHasErrors('whatsapp');

        $this->assertSame(0, WhatsAppSetting::count());
    }

    public function test_beneficiary_login_still_works_right_after_the_take_over(): void
    {
        $this->asConfirmedAdmin()->post(route('staff.whatsapp.take-over'), ['confirm' => '1']);
        auth()->logout();
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone])->assertRedirect(route('login.verify'));

        Http::assertNothingSent();
    }

    // --- Non-secret settings -------------------------------------------------------

    public function test_environment_fields_are_saved_and_audited_without_a_password_confirmation(): void
    {
        $setting = WhatsAppSetting::factory()->create(['production_phone_number_id' => null, 'production_display_number' => null]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('staff.whatsapp.environment', 'production'), [
                'phone_number_id' => ' 123456789012345 ',
                'waba_id' => '200000000000002',
                'display_number' => '+966 50 000 0002',
                'template_name' => 'login_code',
                'template_language' => 'ar',
            ])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertSame('123456789012345', $setting->production_phone_number_id);
        $this->assertSame('966500000002', $setting->production_display_number);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SettingsUpdated, $audit->action);
        $this->assertSame(WhatsAppEnvironment::Production, $audit->environment);
        $this->assertSame([
            'production_phone_number_id' => ['old' => null, 'new' => '123456789012345'],
            'production_display_number' => ['old' => null, 'new' => '966500000002'],
        ], $audit->changes);
    }

    public function test_saving_unchanged_fields_records_nothing(): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'test'), [
                'phone_number_id' => $setting->test_phone_number_id,
                'waba_id' => $setting->test_waba_id,
                'display_number' => $setting->test_display_number,
                'template_name' => $setting->test_template_name,
                'template_language' => $setting->test_template_language,
            ])
            ->assertSessionHas('status', 'لم يتغير شيء.');

        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidEnvironmentFields(): array
    {
        return [
            'phone number id with letters' => ['phone_number_id', '12345abc'],
            'waba id with letters' => ['waba_id', 'waba-1'],
            'display number too short' => ['display_number', '1234'],
            'template name with capitals' => ['template_name', 'Login Code'],
            'template language' => ['template_language', 'arabic'],
        ];
    }

    #[DataProvider('invalidEnvironmentFields')]
    public function test_invalid_environment_fields_are_rejected(string $field, string $value): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.environment', 'test'), [$field => $value])
            ->assertSessionHasErrorsIn('test', [$field]);

        $this->assertSame($setting->test_phone_number_id, $setting->fresh()->test_phone_number_id);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_the_same_phone_number_id_cannot_serve_both_environments(): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => $setting->test_phone_number_id])
            ->assertSessionHasErrorsIn('production', ['phone_number_id']);

        $this->assertSame('100000000000002', $setting->fresh()->production_phone_number_id);
    }

    public function test_api_version_is_saved_and_validated(): void
    {
        $setting = WhatsAppSetting::factory()->create(['api_version' => null]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.general'), ['api_version' => '23'])
            ->assertSessionHasErrors('api_version');

        $this->actingAs($admin)->put(route('staff.whatsapp.general'), ['api_version' => 'v24.0'])->assertSessionHasNoErrors();

        $this->assertSame('v24.0', $setting->fresh()->api_version);
        $this->assertSame(['api_version' => ['old' => null, 'new' => 'v24.0']], WhatsAppSettingsAudit::sole()->changes);
    }

    // --- AC-WA-26: secrets need a password confirmation ----------------------------

    public function test_saving_secrets_requires_a_password_confirmation(): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.webhook-token'), ['webhook_verify_token' => 'fake-verify-token'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame('fake-production-token-0002', $setting->fresh()->production_access_token);
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_a_password_confirmed_16_minutes_ago_asks_for_a_new_confirmation(): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertRedirect(route('password.confirm'));

        $setting->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, WhatsAppSetting::count());
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_a_password_confirmed_14_minutes_ago_is_still_enough(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
            ->post(route('staff.whatsapp.take-over'), ['confirm' => '1'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::NEW_TOKEN, WhatsAppSetting::sole()->production_access_token);
    }

    public function test_the_application_wide_password_timeout_stays_three_hours(): void
    {
        $this->assertSame(10800, config('auth.password_timeout'));

        // A route using the plain "password.confirm" middleware keeps the global timeout.
        Route::middleware(['web', 'auth', 'password.confirm'])->get('/__global-password-timeout', fn () => 'ok');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->get('/__global-password-timeout')
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time() - 10800 - 1])
            ->get('/__global-password-timeout')
            ->assertRedirect(route('password.confirm'));
    }

    // --- AC-WA-13, AC-WA-14: access token check and expiry ---------------------------

    public function test_a_token_meta_confirms_is_saved_encrypted_with_its_expiry(): void
    {
        $setting = WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->asConfirmedAdmin($admin)
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://graph.facebook.com/v23.0/debug_token?')
            && $request['input_token'] === self::NEW_TOKEN
            && $request->hasHeader('Authorization', 'Bearer '.self::NEW_TOKEN));

        $setting->refresh();
        $this->assertSame(self::NEW_TOKEN, $setting->production_access_token);
        $this->assertSame(1798761600, $setting->production_token_expires_at->timestamp);
        $this->assertFalse($setting->production_token_never_expires);
        $this->assertSame('meta', $setting->production_token_expiry_source);
        $this->assertNotNull($setting->production_token_checked_at);
        $this->assertSame(['saved' => true, 'last4' => '9876'], array_intersect_key($setting->secretInfo('production_access_token'), ['saved' => 0, 'last4' => 0]));
        $this->assertSame($admin->id, $setting->secretInfo('production_access_token')['updated_by']);

        // The other environment is untouched.
        $this->assertSame('fake-test-token-0001', $setting->test_access_token);

        $raw = DB::table('whatsapp_settings')->value('production_access_token');
        $this->assertStringNotContainsString(self::NEW_TOKEN, $raw);

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SecretReplaced, $audit->action);
        $this->assertSame(['secret' => 'production_access_token', 'replaced' => true, 'last4_old' => null, 'last4_new' => '9876'], $audit->changes);
    }

    public function test_a_zero_expiry_is_stored_as_no_expiry_date(): void
    {
        $this->meta = 'no-expiry';
        $setting = WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['access_token' => self::NEW_TOKEN]);

        $setting->refresh();
        $this->assertTrue($setting->test_token_never_expires);
        $this->assertNull($setting->test_token_expires_at);
        $this->assertSame('meta', $setting->test_token_expiry_source);

        $this->asConfirmedAdmin()->get(route('staff.whatsapp'))->assertSee('بلا تاريخ انتهاء حسب Meta (يبقى خاضعاً للفحص الدوري)');
    }

    public function test_a_missing_expiry_is_stored_as_unknown(): void
    {
        $this->meta = 'unknown-expiry';
        $setting = WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['access_token' => self::NEW_TOKEN]);

        $setting->refresh();
        $this->assertFalse($setting->test_token_never_expires);
        $this->assertNull($setting->test_token_expires_at);
        $this->assertSame('unknown', $setting->test_token_expiry_source);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedTokens(): array
    {
        return [
            'invalid' => ['invalid', 'invalid', 'أفادت Meta بأن الرمز غير صالح، فلم يُحفظ.'],
            'missing messaging scope' => ['missing-scope', 'missing_scope', 'الرمز لا يملك صلاحية إرسال الرسائل (whatsapp_business_messaging)، فلم يُحفظ.'],
            'meta unreachable' => ['unreachable', 'unverifiable', 'تعذّر التحقق من الرمز لدى Meta، فلم يُحفظ. تأكد من الرمز وحاول مرة أخرى.'],
        ];
    }

    #[DataProvider('refusedTokens')]
    public function test_a_refused_token_is_not_saved_and_the_refusal_is_audited(string $meta, string $reason, string $message): void
    {
        $this->meta = $meta;
        $setting = WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN, 'app_secret' => 'fake-app-secret-1234'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasErrorsIn('secrets_production', ['access_token' => $message]);

        $setting->refresh();
        $this->assertSame('fake-production-token-0002', $setting->production_access_token);
        $this->assertNull($setting->production_app_secret, 'nothing in the same form is saved when the token is refused');

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::AccessTokenRejected, $audit->action);
        $this->assertSame(['secret' => 'production_access_token', 'reason' => $reason], $audit->changes);

        // Not flashed into the session, logged or reported anywhere.
        $this->assertStringNotContainsString(self::NEW_TOKEN, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::NEW_TOKEN, implode("\n", $this->logged));
        $this->assertStringNotContainsString('fake-app-secret-1234', json_encode(session()->all()));
    }

    // --- Empty secret fields keep the stored value --------------------------------------

    public function test_empty_secret_fields_keep_the_stored_values(): void
    {
        $setting = WhatsAppSetting::factory()->create(['production_app_secret' => 'fake-app-secret-keep']);

        $this->asConfirmedAdmin()
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => '', 'app_secret' => ''])
            ->assertSessionHas('status', 'لم يتغير شيء: الحقول الفارغة تُبقي القيم المحفوظة.');

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.webhook-token'), ['webhook_verify_token' => '']);

        $setting->refresh();
        $this->assertSame('fake-production-token-0002', $setting->production_access_token);
        $this->assertSame('fake-app-secret-keep', $setting->production_app_secret);
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_app_secret_and_verify_token_are_saved_without_a_meta_call(): void
    {
        $setting = WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['app_secret' => 'fake-app-secret-abcd'])->assertSessionHasNoErrors();
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.webhook-token'), ['webhook_verify_token' => 'fake-verify-token-wxyz'])->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertSame('fake-app-secret-abcd', $setting->test_app_secret);
        $this->assertSame('fake-verify-token-wxyz', $setting->webhook_verify_token);
        Http::assertNothingSent();
        $this->assertSame(
            [['secret' => 'test_app_secret', 'replaced' => false, 'last4_old' => null, 'last4_new' => 'abcd'], ['secret' => 'webhook_verify_token', 'replaced' => false, 'last4_old' => null, 'last4_new' => 'wxyz']],
            WhatsAppSettingsAudit::orderBy('id')->pluck('changes')->all(),
        );
    }

    public function test_replacing_a_secret_records_the_old_and_new_last_four(): void
    {
        WhatsAppSetting::factory()->create();
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['app_secret' => 'fake-app-secret-1111']);
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['app_secret' => 'fake-app-secret-2222']);

        $this->assertSame(
            ['secret' => 'test_app_secret', 'replaced' => true, 'last4_old' => '1111', 'last4_new' => '2222'],
            WhatsAppSettingsAudit::orderByDesc('id')->first()->changes,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedSecrets(): array
    {
        return [
            'token with spaces' => ['access_token', 'EAAG token with spaces in it'],
            'token too short' => ['access_token', 'EAAG123'],
            'app secret too short' => ['app_secret', 'abc'],
        ];
    }

    #[DataProvider('malformedSecrets')]
    public function test_malformed_secrets_are_rejected_before_any_meta_call(string $field, string $value): void
    {
        WhatsAppSetting::factory()->create();

        $this->asConfirmedAdmin()
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.secrets', 'production'), [$field => $value])
            ->assertSessionHasErrorsIn('secrets_production', [$field]);

        Http::assertNothingSent();
        $this->assertStringNotContainsString($value, json_encode(session()->all()));
    }

    // --- AC-WA-11, AC-WA-12: secrets never shown ------------------------------------------

    public function test_the_page_shows_secret_metadata_but_never_a_secret_value(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'مدير الإعدادات']);
        WhatsAppSetting::factory()->create();
        $this->asConfirmedAdmin($admin)->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN, 'app_secret' => 'fake-app-secret-5555']);
        $this->asConfirmedAdmin($admin)->put(route('staff.whatsapp.webhook-token'), ['webhook_verify_token' => 'fake-verify-token-7777']);

        $html = $this->actingAs($admin)->get(route('staff.whatsapp'))
            ->assertOk()
            ->assertSee('••••')
            ->assertSee('9876')
            ->assertSee('5555')
            ->assertSee('7777')
            ->assertSee('مدير الإعدادات')
            ->getContent();

        foreach ([self::NEW_TOKEN, 'fake-app-secret-5555', 'fake-verify-token-7777', 'fake-test-token-0001'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $this->assertStringNotContainsString('value="EAAG', $html);
    }

    public function test_secrets_never_reach_the_audit_trail_or_the_logs(): void
    {
        WhatsAppSetting::factory()->create();
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN, 'app_secret' => 'fake-app-secret-5555']);
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.webhook-token'), ['webhook_verify_token' => 'fake-verify-token-7777']);
        $this->meta = 'unreachable';
        $this->asConfirmedAdmin()->put(route('staff.whatsapp.secrets', 'test'), ['access_token' => 'EAAG-another-fake-token-4321']);

        $audit = json_encode(DB::table('whatsapp_settings_audits')->get());
        $logs = implode("\n", $this->logged);

        foreach ([self::NEW_TOKEN, 'fake-app-secret-5555', 'fake-verify-token-7777', 'EAAG-another-fake-token-4321', 'fake-production-token-0002'] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    // --- AC-WA-41: missing required settings ------------------------------------------------

    public function test_an_alert_names_the_missing_fields_when_sending_is_blocked(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create([
            'production_access_token' => null,
            'production_template_name' => null,
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('staff.whatsapp'))
            ->assertSee('الإرسال متوقف:')
            ->assertSee('إعدادات بيئة الإنتاج ناقصة')
            ->assertSee('Access Token، اسم القالب')
            ->assertDontSee('fake-test-token-0001');

        $setting->forceFill(['production_access_token' => 'fake-production-token-0002', 'production_template_name' => 'login_code'])->save();

        $this->actingAs($admin)->get(route('staff.whatsapp'))->assertDontSee('الإرسال متوقف:');
    }

    public function test_no_missing_settings_alert_when_sending_is_simply_disabled(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Disabled)->create(['production_access_token' => null]);

        $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))->assertDontSee('الإرسال متوقف:');
    }

    public function test_the_alert_is_not_shown_to_anyone_else(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(['production_access_token' => null]);

        $this->actingAs(User::factory()->create())->get(route('staff.whatsapp'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('staff.team'))->assertDontSee('الإرسال متوقف:');
    }

    // --- AC-WA-28: the audit trail is append-only ------------------------------------------------

    public function test_audit_entries_cannot_be_changed_or_deleted(): void
    {
        $audit = WhatsAppSettingsAudit::record(WhatsAppAuditAction::SettingsUpdated, null);

        try {
            $audit->update(['action' => WhatsAppAuditAction::TakeOver]);
            $this->fail('An audit entry was changed.');
        } catch (LogicException) {
        }

        try {
            $audit->delete();
            $this->fail('An audit entry was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(WhatsAppAuditAction::SettingsUpdated, $audit->fresh()->action);
    }

    public function test_no_route_changes_or_deletes_the_audit_trail(): void
    {
        $auditRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit') || str_contains((string) $route->getName(), 'audit'));

        $this->assertCount(0, $auditRoutes);
    }

    public function test_audit_entries_are_not_pruned_while_pruning_is_disabled(): void
    {
        WhatsAppSettingsAudit::record(WhatsAppAuditAction::SettingsUpdated, null);
        DB::table('whatsapp_settings_audits')->update(['created_at' => now()->subDays(400)]);

        $this->artisan('model:prune', ['--model' => [WhatsAppSettingsAudit::class]])->assertSuccessful();

        $this->assertSame(1, WhatsAppSettingsAudit::count());
        $this->assertSame(365, config('whatsapp.retention.settings_audit_days'));
        $this->assertFalse(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'prune')));
    }

    private function asConfirmedAdmin(?User $admin = null): static
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time()]);
    }
}
