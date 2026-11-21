<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ProductionIdentifiersFrozenException;
use App\Services\WhatsApp\WhatsAppSettingsManager;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.4: the production identifiers are frozen while production mode is in
 * force with the database source active (REQ-WA-15, AC-WA-10).
 */
class WhatsAppProductionFreezeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NEW_TOKEN = 'EAAG-rotated-fake-access-token-4321';

    /** Whether the fake debug_token accepts the token. */
    private bool $tokenValid = true;

    /** Everything written to the application log during the test. */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/debug_token*' => fn () => Http::response(['data' => $this->tokenValid
            ? ['is_valid' => true, 'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'], 'expires_at' => 0]
            : ['is_valid' => false, 'scopes' => []],
        ])]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function frozenFields(): array
    {
        return [
            'Phone Number ID' => ['phone_number_id', '100000000000009'],
            'WABA ID' => ['waba_id', '200000000000009'],
            'template name' => ['template_name', 'other_login_code'],
            'template language' => ['template_language', 'en'],
        ];
    }

    // --- Refused while production is in force ---------------------------------------

    #[DataProvider('frozenFields')]
    public function test_a_frozen_identifier_cannot_change_while_production_is_in_force(string $field, string $new): void
    {
        $setting = $this->productionInForce();
        $before = $setting->fresh()->getAttributes();
        $admin = User::factory()->admin()->create();

        // display_number is not frozen, but nothing at all is saved with a refused change.
        $this->actingAs($admin)
            ->put(route('staff.whatsapp.environment', 'production'), [$field => $new, 'display_number' => '966500000099'] + $this->stored())
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasErrors(['frozen', $field], errorBag: 'production');

        $this->assertSame($before, $setting->fresh()->getAttributes());

        $audit = WhatsAppSettingsAudit::sole();
        $this->assertSame(WhatsAppAuditAction::SettingsChangeRefused, $audit->action);
        $this->assertSame(WhatsAppEnvironment::Production, $audit->environment);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame(['reason' => 'production_identifiers_frozen', 'fields' => [$field]], $audit->changes);
    }

    public function test_every_frozen_identifier_changed_at_once_is_refused_and_named(): void
    {
        $setting = $this->productionInForce();
        $before = $setting->fresh()->getAttributes();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), [
                'phone_number_id' => '100000000000009',
                'waba_id' => '200000000000009',
                'display_number' => '966500000002',
                'template_name' => 'other_login_code',
                'template_language' => 'en',
            ])
            ->assertSessionHasErrors(['frozen', 'phone_number_id', 'waba_id', 'template_name', 'template_language'], errorBag: 'production');

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(['phone_number_id', 'waba_id', 'template_name', 'template_language'], WhatsAppSettingsAudit::sole()->changes['fields']);
    }

    public function test_clearing_a_frozen_identifier_is_refused_too(): void
    {
        $setting = $this->productionInForce();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => ''] + $this->stored())
            ->assertSessionHasErrors('phone_number_id', errorBag: 'production');

        $this->assertSame('100000000000002', $setting->fresh()->production_phone_number_id);
    }

    public function test_the_service_refuses_a_direct_call_too(): void
    {
        $setting = $this->productionInForce();
        $before = $setting->fresh()->getAttributes();

        try {
            app(WhatsAppSettingsManager::class)->updateEnvironment($setting, WhatsAppEnvironment::Production, ['waba_id' => '200000000000009'] + $this->stored(), User::factory()->admin()->create(), request());
            $this->fail('A frozen identifier was changed.');
        } catch (ProductionIdentifiersFrozenException $exception) {
            $this->assertSame(['waba_id'], $exception->fields);
        }

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame('200000000000002', $setting->production_waba_id);
        $this->assertSame(1, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SettingsChangeRefused)->count());
    }

    // --- Still allowed ------------------------------------------------------------------

    public function test_unchanged_identifiers_and_the_display_number_can_still_be_saved(): void
    {
        $setting = $this->productionInForce();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), ['display_number' => '966500000099'] + $this->stored())
            ->assertSessionHasNoErrors();

        $this->assertSame('966500000099', $setting->fresh()->production_display_number);
        $this->assertSame(['production_display_number' => ['old' => '966500000002', 'new' => '966500000099']], WhatsAppSettingsAudit::sole()->changes);
    }

    public function test_the_test_environment_can_still_be_changed_while_production_is_in_force(): void
    {
        $setting = $this->productionInForce();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'test'), [
                'phone_number_id' => '100000000000007',
                'waba_id' => '200000000000007',
                'display_number' => '15550000007',
                'template_name' => 'test_login_code',
                'template_language' => 'en',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('100000000000007', $setting->fresh()->test_phone_number_id);
        $this->assertSame(WhatsAppAuditAction::SettingsUpdated, WhatsAppSettingsAudit::sole()->action);
    }

    /**
     * @return array<string, array{WhatsAppMode, bool}>
     */
    public static function otherStates(): array
    {
        return [
            'disabled' => [WhatsAppMode::Disabled, true],
            'test' => [WhatsAppMode::Test, true],
            'log' => [WhatsAppMode::Log, true],
            'production, being prepared' => [WhatsAppMode::Production, false],
        ];
    }

    #[DataProvider('otherStates')]
    public function test_the_production_identifiers_can_change_in_every_other_state(WhatsAppMode $mode, bool $active): void
    {
        $setting = WhatsAppSetting::factory()->mode($mode)->create($active ? [] : ['database_source_activated_at' => null]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), [
                'phone_number_id' => '100000000000009',
                'waba_id' => '200000000000009',
                'display_number' => '966500000002',
                'template_name' => 'other_login_code',
                'template_language' => 'en',
            ])
            ->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertSame('100000000000009', $setting->production_phone_number_id);
        $this->assertSame('en', $setting->production_template_language);
        $this->assertSame(WhatsAppAuditAction::SettingsUpdated, WhatsAppSettingsAudit::sole()->action);
        $this->assertSame($mode, $setting->mode);
        $this->assertSame($active, $setting->isDatabaseSource());
    }

    // --- Access token rotation (REQ-WA-20) ------------------------------------------------

    public function test_the_access_token_can_still_be_rotated_after_debug_token_accepts_it(): void
    {
        $setting = $this->productionInForce();
        $identifiers = $this->identifiers($setting);

        $this->actingAs(User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $setting->refresh();
        $this->assertSame(self::NEW_TOKEN, $setting->production_access_token);
        $this->assertSame($identifiers, $this->identifiers($setting));
        $this->assertSame(WhatsAppMode::Production, $setting->mode);
        $this->assertTrue($setting->isDatabaseSource());
    }

    public function test_a_token_debug_token_refuses_is_still_not_saved(): void
    {
        $this->tokenValid = false;
        $setting = $this->productionInForce();

        $this->actingAs(User::factory()->admin()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('staff.whatsapp.secrets', 'production'), ['access_token' => self::NEW_TOKEN])
            ->assertSessionHasErrors('access_token', errorBag: 'secrets_production');

        $this->assertSame('fake-production-token-0002', $setting->fresh()->production_access_token);
    }

    // --- Checked again under the lock -------------------------------------------------

    public function test_a_switch_to_production_after_the_row_was_read_is_seen_under_the_lock(): void
    {
        // The caller read the row while it was in test mode...
        $stale = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        // ...then production came into force before the write.
        WhatsAppSetting::query()->update(['mode' => WhatsAppMode::Production->value]);
        $before = WhatsAppSetting::sole()->getAttributes();

        $this->expectException(ProductionIdentifiersFrozenException::class);

        try {
            app(WhatsAppSettingsManager::class)->updateEnvironment($stale, WhatsAppEnvironment::Production, ['phone_number_id' => '100000000000009'] + $this->stored(), User::factory()->admin()->create(), request());
        } finally {
            $this->assertSame($before, WhatsAppSetting::sole()->getAttributes());
            $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::SettingsUpdated)->count());
        }
    }

    public function test_a_switch_away_from_production_after_the_row_was_read_is_seen_under_the_lock(): void
    {
        $stale = $this->productionInForce();
        WhatsAppSetting::query()->update(['mode' => WhatsAppMode::Disabled->value]);

        $changed = app(WhatsAppSettingsManager::class)->updateEnvironment($stale, WhatsAppEnvironment::Production, ['phone_number_id' => '100000000000009'] + $this->stored(), User::factory()->admin()->create(), request());

        $this->assertTrue($changed);
        $this->assertSame('100000000000009', WhatsAppSetting::sole()->production_phone_number_id);
        // The caller's copy follows the row as written, mode included.
        $this->assertSame(WhatsAppMode::Disabled, $stale->mode);
    }

    public function test_changes_are_compared_with_the_row_read_under_the_lock(): void
    {
        $stale = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        // Another administrator saved a new WABA ID in the meantime; this request sends the old one.
        WhatsAppSetting::query()->update(['production_waba_id' => '200000000000008', 'mode' => WhatsAppMode::Production->value]);

        $this->expectException(ProductionIdentifiersFrozenException::class);

        try {
            app(WhatsAppSettingsManager::class)->updateEnvironment($stale, WhatsAppEnvironment::Production, $this->stored(), User::factory()->admin()->create(), request());
        } finally {
            $this->assertSame('200000000000008', WhatsAppSetting::sole()->production_waba_id);
        }
    }

    // --- Mode, source and other protections are untouched -----------------------------

    public function test_a_refusal_changes_neither_the_mode_nor_the_otp_source(): void
    {
        $setting = $this->productionInForce();
        $activatedAt = $setting->fresh()->getRawOriginal('database_source_activated_at');

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), ['template_name' => 'other_login_code'] + $this->stored());

        $setting->refresh();
        $this->assertSame(WhatsAppMode::Production, $setting->mode);
        $this->assertSame($activatedAt, $setting->getRawOriginal('database_source_activated_at'));
    }

    public function test_nothing_secret_reaches_the_audit_the_logs_or_the_page(): void
    {
        $this->productionInForce();
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => '100000000000009', 'access_token' => self::NEW_TOKEN] + $this->stored());

        $stored = json_encode(WhatsAppSettingsAudit::sole()->getAttributes());
        foreach (['fake-production-token', 'fake-test-token', self::NEW_TOKEN, '100000000000009', '100000000000002'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored);
        }

        $page = $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))->getContent();
        $this->assertStringNotContainsString('fake-production-token', $page);
        $this->assertStringNotContainsString(self::NEW_TOKEN, $page);
        $this->assertStringNotContainsString('fake-production-token', implode("\n", $this->logged));
        Http::assertNothingSent();
    }

    public function test_the_panel_marks_the_frozen_fields_and_shows_the_stored_values_after_a_refusal(): void
    {
        $this->productionInForce();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => '100000000000009'] + $this->stored())
            ->assertRedirect();

        $this->actingAs($admin)->get(route('staff.whatsapp'))
            ->assertSee('data-frozen-identifiers', false)
            ->assertSee('لم يُحفظ شيء: معرّفات الإنتاج لا تتغير ما دام وضع الإنتاج نافذًا.')
            ->assertSee('id="production-phone_number_id" name="phone_number_id" dir="ltr" value="100000000000002"', false)
            ->assertDontSee('value="100000000000009"', false)
            ->assertSee('id="production-display_number" name="display_number" dir="ltr" value="966500000002" autocomplete="off"  >', false)
            ->assertDontSee('id="test-phone_number_id" name="phone_number_id" dir="ltr" value="100000000000001" autocomplete="off"  readonly', false);
    }

    public function test_the_panel_does_not_mark_anything_frozen_outside_production(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();

        $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))
            ->assertDontSee('data-frozen-identifiers', false)
            ->assertDontSee('readonly', false);
    }

    public function test_only_administrators_reach_the_frozen_fields(): void
    {
        $setting = $this->productionInForce();
        $before = $setting->fresh()->getAttributes();

        $this->actingAs(User::factory()->patient()->create())
            ->put(route('staff.whatsapp.environment', 'production'), ['phone_number_id' => '100000000000009'] + $this->stored())
            ->assertForbidden();

        $this->assertSame($before, $setting->fresh()->getAttributes());
        $this->assertSame(0, WhatsAppSettingsAudit::count());
    }

    /**
     * REQ-WA-64 is lifted (PH-WA-6.9): a row taken over on a server is being prepared,
     * so its production identifiers are not frozen yet (REQ-WA-15 needs an active source).
     */
    #[DataProvider('serverEnvironments')]
    public function test_a_row_taken_over_on_a_server_is_prepared_and_not_frozen(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $admin = User::factory()->admin()->create();

        $setting = app(WhatsAppSettingsManager::class)->takeOver($admin, request());

        $this->assertSame(1, WhatsAppSetting::count());
        $this->assertSame(WhatsAppMode::Disabled, $setting->mode);
        $this->assertFalse($setting->isDatabaseSource());
        $this->assertSame([], app(WhatsAppSettingsManager::class)->frozenFields($setting, WhatsAppEnvironment::Production));

        $this->actingAs($admin)
            ->put(route('staff.whatsapp.environment', 'production'), $this->stored())
            ->assertSessionHasNoErrors();

        $this->assertSame('100000000000002', $setting->fresh()->production_phone_number_id);
        $this->assertFalse($setting->fresh()->isDatabaseSource());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function serverEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    private function productionInForce(): WhatsAppSetting
    {
        return WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
    }

    /**
     * The production fields as the factory stores them.
     *
     * @return array<string, string>
     */
    private function stored(): array
    {
        return [
            'phone_number_id' => '100000000000002',
            'waba_id' => '200000000000002',
            'display_number' => '966500000002',
            'template_name' => 'login_code',
            'template_language' => 'ar',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identifiers(WhatsAppSetting $setting): array
    {
        return array_map(fn (string $field) => $setting->field(WhatsAppEnvironment::Production, $field), array_combine(WhatsAppSettingsManager::FROZEN_PRODUCTION_FIELDS, WhatsAppSettingsManager::FROZEN_PRODUCTION_FIELDS));
    }
}
