<?php

namespace Tests\Feature\WhatsApp;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppWebhook;
use App\WhatsAppEnvironment;
use App\WhatsAppMessagePurpose;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-8: delivery statuses, their order, the environment they belong to, the OTP
 * code of a failed message, and the atomic use of a code (REQ-WA-32, REQ-WA-33,
 * REQ-WA-54, REQ-WA-56, REQ-WA-57, AC-WA-18, AC-WA-19, AC-WA-34 to AC-WA-36,
 * decisions 8.1, 8.2, 8.4 and 8.5). Secrets are placeholders.
 */
class WebhookStatusTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TEST_SECRET = 'fake-test-app-secret-0001';

    private const PRODUCTION_SECRET = 'fake-production-app-secret-0002';

    private const TEST_NUMBER = '100000000000001';

    private const PRODUCTION_NUMBER = '100000000000002';

    private const RECIPIENT = '966551234567';

    private string $logFile;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-21 10:00:00');
        Http::preventStrayRequests();

        $this->logFile = tempnam(sys_get_temp_dir(), 'anis-webhook-log-');
        config(['logging.channels.probe' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'], 'logging.default' => 'probe']);
        Log::forgetChannel('probe');
        $this->beforeApplicationDestroyed(fn () => @unlink($this->logFile));
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged[] = $event->message.' '.json_encode($event->context));

        $setting = WhatsAppSetting::factory()->create();
        $setting->setSecret('test_app_secret', self::TEST_SECRET, null);
        $setting->setSecret('production_app_secret', self::PRODUCTION_SECRET, null);
        $setting->save();
    }

    // --- Order and repetition (REQ-WA-56, AC-WA-35, decision 8.1) --------------------------------------

    public function test_statuses_move_forward_and_keep_their_times(): void
    {
        $log = $this->message();

        $this->deliver($log, 'sent', '1795255200')->assertOk();
        $this->deliver($log, 'delivered', '1795255260')->assertOk();
        $this->deliver($log, 'read', '1795255320')->assertOk();

        $log->refresh();
        $this->assertSame('read', $log->delivery_status->value);
        $this->assertSame('2026-11-21 10:00:00', $log->sent_at->toDateTimeString());
        $this->assertSame('2026-11-21 10:01:00', $log->delivered_at->toDateTimeString());
        $this->assertSame('2026-11-21 10:02:00', $log->read_at->toDateTimeString());
        $this->assertSame('accepted', $log->send_status->value);
    }

    public function test_a_late_or_repeated_status_changes_nothing(): void
    {
        $log = $this->message();
        $this->deliver($log, 'read', '1795255320');
        $before = $log->fresh()->getAttributes();

        $this->deliver($log, 'delivered', '1795255380')->assertOk();
        $this->deliver($log, 'sent', '1795255380')->assertOk();
        $this->deliver($log, 'read', '1795255999')->assertOk();

        $this->assertSame($before, $log->fresh()->getAttributes());
    }

    public function test_a_status_may_arrive_before_the_lower_ones(): void
    {
        $log = $this->message();

        $this->deliver($log, 'delivered', '1795255260');
        $this->deliver($log, 'sent', '1795255200');

        $this->assertSame('delivered', $log->fresh()->delivery_status->value);
        $this->assertNull($log->fresh()->sent_at);
    }

    /**
     * @return array<string, array{?string, bool}>
     */
    public static function failedAfter(): array
    {
        return ['no status' => [null, true], 'sent' => ['sent', true], 'delivered' => ['delivered', false], 'read' => ['read', false]];
    }

    #[DataProvider('failedAfter')]
    public function test_failed_is_taken_only_from_no_status_or_sent(?string $previous, bool $taken): void
    {
        $code = $this->code();
        $log = $this->message(['one_time_code_id' => $code->id]);

        if ($previous) {
            $this->deliver($log, $previous, '1795255200');
        }

        $this->deliver($log, 'failed', '1795255260', errors: [['code' => 131026, 'title' => 'Message undeliverable', 'message' => 'to '.self::RECIPIENT]])->assertOk();

        $log->refresh();
        $this->assertSame($taken ? 'failed' : $previous, $log->delivery_status->value);
        $this->assertSame($taken ? '131026' : null, $log->delivery_error_code);
        $this->assertSame($taken, $log->failed_at !== null);
        $this->assertSame($taken, $code->fresh()->invalidated_at !== null);
    }

    public function test_failed_is_final(): void
    {
        $log = $this->message();
        $this->deliver($log, 'failed', '1795255200');
        $before = $log->fresh()->getAttributes();

        foreach (['sent', 'delivered', 'read', 'failed'] as $status) {
            $this->deliver($log, $status, '1795255999')->assertOk();
        }

        $this->assertSame($before, $log->fresh()->getAttributes());
    }

    // --- The time of a status ---------------------------------------------------------------------

    /**
     * @return array<string, array{mixed}>
     */
    public static function badTimestamps(): array
    {
        return ['missing' => [null], 'not a number' => ['soon'], 'zero' => ['0'], 'negative' => ['-5'], 'beyond 2100' => ['9999999999'], 'a float' => ['1795258800.5']];
    }

    #[DataProvider('badTimestamps')]
    public function test_an_invalid_time_from_meta_falls_back_to_the_time_received(mixed $timestamp): void
    {
        $log = $this->message();

        $this->deliver($log, 'delivered', $timestamp);

        $this->assertSame('2026-11-21 10:00:00', $log->fresh()->delivered_at->toDateTimeString());
    }

    // --- Environments (REQ-WA-54, AC-WA-34, decision 8.4) ---------------------------------------------

    public function test_a_number_that_is_not_the_one_of_the_signing_environment_is_ignored(): void
    {
        $log = $this->message();

        // Signed with the test secret, carrying the production number.
        $this->signedPost($this->body([$this->change(self::PRODUCTION_NUMBER, [$this->entry($log, 'delivered')])]), self::TEST_SECRET)->assertOk();

        $this->assertNull($log->fresh()->delivery_status);
    }

    public function test_the_test_secret_cannot_change_a_production_message(): void
    {
        $code = $this->code();
        $log = $this->message(['one_time_code_id' => $code->id]);

        // Signed with the test secret and the test number, about a production message.
        $this->signedPost($this->body([$this->change(self::TEST_NUMBER, [$this->entry($log, 'failed')])]), self::TEST_SECRET)->assertOk();

        $this->assertNull($log->fresh()->delivery_status);
        $this->assertNull($code->fresh()->invalidated_at);
    }

    public function test_a_message_sent_with_the_env_settings_is_ignored(): void
    {
        $code = $this->code();
        $log = $this->message(['environment' => null, 'one_time_code_id' => $code->id]);

        $this->deliver($log, 'failed', '1795255200')->assertOk();

        $this->assertNull($log->fresh()->delivery_status);
        $this->assertNull($code->fresh()->invalidated_at);
    }

    public function test_one_secret_for_both_environments_is_told_apart_by_the_number(): void
    {
        $setting = WhatsAppSetting::sole();
        $setting->setSecret('test_app_secret', self::PRODUCTION_SECRET, null);
        $setting->save();
        $test = $this->message(['environment' => WhatsAppEnvironment::Test]);
        $production = $this->message();

        $this->signedPost($this->body([
            $this->change(self::TEST_NUMBER, [$this->entry($test, 'delivered')]),
            $this->change(self::PRODUCTION_NUMBER, [$this->entry($production, 'read')]),
        ]), self::PRODUCTION_SECRET)->assertOk();

        $this->assertSame('delivered', $test->fresh()->delivery_status->value);
        $this->assertSame('read', $production->fresh()->delivery_status->value);
    }

    public function test_an_unknown_message_or_status_changes_nothing(): void
    {
        $log = $this->message();

        $this->signedPost($this->body([$this->change(self::PRODUCTION_NUMBER, [
            ['id' => 'wamid.NOT-OURS', 'status' => 'delivered', 'timestamp' => '1795255200'],
            ['id' => $log->wamid, 'status' => 'deleted', 'timestamp' => '1795255200'],
            ['id' => $log->wamid, 'status' => ['delivered'], 'timestamp' => '1795255200'],
            ['status' => 'delivered'],
            'not an object',
        ])]))->assertOk();

        $this->assertNull($log->fresh()->delivery_status);
        $this->assertSame(0, WhatsAppMessageLog::where('wamid', 'wamid.NOT-OURS')->count());
    }

    // --- Ignored events (REQ-WA-57, AC-WA-36) --------------------------------------------------------

    public function test_incoming_messages_and_other_fields_are_ignored_with_200_and_never_stored(): void
    {
        $log = $this->message();
        $logs = WhatsAppMessageLog::count();

        $this->signedPost($this->body([
            $this->change(self::PRODUCTION_NUMBER, [], messages: [['from' => self::RECIPIENT, 'id' => 'wamid.IN-1', 'type' => 'text', 'text' => ['body' => 'رمزي هو 482913']]]),
            ['field' => 'account_update', 'value' => ['phone_number' => self::RECIPIENT]],
            ['field' => 'messages'],
        ]))->assertOk();

        $this->assertSame($logs, WhatsAppMessageLog::count());
        $this->assertNull($log->fresh()->delivery_status);
        $this->assertNothingLogged(['482913', 'رمزي', 'wamid.IN-1']);
    }

    // --- The OTP code of a failed message (REQ-WA-32, REQ-WA-33, AC-WA-18, AC-WA-19) -------------------

    /**
     * @return array<string, array{CodePurpose, WhatsAppMessagePurpose}>
     */
    public static function purposes(): array
    {
        return [
            'beneficiary login' => [CodePurpose::Login, WhatsAppMessagePurpose::Login],
            'staff two-factor' => [CodePurpose::TwoFactor, WhatsAppMessagePurpose::TwoFactor],
        ];
    }

    #[DataProvider('purposes')]
    public function test_failed_invalidates_the_code_of_the_message(CodePurpose $codePurpose, WhatsAppMessagePurpose $purpose): void
    {
        $code = $this->code(['purpose' => $codePurpose]);
        $log = $this->message(['one_time_code_id' => $code->id, 'purpose' => $purpose]);

        $this->deliver($log, 'failed', '1795255200');

        $this->assertNotNull($code->fresh()->invalidated_at);
        $this->assertSame(WhatsAppWebhook::INVALIDATION_REASON, $code->fresh()->invalidation_reason);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function codesLeftAlone(): array
    {
        return [
            'already used' => [['consumed_at' => '2026-11-21 09:59:00']],
            'expired' => [['expires_at' => '2026-11-21 09:59:59']],
            'already invalidated' => [['invalidated_at' => '2026-11-21 09:58:00', 'invalidation_reason' => 'rejected']],
        ];
    }

    #[DataProvider('codesLeftAlone')]
    public function test_a_used_expired_or_invalidated_code_is_left_as_it_is(array $state): void
    {
        $code = $this->code($state);
        $log = $this->message(['one_time_code_id' => $code->id]);
        $before = $code->fresh()->getAttributes();

        $this->deliver($log, 'failed', '1795255200');

        $this->assertSame('failed', $log->fresh()->delivery_status->value);
        $this->assertSame($before, $code->fresh()->getAttributes());
    }

    public function test_a_failed_invalidation_rolls_the_status_back_and_answers_500(): void
    {
        $code = $this->code();
        $log = $this->message(['one_time_code_id' => $code->id]);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'one_time_codes')) {
                throw new RuntimeException('storage down '.self::RECIPIENT);
            }
        });

        $this->deliver($log, 'failed', '1795255200')->assertStatus(500);

        $this->assertNull($log->fresh()->delivery_status);
        $this->assertNull($code->fresh()->invalidated_at);
        $this->assertTrue(collect($this->logged)->contains(fn (string $line) => str_starts_with($line, 'WhatsApp webhook: internal error.')));
        $this->assertNothingLogged(['storage down']);
    }

    // --- Decision 8.5: a code is used in one conditional write -----------------------------------------

    public function test_a_code_invalidated_while_it_is_checked_is_refused_with_the_usual_answer(): void
    {
        [$patient, $plain, $code] = $this->beneficiaryOnCodeScreen();
        $log = $this->message(['one_time_code_id' => $code->id]);

        // The failed delivery arrives between the read of the code and its use.
        $once = false;
        Event::listen('eloquent.retrieved: '.OneTimeCode::class, function (OneTimeCode $read) use ($log, &$once) {
            if (! $once) {
                $once = true;
                app(WhatsAppWebhook::class)->process(...$this->processArguments($log, 'failed'));
            }
        });

        $this->post(route('login.verify'), ['code' => $plain])
            ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);

        $this->assertGuest();
        $this->assertNull($code->fresh()->consumed_at);
        $this->assertNotNull($code->fresh()->invalidated_at);
    }

    public function test_a_code_cannot_be_used_twice_even_by_parallel_requests(): void
    {
        [$patient, $plain, $code] = $this->beneficiaryOnCodeScreen();

        // Another request uses the code between this one's read and its write.
        $once = false;
        Event::listen('eloquent.retrieved: '.OneTimeCode::class, function (OneTimeCode $read) use ($code, &$once) {
            if (! $once) {
                $once = true;
                $this->assertTrue(OneTimeCode::find($code->id)->consumeIfUsable());
            }
        });

        $this->post(route('login.verify'), ['code' => $plain])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_valid_code_is_still_used_once_and_signs_in(): void
    {
        [$patient, $plain, $code] = $this->beneficiaryOnCodeScreen();

        $this->post(route('login.verify'), ['code' => $plain])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($patient);
        $this->assertNotNull($code->fresh()->consumed_at);
        $this->assertFalse($code->fresh()->consumeIfUsable());
    }

    public function test_a_refused_use_counts_as_a_failed_attempt(): void
    {
        [$patient, $plain, $code] = $this->beneficiaryOnCodeScreen();
        $code->forceFill(['invalidated_at' => now()])->save();

        $this->post(route('login.verify'), ['code' => $plain])->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        $this->post(route('login.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    // --- No sensitive value -------------------------------------------------------------------------

    public function test_nothing_from_the_body_is_logged_or_stored_but_the_status_its_time_and_code(): void
    {
        $code = $this->code();
        $log = $this->message(['one_time_code_id' => $code->id]);

        $this->deliver($log, 'failed', '1795255200', errors: [['code' => 131026, 'title' => 'Undeliverable to '.self::RECIPIENT, 'message' => 'secret '.self::PRODUCTION_SECRET, 'error_data' => ['details' => 'number '.self::RECIPIENT]]]);

        $this->assertNothingLogged([self::RECIPIENT, self::PRODUCTION_SECRET, self::TEST_SECRET, 'Undeliverable', 'error_data', 'sha256=']);
        $this->assertStringNotContainsString(self::RECIPIENT, json_encode($log->fresh()->getAttributes()));
        $this->assertSame('131026', $log->fresh()->delivery_error_code);
        $this->assertTrue(collect($this->logged)->contains(fn (string $line) => $line === 'WhatsApp webhook: statuses received. {"applied":1,"ignored":0}'));
        Http::assertNothingSent();
    }

    // --- Helpers --------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(array $attributes = []): WhatsAppMessageLog
    {
        return WhatsAppMessageLog::factory()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function code(array $attributes = []): OneTimeCode
    {
        return OneTimeCode::factory()->create($attributes);
    }

    /**
     * @return array{User, string, OneTimeCode}
     */
    private function beneficiaryOnCodeScreen(): array
    {
        $patient = User::factory()->patient()->create(['phone' => self::RECIPIENT]);
        [$code, $plain] = OneTimeCode::issue($patient, CodePurpose::Login);
        $this->withSession(['auth.pending' => ['user_id' => $patient->id, 'purpose' => CodePurpose::Login->value]]);

        return [$patient, $plain, $code];
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    private function deliver(WhatsAppMessageLog $log, string $status, mixed $timestamp, array $errors = []): TestResponse
    {
        $environment = $log->environment ?? WhatsAppEnvironment::Production;
        $number = $environment === WhatsAppEnvironment::Test ? self::TEST_NUMBER : self::PRODUCTION_NUMBER;
        $secret = $environment === WhatsAppEnvironment::Test ? self::TEST_SECRET : self::PRODUCTION_SECRET;

        return $this->signedPost($this->body([$this->change($number, [$this->entry($log, $status, $timestamp, $errors)])]), $secret);
    }

    /**
     * @return array{array<string, mixed>, list<WhatsAppEnvironment>}
     */
    private function processArguments(WhatsAppMessageLog $log, string $status): array
    {
        return [json_decode($this->body([$this->change(self::PRODUCTION_NUMBER, [$this->entry($log, $status)])]), true), [WhatsAppEnvironment::Production]];
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, mixed>
     */
    private function entry(WhatsAppMessageLog $log, string $status, mixed $timestamp = '1795255200', array $errors = []): array
    {
        return array_filter([
            'id' => $log->wamid,
            'status' => $status,
            'timestamp' => $timestamp,
            'recipient_id' => self::RECIPIENT,
            'errors' => $errors ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<mixed>  $statuses
     * @param  list<mixed>  $messages
     * @return array<string, mixed>
     */
    private function change(string $number, array $statuses, array $messages = []): array
    {
        return ['field' => 'messages', 'value' => array_filter([
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '966500000002', 'phone_number_id' => $number],
            'statuses' => $statuses ?: null,
            'messages' => $messages ?: null,
        ])];
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function body(array $changes): string
    {
        return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => $changes]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function signedPost(string $body, string $secret = self::PRODUCTION_SECRET): TestResponse
    {
        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    /**
     * @param  list<string>  $values
     */
    private function assertNothingLogged(array $values): void
    {
        $file = (string) file_get_contents($this->logFile);
        $events = implode("\n", $this->logged);

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $file, 'log file: '.$value);
            $this->assertStringNotContainsString($value, $events, 'log events: '.$value);
        }
    }
}
