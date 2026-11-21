<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-7, decision 1: the token alerts of the WhatsApp settings panel, worked out
 * from the stored state when the panel is shown (REQ-WA-22, AC-WA-15, AC-WA-40).
 */
class TokenAlertsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const EXPIRY = '2026-12-31 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function remaining(): array
    {
        return [
            '15 days' => ['-15 days', null],
            'exactly 14 days' => ['-14 days', '14'],
            '8 days' => ['-8 days', '14'],
            'exactly 7 days' => ['-7 days', '7'],
            '4 days' => ['-4 days', '7'],
            'exactly 3 days' => ['-3 days', '3'],
            '2 days' => ['-2 days', '3'],
            'exactly 1 day' => ['-1 day', '1'],
            '12 hours' => ['-12 hours', '1'],
            'the expiry itself' => ['+0 seconds', 'expired'],
            'after the expiry' => ['+2 days', 'expired'],
        ];
    }

    #[DataProvider('remaining')]
    public function test_the_expiry_alert_starts_at_14_days_and_rises_at_7_3_and_1(string $now, ?string $level): void
    {
        $this->token(['production_token_expires_at' => self::EXPIRY, 'production_token_status' => 'valid']);
        Carbon::setTestNow(Carbon::parse(self::EXPIRY)->modify($now));

        $levels = $this->levels($this->panel(), 'production');

        $this->assertSame($level === null ? [] : [$level], $levels);
        // An expiry by date is shown, never written as invalid.
        $this->assertSame('valid', DB::table('whatsapp_settings')->value('production_token_status'));
    }

    public function test_a_token_without_an_expiry_date_has_no_expiry_alert(): void
    {
        $this->token(['production_token_never_expires' => true, 'production_token_expires_at' => null]);

        $this->assertSame([], $this->levels($this->panel(), 'production'));
    }

    public function test_an_invalid_token_is_alerted_at_once(): void
    {
        $this->token(['production_token_status' => 'invalid']);

        $this->panel()
            ->assertSee('data-token-alert="invalid"', false)
            ->assertSee('أفادت Meta بأن Access Token غير صالح');
        $this->assertSame([], $this->levels($this->panel(), 'test'));
    }

    public function test_a_failed_check_is_shown_as_information_never_as_a_revocation(): void
    {
        $this->token(['production_token_status' => 'valid', 'production_token_checked_at' => '2026-11-20 03:17:00']);
        DB::table('whatsapp_settings')->update(['production_token_check_failed_at' => '2026-11-21 03:17:00.123456']);

        $response = $this->panel();

        $this->assertSame(['check_failed'], $this->levels($response, 'production'));
        $response->assertSee('تعذّر فحص الرمز لدى Meta آخر مرة في 2026-11-21 03:17:00 UTC')
            ->assertSee('هذا ليس دليلًا على إبطاله')
            ->assertSee('آخر فحص مؤكد: 2026-11-20 03:17:00 UTC')
            ->assertDontSee('data-token-alert="invalid"', false);
    }

    public function test_no_alert_without_a_saved_token(): void
    {
        WhatsAppSetting::factory()->create(['production_access_token' => null]);
        DB::table('whatsapp_settings')->update(['production_token_status' => 'invalid']);

        $this->assertSame([], $this->levels($this->panel(), 'production'));
    }

    public function test_only_an_administrator_sees_the_panel_and_its_alerts(): void
    {
        $this->token(['production_token_status' => 'invalid']);

        $this->actingAs(User::factory()->create())->get(route('staff.whatsapp'))->assertForbidden();
        $this->actingAs(User::factory()->patient()->create())->get(route('staff.whatsapp'))->assertForbidden();
    }

    public function test_the_env_section_says_that_the_env_token_is_not_monitored(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.whatsapp'))
            ->assertSee('data-env-token-not-monitored', false)
            ->assertSee('ولا تشمل رمز')
            ->assertSee('whatsapp:test --legacy-env');
    }

    public function test_no_value_of_the_token_is_shown(): void
    {
        $setting = $this->token(['production_token_status' => 'invalid', 'production_token_expires_at' => self::EXPIRY]);
        Carbon::setTestNow(Carbon::parse(self::EXPIRY)->subDay());

        $this->panel()->assertDontSee($setting->production_access_token)->assertDontSee($setting->test_access_token);
    }

    // --- Helpers --------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     */
    private function token(array $state): WhatsAppSetting
    {
        $setting = WhatsAppSetting::factory()->create();
        $columns = ['production_token_status', 'production_token_never_expires', 'production_token_expires_at', 'production_token_checked_at'];
        DB::table('whatsapp_settings')->update(array_intersect_key($state, array_flip($columns)));

        return $setting->fresh();
    }

    private function panel(): TestResponse
    {
        return $this->actingAs(User::factory()->admin()->create())->get(route('staff.whatsapp'))->assertOk();
    }

    /**
     * The alert levels shown in the card of one environment.
     *
     * @return list<string>
     */
    private function levels(TestResponse $response, string $environment): array
    {
        $html = $response->getContent();
        $start = strpos($html, 'id="'.$environment.'-title"');
        $this->assertNotFalse($start);
        $card = substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
        preg_match_all('/data-token-alert="([^"]+)"/', $card, $matches);

        return $matches[1];
    }
}
