<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppTokenMonitor;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * PH-WA-7 on a real MariaDB server, with a second connection and committed writes:
 * a result is never applied over a newer one or to a token replaced meanwhile, even
 * when the other write lands between the monitor's read and its UPDATE. Skipped on
 * SQLite, which has no second connection to an in-memory database.
 */
class WhatsAppTokenMonitorConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const T0 = '2026-11-21 03:17:00.000001';

    private ?PDO $other = null;

    private ?Closure $during = null;

    private ?Closure $answer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Needs a second connection to a MariaDB test database.');
        }

        config(['services.whatsapp' => ['driver' => 'meta', 'phone_number_id' => '1122334455', 'access_token' => 'fake-env-token-9911', 'api_version' => 'v23.0', 'login_template' => 'login_code', 'template_language' => 'ar']]);
        Carbon::setTestNow(self::T0);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            if ($this->during) {
                [$callback, $this->during] = [$this->during, null];
                $callback();
            }

            return $this->answer ? ($this->answer)() : Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]);
        }]);

        $config = config('database.connections.'.config('database.default'));
        $this->other = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function test_a_newer_result_committed_elsewhere_is_never_overwritten(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $this->during = fn () => $this->other->exec("update whatsapp_settings set production_token_status = 'invalid', production_token_status_started_at = '2026-11-21 03:17:00.000002'");

        try {
            $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->assertSame(['invalid', '2026-11-21 03:17:00.000002'], $this->otherState());
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_result_of_the_same_start_time_committed_elsewhere_wins(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $this->during = fn () => $this->other->exec("update whatsapp_settings set production_token_status = 'invalid', production_token_status_started_at = '".self::T0."'");

        try {
            $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->assertSame(['invalid', self::T0], $this->otherState());
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_token_replaced_elsewhere_during_the_check_gets_no_result(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create();
        $replacement = $this->other->quote(Crypt::encryptString('fake-production-token-0003'));
        $this->during = fn () => $this->other->exec("update whatsapp_settings set production_access_token = {$replacement}");

        try {
            $this->assertFalse(app(WhatsAppTokenMonitor::class)->check(WhatsAppEnvironment::Production)['applied']);
            $this->assertSame([null, null], $this->otherState());
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_token_replaced_between_the_rejection_read_and_its_update_is_not_marked(): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Production)->create(['database_source_activated_at' => '2026-11-19 09:00:00']);
        $this->answer = fn () => Http::response(['error' => ['code' => 190]], 401);
        $replacement = $this->other->quote(Crypt::encryptString('fake-production-token-0003'));
        $armed = false;
        $this->during = function () use (&$armed) {
            $armed = true;
        };

        // The first read of the settings after the refused send is the monitor's own.
        DB::listen(function (QueryExecuted $query) use (&$armed, $replacement) {
            if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'whatsapp_settings')) {
                $armed = false;
                $this->other->exec("update whatsapp_settings set production_access_token = {$replacement}");
            }
        });

        try {
            $this->post(route('login'), ['phone' => User::factory()->patient()->create()->phone]);

            $this->assertSame([null, null], $this->otherState());
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * @return array{?string, ?string} The production token state as another connection sees it.
     */
    private function otherState(): array
    {
        $row = $this->other->query('select production_token_status, production_token_status_started_at from whatsapp_settings')->fetch(PDO::FETCH_NUM);

        return [$row[0], $row[1] === null ? null : Carbon::parse($row[1])->format('Y-m-d H:i:s.u')];
    }

    /**
     * The migration refuses to roll back while a row is being prepared (PH-WA-6.1).
     */
    private function cleanUp(): void
    {
        $this->other = null;
        DB::table('whatsapp_settings')->delete();
    }
}
