<?php

namespace Tests\Feature\WhatsApp;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Notifications\LoginCodeNotification;
use App\WhatsAppEnvironment;
use App\WhatsAppMessagePurpose;
use App\WhatsAppMode;
use App\WhatsAppSendStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-3 of docs/ANIS-WHATSAPP-SETTINGS-001.md: the message log
 * (AC-WA-29, AC-WA-30, AC-WA-31, AC-WA-37). No request ever reaches Meta.
 */
class MessageLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const RECIPIENT = '966551234567';

    private const MESSAGES_URL = 'graph.facebook.com/v23.0/100000000000002/messages';

    /** Plain codes captured from the (fake) requests to Meta. */
    private array $sentCodes = [];

    /** What the fake Meta answers: 'accept', 'reject', 'server-error' or 'timeout'. */
    private string $meta = 'accept';

    protected function setUp(): void
    {
        parent::setUp();

        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        Http::preventStrayRequests();
        Http::fake([self::MESSAGES_URL => function (Request $request) {
            $this->sentCodes[] = $request['template']['components'][0]['parameters'][0]['text'];

            return match ($this->meta) {
                'accept' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TEST'.count($this->sentCodes)]]]),
                'reject' => Http::response(['error' => ['message' => '(#131030) Recipient '.self::RECIPIENT.' not in allowed list', 'code' => 131030]], 400),
                'server-error' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503),
                'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
            };
        }]);
    }

    // --- AC-WA-29: the four outcomes ----------------------------------------

    public function test_accepted_message_is_logged_with_its_wamid_and_no_delivery_yet(): void
    {
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertRedirect(route('login.verify'));

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppSendStatus::Accepted, $log->send_status);
        $this->assertSame('wamid.TEST1', $log->wamid);
        $this->assertSame(WhatsAppEnvironment::Production, $log->environment);
        $this->assertSame(WhatsAppMessagePurpose::Login, $log->purpose);
        $this->assertSame($patient->id, $log->user_id);
        $this->assertSame(OneTimeCode::latestFor($patient, CodePurpose::Login)->id, $log->one_time_code_id);
        $this->assertSame(WhatsAppMessageLog::recipientHash(self::RECIPIENT), $log->recipient_hash);
        $this->assertSame('4567', $log->recipient_last4);
        $this->assertSame('login_code', $log->template_name);
        $this->assertSame('ar', $log->template_language);
        $this->assertSame(200, $log->http_status);
        $this->assertIsInt($log->duration_ms);
        $this->assertNotNull($log->attempted_at);
        $this->assertNull($log->error_code);

        // Accepted by Meta is not delivered: delivery fields wait for the webhook (PH-WA-8).
        $this->assertNull($log->delivery_status);
        $this->assertNull($log->sent_at);
        $this->assertNull($log->delivered_at);
        $this->assertNull($log->read_at);
        $this->assertNull($log->failed_at);
    }

    public function test_rejected_message_is_logged_with_metas_error(): void
    {
        $this->meta = 'reject';
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasErrors('send');

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppSendStatus::Rejected, $log->send_status);
        $this->assertNull($log->wamid);
        $this->assertSame('131030', $log->error_code);
        $this->assertSame('(#131030) Recipient [recipient] not in allowed list', $log->error_title);
        $this->assertSame(400, $log->http_status);
    }

    public function test_server_error_is_logged_as_uncertain(): void
    {
        $this->meta = 'server-error';
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasErrors('send');

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppSendStatus::Uncertain, $log->send_status);
        $this->assertSame('2', $log->error_code);
        $this->assertSame(503, $log->http_status);
    }

    public function test_timeout_is_logged_as_uncertain(): void
    {
        $this->meta = 'timeout';
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasErrors('send');

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppSendStatus::Uncertain, $log->send_status);
        $this->assertSame('connection_error', $log->error_code);
        $this->assertSame('cURL error 28: Operation timed out', $log->error_title);
        $this->assertNull($log->http_status);
        $this->assertNull($log->wamid);
    }

    public function test_blocked_message_is_logged_without_calling_meta(): void
    {
        WhatsAppSetting::sole()->update(['mode' => WhatsAppMode::Disabled]);
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertSessionHasErrors('send');

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppSendStatus::Blocked, $log->send_status);
        $this->assertSame('disabled', $log->error_code);
        $this->assertNull($log->http_status);
        Http::assertNothingSent();
    }

    // --- Purposes ------------------------------------------------------------

    public function test_staff_two_factor_is_logged_with_its_purpose(): void
    {
        $guide = User::factory()->create();

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.verify'));

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppMessagePurpose::TwoFactor, $log->purpose);
        $this->assertSame($guide->id, $log->user_id);
    }

    public function test_console_test_message_is_logged_as_admin_test_without_a_code_or_user(): void
    {
        $this->artisan('whatsapp:test', ['phone' => '+'.self::RECIPIENT, '--environment' => 'production', '--confirm-production' => true, '--no-interaction' => true])->assertSuccessful();

        $log = WhatsAppMessageLog::sole();
        $this->assertSame(WhatsAppMessagePurpose::AdminTest, $log->purpose);
        $this->assertNull($log->user_id);
        $this->assertNull($log->one_time_code_id);
    }

    public function test_env_settings_are_logged_without_an_environment(): void
    {
        WhatsAppSetting::query()->delete();
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '100000000000002', 'services.whatsapp.access_token' => 'fake-env-token']);

        User::factory()->patient()->create(['phone' => self::RECIPIENT])->notify(new LoginCodeNotification('123456'));

        $this->assertNull(WhatsAppMessageLog::sole()->environment);
    }

    public function test_log_driver_records_nothing(): void
    {
        WhatsAppSetting::sole()->update(['mode' => WhatsAppMode::Log]);

        User::factory()->patient()->create()->notify(new LoginCodeNotification('123456'));

        $this->assertSame(0, WhatsAppMessageLog::count());
    }

    public function test_reissuing_a_code_keeps_the_log_and_unlinks_the_deleted_code(): void
    {
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);
        $this->post(route('login'), ['phone' => $patient->phone]);

        $this->travel(61)->seconds();
        $this->post(route('login.resend'));

        $logs = WhatsAppMessageLog::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->one_time_code_id);
        $this->assertSame(OneTimeCode::latestFor($patient, CodePurpose::Login)->id, $logs[1]->one_time_code_id);
    }

    // --- AC-WA-30: nothing sensitive is stored --------------------------------

    public function test_no_code_token_body_or_full_number_is_ever_stored(): void
    {
        foreach (['accept', 'reject', 'server-error', 'timeout'] as $outcome) {
            $this->meta = $outcome;
            $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);
            $this->post(route('login'), ['phone' => $patient->phone]);
            $patient->forceDelete();
        }

        $stored = json_encode(DB::table('whatsapp_message_logs')->get());

        $this->assertCount(4, $this->sentCodes);
        foreach ($this->sentCodes as $code) {
            $this->assertStringNotContainsString($code, $stored);
        }
        $this->assertStringNotContainsString(self::RECIPIENT, $stored);
        $this->assertStringNotContainsString('551234567', $stored);
        $this->assertStringNotContainsString('fake-production-token-0002', $stored);
        $this->assertStringNotContainsString('messaging_product', $stored);
        $this->assertStringNotContainsString('components', $stored);
        $this->assertStringNotContainsString('"messages"', $stored);
    }

    // --- AC-WA-31: a failing log never blocks or changes the send -------------

    public function test_sending_and_login_continue_when_the_log_cannot_be_written(): void
    {
        $this->breakTheLogStorage();
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->post(route('login'), ['phone' => $patient->phone])->assertRedirect(route('login.verify'));
        $this->post(route('login.verify'), ['code' => $this->sentCodes[0]])->assertRedirect(route('portal'));

        $this->assertAuthenticatedAs($patient);
        $this->assertSame(0, WhatsAppMessageLog::count());
    }

    public function test_a_failing_log_does_not_change_the_original_failure(): void
    {
        $this->breakTheLogStorage();
        $this->meta = 'reject';
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);

        $this->from(route('login'))
            ->post(route('login'), ['phone' => $patient->phone])
            ->assertSessionHasErrors(['send' => 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.']);

        $this->assertSame(WhatsAppSendStatus::Rejected->value, OneTimeCode::latestIssuedFor($patient, CodePurpose::Login)->invalidation_reason);
    }

    public function test_a_failing_log_does_not_hide_metas_rejection_from_the_channel(): void
    {
        $this->breakTheLogStorage();
        $this->meta = 'reject';

        $this->expectException(RequestException::class);

        User::factory()->patient()->create(['phone' => self::RECIPIENT])->notify(new LoginCodeNotification('123456'));
    }

    // --- Unchanged behaviour ----------------------------------------------------

    public function test_the_meta_request_is_unchanged(): void
    {
        User::factory()->patient()->create(['phone' => self::RECIPIENT])->notify(new LoginCodeNotification('012345', 7, WhatsAppMessagePurpose::Login));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer fake-production-token-0002')
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => self::RECIPIENT,
                'type' => 'template',
                'template' => [
                    'name' => 'login_code',
                    'language' => ['code' => 'ar'],
                    'components' => [
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '012345']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '012345']]],
                    ],
                ],
            ]);
    }

    // --- AC-WA-37: retention ---------------------------------------------------

    public function test_retention_periods_come_from_the_settings(): void
    {
        $this->assertSame(90, config('whatsapp.retention.message_logs_days'));
        $this->assertSame(365, config('whatsapp.retention.settings_audit_days'));
        $this->assertFalse(config('whatsapp.retention.pruning_enabled'));
    }

    public function test_nothing_is_pruned_while_pruning_is_disabled(): void
    {
        WhatsAppMessageLog::factory()->create(['created_at' => now()->subDays(400)]);

        $this->artisan('model:prune', ['--model' => [WhatsAppMessageLog::class]])->assertSuccessful();

        $this->assertSame(1, WhatsAppMessageLog::count());
    }

    public function test_once_enabled_only_rows_older_than_the_retention_period_are_pruned(): void
    {
        config(['whatsapp.retention.pruning_enabled' => true, 'whatsapp.retention.message_logs_days' => 90]);
        $old = WhatsAppMessageLog::factory()->create(['created_at' => now()->subDays(91)]);
        $recent = WhatsAppMessageLog::factory()->create(['created_at' => now()->subDays(89)]);

        $this->artisan('model:prune', ['--model' => [WhatsAppMessageLog::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_no_automatic_pruning_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        $this->assertFalse($commands->contains(fn (string $command) => str_contains($command, 'prune')));
    }

    /**
     * Make every write to the message log fail, without changing the schema.
     */
    private function breakTheLogStorage(): void
    {
        WhatsAppMessageLog::creating(fn () => throw new RuntimeException('log storage down'));
    }
}
