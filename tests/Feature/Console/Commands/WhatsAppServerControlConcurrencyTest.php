<?php

namespace Tests\Feature\Console\Commands;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\WhatsAppActivation;
use App\WhatsAppMode;
use ArrayObject;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PH-WA-6.8 on a real MariaDB server, with a second connection and committed
 * transactions: the row lock taken by whatsapp:mode and whatsapp:restore-env, and
 * what another connection sees before and after their commit (REQ-WA-69, REQ-WA-70).
 * Skipped on SQLite, which has no second connection to an in-memory database.
 */
class WhatsAppServerControlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Needs a second connection to a MariaDB test database.');
        }

        config(['services.whatsapp' => [
            'driver' => 'meta',
            'phone_number_id' => '1122334455',
            'access_token' => 'fake-env-token-9911',
            'api_version' => 'v23.0',
            'login_template' => 'login_code',
            'template_language' => 'ar',
        ]]);

        Http::preventStrayRequests();

        $config = config('database.connections.'.config('database.default'));
        $this->other = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function commands(): array
    {
        return [
            'whatsapp:mode' => ['whatsapp:mode', ['mode' => 'disabled', '--confirm' => true], 'mode_changed'],
            'whatsapp:restore-env' => ['whatsapp:restore-env', ['--confirm' => true], 'env_restored'],
        ];
    }

    #[DataProvider('commands')]
    public function test_a_lock_held_elsewhere_fails_without_any_write(string $command, array $options, string $action): void
    {
        $setting = WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        $before = WhatsAppActivation::storedValues($setting->fresh());

        try {
            $this->other->beginTransaction();
            $this->other->query('select id from whatsapp_settings for update')->fetchAll();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            $status = Artisan::call($command, $options);
            $output = Artisan::output();

            $this->other->rollBack();

            $this->assertSame(1, $status);
            $this->assertStringContainsString('خطأ في قاعدة البيانات', $output);
            $this->assertSame($before, WhatsAppActivation::storedValues($setting->fresh()));
            $this->assertSame(0, WhatsAppSettingsAudit::where('action', $action)->count());
        } finally {
            $this->cleanUp();
        }
    }

    #[DataProvider('commands')]
    public function test_another_connection_sees_nothing_before_the_commit_and_everything_after(string $command, array $options, string $action): void
    {
        WhatsAppSetting::factory()->mode(WhatsAppMode::Test)->create();
        $seen = new ArrayObject;

        // Written in the command's transaction, not yet committed.
        Event::listen('eloquent.created: '.WhatsAppSettingsAudit::class, function () use ($seen, $action) {
            $seen['row'] = $this->other->query('select mode, database_source_activated_at is not null as active from whatsapp_settings')->fetch(PDO::FETCH_ASSOC);
            $seen['audits'] = (int) $this->other->query("select count(*) from whatsapp_settings_audits where action = '{$action}'")->fetchColumn();
        });

        try {
            $this->assertSame(0, Artisan::call($command, $options), Artisan::output());

            $this->assertSame(['mode' => 'test', 'active' => 1], ['mode' => $seen['row']['mode'], 'active' => (int) $seen['row']['active']]);
            $this->assertSame(0, $seen['audits']);

            $row = $this->other->query('select mode, database_source_activated_at is not null as active from whatsapp_settings')->fetch(PDO::FETCH_ASSOC);
            $expected = $command === 'whatsapp:mode' ? ['mode' => 'disabled', 'active' => 1] : ['mode' => 'test', 'active' => 0];
            $this->assertSame($expected, ['mode' => $row['mode'], 'active' => (int) $row['active']]);
            $this->assertSame(1, (int) $this->other->query("select count(*) from whatsapp_settings_audits where action = '{$action}'")->fetchColumn());
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * The migration refuses to roll back while a row is being prepared (PH-WA-6.1).
     */
    private function cleanUp(): void
    {
        if ($this->other?->inTransaction()) {
            $this->other->rollBack();
        }

        $this->other = null;
        DB::table('whatsapp_settings')->delete();
    }
}
