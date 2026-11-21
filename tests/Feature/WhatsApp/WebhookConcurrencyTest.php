<?php

namespace Tests\Feature\WhatsApp;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppWebhook;
use App\WhatsAppEnvironment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * PH-WA-8 on a real MariaDB server, with a second connection and committed writes:
 * statuses of one message from two connections never go backwards, and a code used
 * elsewhere is never invalidated by a failed delivery, nor a code invalidated
 * elsewhere used (decisions 8.1 and 8.5). Skipped on SQLite.
 */
class WebhookConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Needs a second connection to a MariaDB test database.');
        }

        Http::preventStrayRequests();
        WhatsAppSetting::factory()->create();

        $config = config('database.connections.'.config('database.default'));
        $this->other = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function test_a_read_committed_elsewhere_is_never_taken_back_by_a_delivered(): void
    {
        $log = WhatsAppMessageLog::factory()->create();
        $this->other->exec("update whatsapp_message_logs set delivery_status = 'read', read_at = now() where id = {$log->id}");

        $this->process($log, 'delivered');

        $this->assertSame('read', $this->otherValue("select delivery_status from whatsapp_message_logs where id = {$log->id}"));
    }

    public function test_a_failed_does_not_follow_a_delivered_committed_elsewhere(): void
    {
        $code = OneTimeCode::factory()->create();
        $log = WhatsAppMessageLog::factory()->create(['one_time_code_id' => $code->id]);
        $this->other->exec("update whatsapp_message_logs set delivery_status = 'delivered' where id = {$log->id}");

        $this->process($log, 'failed');

        $this->assertSame('delivered', $this->otherValue("select delivery_status from whatsapp_message_logs where id = {$log->id}"));
        $this->assertNull($this->otherValue("select invalidated_at from one_time_codes where id = {$code->id}"));
    }

    public function test_a_code_used_elsewhere_after_the_status_was_written_is_not_invalidated(): void
    {
        $code = OneTimeCode::factory()->create();
        $log = WhatsAppMessageLog::factory()->create(['one_time_code_id' => $code->id]);

        // Between the status UPDATE and the code UPDATE, another connection uses the code.
        DB::listen(function (QueryExecuted $query) use ($code, &$done) {
            if (! $done && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'whatsapp_message_logs')) {
                $done = true;
                $this->other->exec("update one_time_codes set consumed_at = now() where id = {$code->id} and consumed_at is null");
            }
        });
        $done = false;

        $this->process($log, 'failed');

        $this->assertNotNull($this->otherValue("select consumed_at from one_time_codes where id = {$code->id}"));
        $this->assertNull($this->otherValue("select invalidated_at from one_time_codes where id = {$code->id}"));
        $this->assertSame('failed', $this->otherValue("select delivery_status from whatsapp_message_logs where id = {$log->id}"));
    }

    public function test_a_code_invalidated_elsewhere_after_it_was_read_is_never_used(): void
    {
        $patient = User::factory()->patient()->create();
        [$code, $plain] = OneTimeCode::issue($patient, CodePurpose::Login);
        $read = OneTimeCode::latestFor($patient, CodePurpose::Login);

        $this->other->exec("update one_time_codes set invalidated_at = now(), invalidation_reason = 'delivery_failed' where id = {$code->id}");

        $this->assertTrue($read->matches($plain), 'The copy read before still looks usable.');
        $this->assertFalse($read->consumeIfUsable());
        $this->assertNull($this->otherValue("select consumed_at from one_time_codes where id = {$code->id}"));
    }

    public function test_only_one_of_two_connections_uses_a_code(): void
    {
        $patient = User::factory()->patient()->create();
        [$code] = OneTimeCode::issue($patient, CodePurpose::Login);

        $first = $this->other->exec("update one_time_codes set consumed_at = now() where id = {$code->id} and consumed_at is null and invalidated_at is null and expires_at > now()");

        $this->assertSame(1, $first);
        $this->assertFalse(OneTimeCode::find($code->id)->consumeIfUsable());
    }

    private function process(WhatsAppMessageLog $log, string $status): void
    {
        app(WhatsAppWebhook::class)->process(['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '100000000000002'],
            'statuses' => [['id' => $log->wamid, 'status' => $status, 'timestamp' => (string) now()->getTimestamp()]],
        ]]]]]], [WhatsAppEnvironment::Production]);
    }

    private function otherValue(string $sql): mixed
    {
        return $this->other->query($sql)->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->other = null;
        DB::table('whatsapp_settings')->delete();

        parent::tearDown();
    }
}
