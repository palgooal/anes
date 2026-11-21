<?php

namespace Tests\Feature\WhatsApp;

use App\Models\OneTimeCode;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\WhatsAppWebhook;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-8: the webhook verification request and the checks done before anything in a
 * POST is read (REQ-WA-52, REQ-WA-53, REQ-WA-55, REQ-WA-58, AC-WA-32, AC-WA-33,
 * decisions 8.2 and 8.3). Secrets and tokens are placeholders.
 */
class WebhookVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const VERIFY_TOKEN = 'verify-token-fake-0001';

    private const TEST_SECRET = 'fake-test-app-secret-0001';

    private const PRODUCTION_SECRET = 'fake-production-app-secret-0002';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // --- GET (REQ-WA-52, AC-WA-32) ----------------------------------------------------------------

    public function test_the_challenge_is_returned_as_plain_text_when_the_token_matches(): void
    {
        $this->configured();

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=1158201444')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSeeText('1158201444');

        $this->assertStringStartsWith('text/plain', (string) $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=7')->headers->get('Content-Type'));
        $this->assertSame('7', $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=7')->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedVerifications(): array
    {
        return [
            'a wrong token' => ['hub.mode=subscribe&hub.verify_token=wrong-token-0000&hub.challenge=1'],
            'no token' => ['hub.mode=subscribe&hub.challenge=1'],
            'an empty token' => ['hub.mode=subscribe&hub.verify_token=&hub.challenge=1'],
            'another mode' => ['hub.mode=unsubscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=1'],
            'no mode' => ['hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=1'],
            'the token as an array' => ['hub.mode=subscribe&hub.verify_token[]='.self::VERIFY_TOKEN.'&hub.challenge=1'],
        ];
    }

    #[DataProvider('refusedVerifications')]
    public function test_a_wrong_verification_is_refused_with_403(string $query): void
    {
        $this->configured();

        $this->get('/webhooks/whatsapp?'.$query)->assertForbidden()->assertDontSee('1', false);
    }

    public function test_without_a_saved_token_or_a_settings_row_every_verification_is_refused(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=1')->assertForbidden();
        $this->assertSame(0, WhatsAppSetting::count());

        WhatsAppSetting::factory()->create();
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.self::VERIFY_TOKEN.'&hub.challenge=1')->assertForbidden();
    }

    // --- POST: signature (REQ-WA-53, REQ-WA-55, AC-WA-33) ----------------------------------------------

    public function test_a_correctly_signed_body_is_accepted_for_each_environment(): void
    {
        $this->configured();
        $body = '{"object":"whatsapp_business_account","entry":[]}';

        $this->signed($body, self::TEST_SECRET)->assertOk();
        $this->signed($body, self::PRODUCTION_SECRET)->assertOk();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function badSignatures(): array
    {
        $body = self::statusBody();

        return [
            'missing' => [null],
            'empty' => [''],
            'no prefix' => [hash_hmac('sha256', $body, self::PRODUCTION_SECRET)],
            'sha1 prefix' => ['sha1='.hash_hmac('sha256', $body, self::PRODUCTION_SECRET)],
            'too short' => ['sha256='.substr(hash_hmac('sha256', $body, self::PRODUCTION_SECRET), 0, 63)],
            'another secret' => ['sha256='.hash_hmac('sha256', $body, 'some-other-secret-0000')],
            'the signature of another body' => ['sha256='.hash_hmac('sha256', $body.' ', self::PRODUCTION_SECRET)],
            'not hex' => ['sha256='.str_repeat('z', 64)],
        ];
    }

    #[DataProvider('badSignatures')]
    public function test_a_missing_or_wrong_signature_is_refused_with_403_and_changes_nothing(?string $signature): void
    {
        $log = $this->configuredWithMessage();
        $before = $this->state();

        $this->post403(self::statusBody(), $signature);

        $this->assertSame($before, $this->state());
        $this->assertNull($log->fresh()->delivery_status);
    }

    public function test_changing_one_byte_after_signing_is_refused(): void
    {
        $this->configuredWithMessage();
        $body = self::statusBody();
        $signature = 'sha256='.hash_hmac('sha256', $body, self::PRODUCTION_SECRET);
        $before = $this->state();

        $this->post403(substr_replace($body, $body[10] === 'a' ? 'b' : 'a', 10, 1), $signature);

        $this->assertSame($before, $this->state());
    }

    public function test_without_a_saved_app_secret_every_post_is_refused(): void
    {
        WhatsAppSetting::factory()->create();

        $this->signed(self::statusBody(), self::PRODUCTION_SECRET)->assertForbidden();
    }

    public function test_an_unsigned_body_is_refused_before_it_is_parsed(): void
    {
        $this->configured();

        // Not JSON and not signed: 403, not 400, so nothing was decoded.
        $this->post403('{not json', 'sha256='.str_repeat('0', 64));
    }

    // --- JSON after the signature (decision 8.2) ---------------------------------------------------------

    public function test_a_signed_body_that_is_not_json_is_refused_with_400(): void
    {
        $this->configured();

        $this->signed('{"entry": [', self::PRODUCTION_SECRET)->assertStatus(400);
    }

    public function test_signed_json_that_is_not_an_object_is_ignored_with_200(): void
    {
        $this->configured();

        $this->signed('"just a string"', self::PRODUCTION_SECRET)->assertOk();
        $this->signed('[]', self::PRODUCTION_SECRET)->assertOk();
    }

    // --- Size (decision 8.3) -------------------------------------------------------------------------

    public function test_a_body_of_exactly_256_kib_is_accepted_and_one_byte_more_is_refused_with_413(): void
    {
        $this->configured();
        $prefix = '{"entry":[],"padding":"';
        $suffix = '"}';
        $body = $prefix.str_repeat('a', WhatsAppWebhook::MAX_BYTES - strlen($prefix) - strlen($suffix)).$suffix;
        $this->assertSame(WhatsAppWebhook::MAX_BYTES, strlen($body));

        $this->signed($body, self::PRODUCTION_SECRET)->assertOk();

        $larger = $prefix.str_repeat('a', WhatsAppWebhook::MAX_BYTES - strlen($prefix) - strlen($suffix) + 1).$suffix;
        $this->signed($larger, self::PRODUCTION_SECRET)->assertStatus(413);
    }

    public function test_the_actual_size_is_checked_whatever_the_header_says(): void
    {
        $this->configured();
        $body = str_repeat(' ', WhatsAppWebhook::MAX_BYTES + 1);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '10',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::PRODUCTION_SECRET),
        ], $body)->assertStatus(413);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) (WhatsAppWebhook::MAX_BYTES + 1),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=00',
        ], '{}')->assertStatus(413);
    }

    // --- Route, CSRF and rate limit (REQ-WA-58) ----------------------------------------------------------

    public function test_only_the_webhook_routes_are_excluded_from_csrf_protection(): void
    {
        foreach (['whatsapp.webhook', 'whatsapp.webhook.verify'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame([PreventRequestForgery::class], $route->excludedMiddleware());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('throttle:whatsapp-webhook', $route->gatherMiddleware());
            $this->assertNotContains('auth', $route->gatherMiddleware());
        }

        $excluded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(PreventRequestForgery::class, $route->excludedMiddleware(), true))
            ->map(fn ($route) => $route->getName())
            ->values()->all();

        $this->assertEqualsCanonicalizing(['whatsapp.webhook', 'whatsapp.webhook.verify'], $excluded);
    }

    public function test_the_webhook_is_limited_to_600_requests_a_minute_per_address(): void
    {
        $this->configured();

        for ($i = 0; $i < 600; $i++) {
            $this->post403('{}', null);
        }

        $this->postJson('/webhooks/whatsapp', [])->assertStatus(429);
        $this->get('/webhooks/whatsapp')->assertStatus(429);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['REMOTE_ADDR' => '10.0.0.9', 'CONTENT_TYPE' => 'application/json'], '{}')->assertForbidden();
    }

    // --- Helpers ---------------------------------------------------------------------------------------

    public static function statusBody(): string
    {
        return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '966500000002', 'phone_number_id' => '100000000000002'],
            'statuses' => [['id' => 'wamid.WEBHOOK-1', 'status' => 'delivered', 'timestamp' => '1763700000', 'recipient_id' => '966551234567']],
        ]]]]]], JSON_UNESCAPED_SLASHES);
    }

    private function configured(): WhatsAppSetting
    {
        $setting = WhatsAppSetting::factory()->create();
        $setting->setSecret('webhook_verify_token', self::VERIFY_TOKEN, null);
        $setting->setSecret('test_app_secret', self::TEST_SECRET, null);
        $setting->setSecret('production_app_secret', self::PRODUCTION_SECRET, null);
        $setting->save();

        return $setting;
    }

    private function configuredWithMessage(): WhatsAppMessageLog
    {
        $this->configured();

        return WhatsAppMessageLog::factory()->create(['wamid' => 'wamid.WEBHOOK-1', 'one_time_code_id' => OneTimeCode::factory()->create()->id]);
    }

    private function signed(string $body, string $secret): TestResponse
    {
        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function post403(string $body, ?string $signature): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($signature === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => $signature]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $body)->assertForbidden();
    }

    /**
     * The application data a webhook may change (AC-WA-33).
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'logs' => WhatsAppMessageLog::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'codes' => OneTimeCode::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'settings' => WhatsAppSetting::query()->get()->map->getAttributes()->all(),
            'audits' => WhatsAppSettingsAudit::count(),
        ];
    }
}
