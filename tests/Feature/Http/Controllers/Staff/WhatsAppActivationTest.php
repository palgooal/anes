<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\MetaGraphClient;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppReadiness;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\UserRole;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-6.7: atomic activation of the database as the OTP source (REQ-WA-66, REQ-WA-67,
 * REQ-WA-24 to 26, REQ-WA-68; AC-WA-09, AC-WA-47 to 50, AC-WA-54).
 */
class WhatsAppActivationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REFERENCE = 'ملف التحقق من الملكية NO-2026-117';

    private const PRODUCTION_TOKEN = 'fake-production-token-0002';

    /** debug_token "data", or 'unreachable'. */
    private array|string $token = ['is_valid' => true, 'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'], 'expires_at' => 0];

    /** @var array<string, mixed> */
    private array $phone = ['id' => '100000000000002', 'display_phone_number' => '+966 50 000 0002'];

    /** @var list<array<string, mixed>> */
    private array $templates = [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION', 'components' => [['type' => 'FOOTER', 'code_expiration_minutes' => 5]]]];

    /** Runs once, during the first call to Meta: a concurrent change while the checks run. */
    private ?Closure $duringMeta = null;

    /** @var list<string> */
    private array $metaCalls = [];

    /** @var list<int> Transaction levels seen during the calls to Meta. */
    private array $levels = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->metaCalls[] = $path;
            $this->levels[] = DB::transactionLevel();

            if ($this->duringMeta) {
                [$callback, $this->duringMeta] = [$this->duringMeta, null];
                $callback();
            }

            if (str_ends_with($path, '/debug_token')) {
                return $this->token === 'unreachable'
                    ? throw new ConnectionException('cURL error 7')
                    : Http::response(['data' => $this->token]);
            }

            if (str_ends_with($path, '/message_templates')) {
                return Http::response(['data' => $this->templates]);
            }

            return Http::response($this->phone);
        }]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    // --- Success ---------------------------------------------------------------------------------

    public function test_production_is_activated_from_a_prepared_row_with_everything_audited(): void
    {
        $setting = $this->prepared();
        $evidence = $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $admin = User::factory()->admin()->create();
        $baseline = DB::transactionLevel();

        // AC-WA-47: before the activation codes are sent with .env.
        $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', $admin)
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'تم التفعيل'));

        $setting->refresh();
        $this->assertSame(WhatsAppMode::Production, $setting->mode);
        $this->assertTrue($setting->isDatabaseSource());

        // Only the mode and the source state changed.
        $after = WhatsAppActivation::storedValues($setting);
        unset($before['mode'], $before['database_source_activated_at'], $after['mode'], $after['database_source_activated_at']);
        $this->assertSame($before, $after);

        // AC-WA-47: right after it, codes are sent with the activated environment.
        $config = app(WhatsAppSettings::class)->current();
        $this->assertSame(WhatsAppConfig::SOURCE_DATABASE, $config->source);
        $this->assertSame(WhatsAppEnvironment::Production, $config->environment);
        $this->assertTrue($config->sendsToMeta());

        // REQ-WA-26: the readiness snapshot, the fingerprint and the reference text.
        $audit = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(WhatsAppEnvironment::Production, $audit->environment);
        $this->assertSame(['old' => 'disabled', 'new' => 'production'], $audit->changes['mode']);
        $this->assertSame(['old' => 'preparing', 'new' => 'active'], $audit->changes['source']);
        $this->assertSame(app(SettingsFingerprint::class)->production($setting), $audit->changes['fingerprint']);
        $this->assertSame($evidence->id, $audit->changes['evidence_audit_id']);
        $this->assertSame(self::REFERENCE, $audit->changes['reference']);
        $this->assertSame(['condition_1', 'condition_2', 'condition_3', 'condition_4', 'condition_5', 'condition_6'], array_keys($audit->changes['readiness']));
        $this->assertSame(['passed'], array_values(array_unique(array_column($audit->changes['readiness'], 'status'))));
        $this->assertNotEmpty($audit->changes['activation_id']);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->count());
        $this->assertNoSecretIn(json_encode($audit->getAttributes()));

        // The checks ran in this attempt, with no transaction open while Meta was called (AC-WA-48).
        $this->assertCount(3, $this->metaCalls);
        $this->assertSame([$baseline, $baseline, $baseline], $this->levels);
    }

    public function test_production_is_activated_from_an_active_test_mode(): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
        $this->evidenceFor($setting);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active')->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertSame(WhatsAppMode::Production, $setting->mode);
        // REQ-WA-65: the column carries the time of the last activation.
        $this->assertNotSame('2026-11-19 09:00:00', $setting->getRawOriginal('database_source_activated_at'));
        $this->assertSame(['old' => 'active', 'new' => 'active'], WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->sole()->changes['source']);
    }

    public function test_test_is_activated_from_a_prepared_row_without_meta(): void
    {
        $setting = $this->prepared();

        $this->activate(WhatsAppMode::Test, WhatsAppMode::Disabled, 'preparing', reference: null, ownership: false)->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertSame(WhatsAppMode::Test, $setting->mode);
        $this->assertTrue($setting->isDatabaseSource());
        $this->assertSame([], $this->metaCalls);

        $changes = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->sole()->changes;
        $this->assertSame(['old' => 'disabled', 'new' => 'test'], $changes['mode']);
        $this->assertArrayNotHasKey('readiness', $changes);
        $this->assertArrayNotHasKey('reference', $changes);
        $this->assertArrayNotHasKey('fingerprint', $changes);
    }

    public function test_log_is_activated_in_the_testing_environment_and_locally(): void
    {
        $setting = $this->prepared();

        $this->activate(WhatsAppMode::Log, WhatsAppMode::Disabled, 'preparing', reference: null, ownership: false)->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::Log, $setting->fresh()->mode);
    }

    // --- Production again from an active source: everything is checked again ---------------------

    /**
     * @return array<string, array{WhatsAppMode}>
     */
    public static function activeModes(): array
    {
        return ['active test' => [WhatsAppMode::Test], 'active disabled' => [WhatsAppMode::Disabled]];
    }

    #[DataProvider('activeModes')]
    public function test_production_from_an_active_source_runs_every_check_again(WhatsAppMode $mode): void
    {
        $setting = $this->active($mode);
        $evidence = $this->evidenceFor($setting);
        $baseline = DB::transactionLevel();

        // An earlier readiness check counts for nothing.
        $this->asConfirmedAdmin()->post(route('staff.whatsapp.readiness'))->assertOk();
        $this->metaCalls = [];
        $this->levels = [];

        $this->activate(WhatsAppMode::Production, $mode, 'active')->assertSessionHasNoErrors();

        // The token, the phone number and the template were checked again, outside any transaction.
        $this->assertCount(3, $this->metaCalls);
        $this->assertTrue(collect($this->metaCalls)->contains(fn (string $path) => str_ends_with($path, '/debug_token')));
        $this->assertTrue(collect($this->metaCalls)->contains(fn (string $path) => str_ends_with($path, '/message_templates')));
        $this->assertSame([$baseline, $baseline, $baseline], $this->levels);

        $changes = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->sole()->changes;
        $this->assertSame(['old' => $mode->value, 'new' => 'production'], $changes['mode']);
        $this->assertSame(['old' => 'active', 'new' => 'active'], $changes['source']);
        $this->assertCount(6, $changes['readiness']);
        $this->assertSame(['passed'], array_values(array_unique(array_column($changes['readiness'], 'status'))));
        $this->assertSame(self::REFERENCE, $changes['reference']);
        $this->assertSame($evidence->id, $changes['evidence_audit_id']);
        $this->assertSame(WhatsAppMode::Production, $setting->fresh()->mode);
    }

    #[DataProvider('failedConditions')]
    public function test_production_from_an_active_source_is_refused_on_any_failed_condition(int $condition, Closure $break): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $this->evidenceFor($setting);
        $break($this, $setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active')
            ->assertOk()
            ->assertSee('data-readiness-check="'.$condition.'" data-status="failed"', false);

        $this->assertNothingActivated($setting, $before);
        $this->assertSame('failed', $this->assertRefusalAudited('readiness_failed')->changes['readiness']['condition_'.$condition]);
    }

    public function test_production_from_an_active_source_needs_a_new_reference_and_acknowledgement(): void
    {
        $setting = $this->active(WhatsAppMode::Test);
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active', reference: null)
            ->assertSessionHasErrors('reference', errorBag: 'activation');
        $this->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active', ownership: false)
            ->assertSessionHasErrors('acknowledge_ownership', errorBag: 'activation');

        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active', null, User::factory()->admin()->create(), request());
            $this->fail('Production was activated again without a reference text.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::REFERENCE_INVALID, $exception->reason);
        }

        $this->assertNothingActivated($setting, $before);
        $this->assertSame([], $this->metaCalls);
        $this->assertRefusalAudited('reference_invalid');
    }

    public function test_production_from_an_active_source_checks_the_evidence_again_under_the_lock(): void
    {
        $this->freezeTime();
        $setting = $this->active(WhatsAppMode::Test);
        $this->evidenceFor($setting, ageMinutes: 24 * 60 - 1);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->checksTakeTwoMinutes();

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Test, 'active')->assertSee('لم يعد اختبار الإرسال المؤكَّد صالحًا');

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited('evidence_lost');
    }

    // --- Refusals that change nothing (AC-WA-47, AC-WA-49) -----------------------------------------

    /**
     * @return array<string, array{string, WhatsAppMode}>
     */
    public static function notAllowedHere(): array
    {
        return [
            'test in a production application' => ['production', WhatsAppMode::Test],
            'log in a production application' => ['production', WhatsAppMode::Log],
            'log in staging' => ['staging', WhatsAppMode::Log],
        ];
    }

    #[DataProvider('notAllowedHere')]
    public function test_a_mode_not_allowed_in_the_application_environment_is_refused(string $environment, WhatsAppMode $to): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $setting = $this->prepared();
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate($to, WhatsAppMode::Disabled, 'preparing', reference: null, ownership: false)
            ->assertSessionHasErrors('activation', errorBag: 'activation');

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited('not_allowed');
    }

    public function test_production_is_allowed_in_a_production_application(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $setting = $this->prepared();
        $this->evidenceFor($setting);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::Production, $setting->fresh()->mode);
    }

    public function test_disabled_is_never_activated(): void
    {
        $setting = $this->prepared();
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate(WhatsAppMode::Disabled, WhatsAppMode::Disabled, 'preparing', reference: null, ownership: false)
            ->assertSessionHasErrors('mode', errorBag: 'activation');
        $this->assertSame(ActivationRefusedException::NOT_ALLOWED, app(WhatsAppActivation::class)->targetProblem($setting, WhatsAppMode::Disabled));

        $this->assertNothingActivated($setting, $before);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    public function test_the_mode_switch_still_refuses_production(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.mode'), ['mode' => 'production', 'expected_mode' => 'test', 'acknowledge_impact' => '1'])
            ->assertSessionHasErrors('mode', errorBag: 'mode');

        $this->assertSame(WhatsAppMode::Test, WhatsAppSetting::sole()->mode);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, WhatsAppMode, WhatsAppMode, string, string}>
     */
    public static function staticRefusals(): array
    {
        return [
            'stale mode' => [[], WhatsAppMode::Test, WhatsAppMode::Production, 'preparing', 'stale'],
            'stale source state' => [[], WhatsAppMode::Disabled, WhatsAppMode::Production, 'active', 'stale'],
            'already active in production' => [['mode' => WhatsAppMode::Production, 'database_source_activated_at' => '2026-11-19 09:00:00'], WhatsAppMode::Production, WhatsAppMode::Production, 'active', 'unchanged'],
            'test once active' => [['mode' => WhatsAppMode::Disabled, 'database_source_activated_at' => '2026-11-19 09:00:00'], WhatsAppMode::Disabled, WhatsAppMode::Test, 'active', 'already_active'],
            'test environment incomplete' => [['test_access_token' => null], WhatsAppMode::Disabled, WhatsAppMode::Test, 'preparing', 'test_incomplete'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('staticRefusals')]
    public function test_refusals_before_any_check(array $attributes, WhatsAppMode $expected, WhatsAppMode $to, string $state, string $reason): void
    {
        $setting = WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Disabled)->create($attributes);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate($to, $expected, $state, reference: $to === WhatsAppMode::Production ? self::REFERENCE : null, ownership: $to === WhatsAppMode::Production)
            ->assertSessionHasErrors('activation', errorBag: 'activation');

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited($reason);
        $this->assertSame([], $this->metaCalls);
    }

    // --- AC-WA-09: each readiness condition on its own -----------------------------------------------

    /**
     * @return array<string, array{int, Closure}>
     */
    public static function failedConditions(): array
    {
        return [
            '(1) WABA ID missing' => [1, fn (self $test, WhatsAppSetting $setting) => $setting->forceFill(['production_waba_id' => null])->save()],
            '(2) token invalid' => [2, fn (self $test) => $test->token = ['is_valid' => false, 'scopes' => []]],
            '(2) token expiring in 6 days' => [2, fn (self $test) => $test->token = ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => now()->addDays(6)->getTimestamp()]],
            '(2) Meta unreachable' => [2, fn (self $test) => $test->token = 'unreachable'],
            '(3) other display number' => [3, fn (self $test) => $test->phone = ['display_phone_number' => '+966 50 000 0009']],
            '(4) template not approved' => [4, fn (self $test) => $test->templates[0]['status'] = 'PENDING'],
            '(4) footer of 10 minutes' => [4, fn (self $test) => $test->templates[0]['components'] = [['type' => 'FOOTER', 'code_expiration_minutes' => 10]]],
            '(5) no evidence' => [5, fn (self $test, WhatsAppSetting $setting) => WhatsAppSettingsAudit::query()->toBase()->delete()],
        ];
    }

    #[DataProvider('failedConditions')]
    public function test_a_failed_readiness_condition_refuses_production(int $condition, Closure $break): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $break($this, $setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('data-activation-refused', false)
            ->assertSee('data-readiness-check="'.$condition.'" data-status="failed"', false)
            ->assertDontSee('data-refusal-not-recorded', false);

        $this->assertNothingActivated($setting, $before);
        $refusal = $this->assertRefusalAudited('readiness_failed');
        $this->assertSame('failed', $refusal->changes['readiness']['condition_'.$condition]);
        $this->assertSame('passed', $refusal->changes['readiness']['condition_6']);
    }

    // --- AC-WA-54 and the reference text (D3) ------------------------------------------------------

    /**
     * @return array<string, array{?string}>
     */
    public static function refusedReferences(): array
    {
        return [
            'missing' => [null],
            'spaces only' => ['     '],
            'too short' => ['abc'],
            'too long' => [str_repeat('م', 501)],
            'two lines' => ["ملف التحقق\nNO-117"],
            'the stored production token' => ['المرجع '.self::PRODUCTION_TOKEN],
            'the stored test token' => ['fake-test-token-0001 في الملف'],
            'a Meta access token' => ['EAAGm0PX4ZCpsBAKZCZBabcdef1234567'],
            'a long key' => ['المفتاح '.str_repeat('Ab3', 12)],
            'a full phone number' => ['جوال المالك +966 55 123 4567'],
            'Arabic-Indic digits' => ['جوال المالك ٩٦٦٥٥١٢٣٤٥٦٧'],
        ];
    }

    #[DataProvider('refusedReferences')]
    public function test_a_reference_text_not_allowed_is_refused_before_anything(?string $reference): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', reference: $reference)
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasErrors('reference', errorBag: 'activation')
            ->assertSessionMissing('_old_input');

        $this->assertNothingActivated($setting, $before);
        $this->assertSame([], $this->metaCalls);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->count());

        // The service refuses it too, and records the refusal without the text.
        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', $reference, User::factory()->admin()->create(), request());
            $this->fail('An invalid reference text was accepted.');
        } catch (ActivationRefusedException $exception) {
            $this->assertSame(ActivationRefusedException::REFERENCE_INVALID, $exception->reason);
        }

        $refusal = $this->assertRefusalAudited('reference_invalid');
        $this->assertNoSecretIn(json_encode($refusal->getAttributes()));

        if (is_string($reference) && trim($reference) !== '') {
            $this->assertStringNotContainsString(trim($reference), json_encode($refusal->getAttributes(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_an_ordinary_reference_is_accepted_and_escaped_on_display(): void
    {
        $this->assertNull(WhatsAppActivation::referenceProblem('مستند الملكية رقم 2026-11-21 لدى الإدارة', $this->prepared()));
        $this->assertNull(WhatsAppActivation::referenceProblem('<b>ملف</b> 117', WhatsAppSetting::sole()));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function missingAcknowledgements(): array
    {
        return [
            'impact' => [['acknowledge_impact' => ''], 'acknowledge_impact'],
            'ownership (REQ-AUTH-37)' => [['acknowledge_ownership' => ''], 'acknowledge_ownership'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('missingAcknowledgements')]
    public function test_the_acknowledgements_are_required_on_the_server(array $override, string $field): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        $this->asConfirmedAdmin()->put(route('staff.whatsapp.activation'), array_filter($override + $this->payload(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')))
            ->assertSessionHasErrors($field, errorBag: 'activation');

        $this->assertNothingActivated($setting, $before);
    }

    // --- AC-WA-48: concurrent changes while the checks run ------------------------------------------

    /**
     * @return array<string, array{Closure}>
     */
    public static function concurrentChanges(): array
    {
        return [
            'a test environment field' => [fn () => WhatsAppSetting::query()->update(['test_waba_id' => '200000000000099']), 'snapshot_changed'],
            'the same token saved again' => [function () {
                $setting = WhatsAppSetting::sole();
                $setting->setSecret('production_access_token', self::PRODUCTION_TOKEN, null);
                $setting->save();
            }, 'snapshot_changed'],
            'secrets re-encrypted after a key rotation' => [function () {
                $old = config('app.key');
                config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher'))), 'app.previous_keys' => [$old]]);
                app()->forgetInstance('encrypter');
                Crypt::clearResolvedInstance('encrypter');
                WhatsAppSetting::encryptUsing(null);
                Artisan::call('whatsapp:reencrypt-secrets');
                // The new key also changes the fingerprint: the evidence fails first, in the checks.
            }, 'readiness_failed'],
            'the mode switched' => [fn () => WhatsAppSetting::query()->update(['mode' => WhatsAppMode::Test->value]), 'snapshot_changed'],
            'the source state changed' => [fn () => WhatsAppSetting::query()->update(['database_source_activated_at' => now()]), 'snapshot_changed'],
            'the token rotated' => [function () {
                $setting = WhatsAppSetting::sole();
                $setting->setSecret('production_access_token', 'fake-rotated-production-token-9999', null);
                $setting->save();
            }, 'snapshot_changed'],
            'a production identifier' => [fn () => WhatsAppSetting::query()->update(['production_template_name' => 'other_login_code']), 'snapshot_changed'],
        ];
    }

    #[DataProvider('concurrentChanges')]
    public function test_a_change_during_the_checks_refuses_without_any_write(Closure $change, string $reason): void
    {
        $this->freezeTime();
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $this->duringMeta = $change;

        $response = $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('data-activation-refused', false);

        if ($reason === 'snapshot_changed') {
            $response->assertSee('تغيّرت الإعدادات المحفوظة أثناء الفحوص');
        }

        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
        $this->assertRefusalAudited($reason);
        $this->assertFalse($this->sameAsActivated(WhatsAppSetting::sole()));
    }

    public function test_a_value_changed_and_restored_before_the_lock_is_not_detected(): void
    {
        // REQ-WA-67: the guarantee is that the activated values are the checked ones, not
        // that every edit is detected.
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $this->duringMeta = function () {
            WhatsAppSetting::query()->update(['test_waba_id' => '200000000000099']);
            WhatsAppSetting::query()->update(['test_waba_id' => '200000000000001']);
        };

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')->assertSessionHasNoErrors();

        $this->assertSame(WhatsAppMode::Production, $setting->fresh()->mode);
    }

    public function test_the_evidence_is_checked_again_under_the_lock(): void
    {
        $this->freezeTime();
        $setting = $this->prepared();
        $this->evidenceFor($setting, ageMinutes: 24 * 60 - 1);

        // The checks take two minutes: the evidence was valid during them, not any more under the lock.
        $this->checksTakeTwoMinutes();

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('لم يعد اختبار الإرسال المؤكَّد صالحًا');

        $this->assertFalse($setting->fresh()->isDatabaseSource());
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
        $this->assertRefusalAudited('evidence_lost');
    }

    public function test_an_earlier_readiness_check_is_never_reused(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);

        $this->asConfirmedAdmin()->post(route('staff.whatsapp.readiness'))->assertOk();
        $this->assertCount(3, $this->metaCalls);

        // Meta now refuses the token: the activation runs its own checks and sees it.
        $this->token = ['is_valid' => false, 'scopes' => []];
        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')->assertSee('data-readiness-check="2" data-status="failed"', false);

        $this->assertCount(6, $this->metaCalls);
        $this->assertFalse($setting->fresh()->isDatabaseSource());
    }

    // --- Database failures (D2, AC-WA-47) ------------------------------------------------------------

    public function test_a_failure_to_write_the_activation_entry_rolls_everything_back(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->failAuditWrites([WhatsAppAuditAction::Activated]);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('خطأ في قاعدة البيانات')
            ->assertDontSee('data-refusal-not-recorded', false);

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited('database_error');
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('WhatsApp activation: database error', $log);
        $this->assertStringNotContainsString('NO-2026-117', $log);
        $this->assertNoSecretIn($log);
    }

    public function test_a_write_stopped_without_an_exception_is_refused_and_rolled_back(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());

        // A saving listener that cancels the write: save() returns false, no exception.
        Event::listen('eloquent.saving: '.WhatsAppSetting::class, fn () => false);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('خطأ في قاعدة البيانات');

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited('database_error');
    }

    public function test_a_refusal_that_cannot_be_recorded_says_so(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->failAuditWrites([WhatsAppAuditAction::Activated, WhatsAppAuditAction::ActivationRefused]);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertOk()
            ->assertSee('data-refusal-not-recorded', false);

        $this->assertNothingActivated($setting, $before);
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->count());
    }

    public function test_a_failed_commit_that_did_not_apply_is_a_refusal(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->failCommit(applied: false);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')->assertSee('خطأ في قاعدة البيانات');

        $this->assertNothingActivated($setting, $before);
        $this->assertRefusalAudited('database_error');
    }

    public function test_a_failed_commit_that_did_apply_is_a_success(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $this->failCommit(applied: true);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'تم التفعيل'));

        $this->assertSame(WhatsAppMode::Production, $setting->fresh()->mode);
        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->count());
    }

    public function test_an_unknown_commit_outcome_is_reported_as_unknown(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $this->failCommit(applied: false, unknown: true);

        $this->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing')
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasErrors(['activation' => 'تعذّر تأكيد نتيجة التفعيل: فشل حفظ المعاملة ولم يمكن قراءة الحالة بعده. لا يُعدّ التفعيل ناجحًا ولا مرفوضًا. راجع مصدر رموز الدخول في هذه الصفحة قبل أي محاولة جديدة.'], errorBag: 'activation')
            ->assertSessionMissing('status');

        // Neither outcome is claimed in the audit trail.
        $this->assertSame(0, WhatsAppSettingsAudit::whereIn('action', [WhatsAppAuditAction::Activated, WhatsAppAuditAction::ActivationRefused])->count());
    }

    // --- Permissions, password, CSRF ---------------------------------------------------------------

    public function test_only_administrators_review_or_activate(): void
    {
        $setting = $this->prepared();
        $before = WhatsAppActivation::storedValues($setting->fresh());

        foreach ([User::factory()->patient()->create(), User::factory()->role(UserRole::Guide)->create()] as $user) {
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
                ->post(route('staff.whatsapp.activation.review'), ['mode' => 'production'])->assertForbidden();
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
                ->put(route('staff.whatsapp.activation'), $this->payload(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing'))->assertForbidden();
        }

        $this->assertNothingActivated($setting, $before);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
        $this->assertSame([], $this->metaCalls);
    }

    public function test_review_and_activation_need_a_password_confirmed_within_15_minutes(): void
    {
        $setting = $this->prepared();
        $this->evidenceFor($setting);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->post(route('staff.whatsapp.activation.review'), ['mode' => 'production'])->assertRedirect(route('password.confirm'));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 16 * 60])
            ->put(route('staff.whatsapp.activation'), $this->payload(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing'))->assertRedirect(route('password.confirm'));
        $this->assertFalse($setting->fresh()->isDatabaseSource());

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 14 * 60])
            ->put(route('staff.whatsapp.activation'), $this->payload(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing'))->assertSessionHasNoErrors();
        $this->assertTrue($setting->fresh()->isDatabaseSource());
    }

    public function test_an_activation_without_a_csrf_token_is_refused(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $setting = $this->prepared();

        $this->activate(WhatsAppMode::Test, WhatsAppMode::Disabled, 'preparing', reference: null, ownership: false)->assertStatus(419);

        $this->assertFalse($setting->fresh()->isDatabaseSource());
    }

    // --- The panel and the review page ------------------------------------------------------------

    public function test_the_panel_offers_the_allowed_modes_only(): void
    {
        $setting = $this->prepared();
        $admin = User::factory()->admin()->create();

        $form = $this->activationForm($admin);
        foreach (['production', 'test', 'log'] as $mode) {
            $this->assertStringContainsString('<option value="'.$mode.'">', $form);
        }
        $this->assertStringNotContainsString('<option value="disabled">', $form);

        $setting->forceFill(['mode' => WhatsAppMode::Test, 'database_source_activated_at' => now()])->save();
        $form = $this->activationForm($admin);
        $this->assertStringContainsString('<option value="production">', $form);
        $this->assertStringNotContainsString('<option value="test">', $form);
        $this->assertStringNotContainsString('<option value="log">', $form);

        $setting->forceFill(['mode' => WhatsAppMode::Production])->save();
        $this->actingAs($admin)->get(route('staff.whatsapp'))
            ->assertDontSee('data-activation>', false)
            ->assertSee('data-activation-done', false);
    }

    public function test_the_review_shows_the_impact_and_asks_nothing_of_meta(): void
    {
        $this->prepared();

        $this->asConfirmedAdmin()->post(route('staff.whatsapp.activation.review'), ['mode' => 'production'])
            ->assertOk()
            ->assertSee('data-activation-review', false)
            ->assertSee('ومنها رمز دخولك أنت')
            ->assertSee('ولا يتوفر بعد أمر استعادة من الخادم')
            ->assertSee('name="reference"', false)
            ->assertSee('name="acknowledge_ownership"', false)
            ->assertSee('name="expected_state" value="preparing"', false)
            ->assertSee('name="expected_mode" value="disabled"', false);

        $this->asConfirmedAdmin()->post(route('staff.whatsapp.activation.review'), ['mode' => 'test'])
            ->assertOk()
            ->assertSee('لا تصل الرموز إلا إلى المستلمين المسموح لهم')
            ->assertDontSee('name="reference"', false);

        $this->assertSame([], $this->metaCalls);
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    // --- Unchanged protections ---------------------------------------------------------------------

    public function test_a_prepared_row_still_leaves_otp_codes_on_env(): void
    {
        $this->prepared();

        $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('serverEnvironments')]
    public function test_req_wa_64_still_blocks_the_take_over(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);

        $this->assertFalse(WhatsAppSettingsManager::takeOverAllowed());
    }

    // --- Helpers ----------------------------------------------------------------------------------

    private function checksTakeTwoMinutes(): void
    {
        $this->app->bind(WhatsAppReadiness::class, fn ($app) => new class($app->make(MetaGraphClient::class), $app->make(SettingsFingerprint::class)) extends WhatsAppReadiness
        {
            public function check(WhatsAppSetting $setting, ?string $ownershipReference = null): array
            {
                $result = parent::check($setting, $ownershipReference);
                Carbon::setTestNow(now()->addMinutes(2));

                return $result;
            }
        });
    }

    private function active(WhatsAppMode $mode): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode($mode)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
    }

    private function prepared(): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Disabled)->create();
    }

    private function evidenceFor(WhatsAppSetting $setting, int $ageMinutes = 5): WhatsAppSettingsAudit
    {
        $audit = WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestVerification, User::factory()->admin()->create(), WhatsAppEnvironment::Production, [
            'origin' => 'panel',
            'recipient_last4' => '4567',
            'outcome' => 'verified',
            'fingerprint' => app(SettingsFingerprint::class)->production($setting),
        ]);
        WhatsAppSettingsAudit::query()->whereKey($audit->getKey())->toBase()->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return $audit;
    }

    private function asConfirmedAdmin(?User $admin = null): static
    {
        return $this->actingAs($admin ?? User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(WhatsAppMode $to, WhatsAppMode $expected, string $state, ?string $reference = self::REFERENCE, bool $ownership = true): array
    {
        return array_filter([
            'mode' => $to->value,
            'expected_mode' => $expected->value,
            'expected_state' => $state,
            'acknowledge_impact' => '1',
            'acknowledge_ownership' => $ownership ? '1' : null,
            'reference' => $reference,
        ], fn ($value) => $value !== null);
    }

    private function activate(WhatsAppMode $to, WhatsAppMode $expected, string $state, ?User $admin = null, ?string $reference = self::REFERENCE, bool $ownership = true): TestResponse
    {
        return $this->asConfirmedAdmin($admin)->put(route('staff.whatsapp.activation'), $this->payload($to, $expected, $state, $reference, $ownership));
    }

    private function activationForm(User $admin): string
    {
        $page = $this->actingAs($admin)->get(route('staff.whatsapp'))->getContent();
        $form = substr($page, (int) strpos($page, 'data-activation>'));

        return substr($form, 0, (int) strpos($form, '</form>'));
    }

    /**
     * @param  array<string, ?string>  $before
     */
    private function assertNothingActivated(WhatsAppSetting $setting, array $before): void
    {
        $this->assertSame($before, WhatsAppActivation::storedValues($setting->fresh()));
        $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());

        if ($before['database_source_activated_at'] === null) {
            $this->assertSame(WhatsAppConfig::SOURCE_ENV, app(WhatsAppSettings::class)->current()->source);
        }
    }

    private function assertRefusalAudited(string $reason): WhatsAppSettingsAudit
    {
        $refusal = WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->latest('id')->first();

        $this->assertNotNull($refusal, 'The refusal was not audited.');
        $this->assertSame($reason, $refusal->changes['reason']);
        $this->assertArrayNotHasKey('reference', $refusal->changes);
        $this->assertNoSecretIn(json_encode($refusal->getAttributes()));

        return $refusal;
    }

    private function sameAsActivated(WhatsAppSetting $setting): bool
    {
        return $setting->isDatabaseSource() && $setting->mode === WhatsAppMode::Production;
    }

    private function assertNoSecretIn(string $text): void
    {
        foreach ([self::PRODUCTION_TOKEN, 'fake-test-token-0001', 'fake-rotated-production-token-9999', '966500000002', '966551234567'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    /**
     * @param  list<WhatsAppAuditAction>  $actions
     */
    private function failAuditWrites(array $actions): void
    {
        Event::listen('eloquent.creating: '.WhatsAppSettingsAudit::class, function (WhatsAppSettingsAudit $audit) use ($actions) {
            if (in_array($audit->action, $actions, true)) {
                throw new RuntimeException('Simulated database failure (bindings: '.self::REFERENCE.' '.self::PRODUCTION_TOKEN.')');
            }
        });
    }

    private function failCommit(bool $applied, bool $unknown = false): void
    {
        $this->app->bind(WhatsAppActivation::class, fn ($app) => new class($app->make(WhatsAppReadiness::class), $app->make(SettingsFingerprint::class), $app->make(WhatsAppSettings::class), $applied, $unknown) extends WhatsAppActivation
        {
            public function __construct(WhatsAppReadiness $readiness, SettingsFingerprint $fingerprint, WhatsAppSettings $settings, private bool $applied, private bool $unknown)
            {
                parent::__construct($readiness, $fingerprint, $settings);
            }

            protected function commit(Connection $connection): void
            {
                if ($this->applied) {
                    parent::commit($connection);
                }

                throw new RuntimeException('Simulated commit failure');
            }

            protected function outcome(mixed $key, array $snapshot, string $attempt): string
            {
                return $this->unknown ? 'unknown' : parent::outcome($key, $snapshot, $attempt);
            }
        });
    }
}
