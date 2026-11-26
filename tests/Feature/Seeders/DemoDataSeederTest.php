<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\UserRole;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\Support\DemoSeedGuard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PDO;
use RuntimeException;
use Tests\Support\DemoDataSeederOnFile;
use Tests\TestCase;

/**
 * The real guard and seeder (ANIS-SEC-01, F3), on temporary SQLite files only.
 *
 * Each test starts from a valid demo setup (a migrated temporary demo file, APP_ENV
 * local, the opt-in, the log WhatsApp driver, the array mailer) and breaks exactly
 * one condition. The suite's own in-memory database is checked to stay untouched,
 * and database/anis-demo.sqlite is never opened.
 */
class DemoDataSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DEMO = 'anis_demo_test';

    /** Environment variables this test sets, restored afterwards. */
    private const VARIABLES = [DemoSeedGuard::OPT_IN];

    private string $directory;

    private string $demoFile;

    private string $suiteConnection;

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $savedVariables = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARIABLES as $name) {
            $this->savedVariables[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'putenv' => getenv($name)];
        }

        $this->suiteConnection = DB::getDefaultConnection();
        $this->directory = sys_get_temp_dir().'/anis-demo-seed-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->demoFile = $this->directory.'/anis-demo.sqlite';
        touch($this->demoFile);
        DemoDataSeederOnFile::$file = $this->demoFile;

        config(['database.connections.'.self::DEMO => [
            'driver' => 'sqlite',
            'database' => $this->demoFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Artisan::call('migrate', ['--database' => self::DEMO, '--force' => true]);

        $this->useDemoConnection();
        $this->app->detectEnvironment(fn () => 'local');
        $this->setVariable(DemoSeedGuard::OPT_IN, '1');
        config(['services.whatsapp.driver' => 'log', 'mail.default' => 'array']);

        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        // The suite's transaction is on its own connection: switch back before it rolls back.
        config(['database.default' => $this->suiteConnection]);
        DB::setDefaultConnection($this->suiteConnection);
        DB::purge(self::DEMO);
        // Release every PDO handle before deleting the files (required on Windows).
        gc_collect_cycles();

        foreach ($this->savedVariables as $name => $saved) {
            $this->restoreVariable($name, $saved);
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
        DemoDataSeederOnFile::$file = '';

        parent::tearDown();
    }

    public function test_seeds_the_demo_accounts_on_the_isolated_demo_file(): void
    {
        $this->seed(DemoDataSeederOnFile::class);

        $this->assertSame(9, $this->demoUsers());
        $this->assertSame(0, $this->suiteUsers(), 'The suite database must stay untouched.');

        $emails = DB::connection(self::DEMO)->table('users')->whereNotNull('email')->pluck('email')->all();
        $this->assertCount(7, $emails);

        foreach ($emails as $email) {
            $this->assertStringEndsWith('@'.DemoDataSeeder::EMAIL_DOMAIN, $email);
        }

        $this->assertSame(0, DB::connection(self::DEMO)->table('users')->where('email', 'like', '%@demo.sa')->count());
        $this->assertSame(1, User::query()->where('role', UserRole::Admin)->count());
        $this->assertEqualsCanonicalizing(DemoDataSeeder::phones(), User::query()->pluck('phone')->all());

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_seeding_twice_keeps_the_same_accounts(): void
    {
        $this->seed(DemoDataSeederOnFile::class);
        $this->seed(DemoDataSeederOnFile::class);

        $this->assertSame(9, $this->demoUsers());
    }

    public function test_refuses_without_the_opt_in(): void
    {
        $this->setVariable(DemoSeedGuard::OPT_IN, null);

        $this->assertRefused('opt-in');
    }

    public function test_refuses_an_opt_in_other_than_1(): void
    {
        $this->setVariable(DemoSeedGuard::OPT_IN, 'yes');

        $this->assertRefused('opt-in');
    }

    public function test_refuses_in_the_testing_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        $this->assertRefused('not "local"');
    }

    public function test_refuses_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertRefused('not "local"');
    }

    public function test_refuses_a_cached_configuration(): void
    {
        // How Laravel records, when it boots, that the configuration came from its cache.
        $this->app->instance('config_loaded_from_cache', true);

        $this->assertTrue($this->app->configurationIsCached());
        $this->assertRefused('cached');
    }

    public function test_refuses_another_sqlite_file_as_default_connection(): void
    {
        touch($other = $this->directory.'/project.sqlite');
        config(['database.connections.'.self::DEMO.'.database' => $other]);
        DB::purge(self::DEMO);

        $this->assertRefused('not the demo database file');
    }

    public function test_refuses_a_read_connection(): void
    {
        config(['database.connections.'.self::DEMO.'.read' => ['database' => $this->demoFile]]);

        $this->assertRefused('read/write');
    }

    public function test_refuses_when_the_live_write_connection_is_another_file(): void
    {
        touch($other = $this->directory.'/project.sqlite');
        DB::connection(self::DEMO)->setPdo(new PDO('sqlite:'.$other));

        $this->assertRefused('write connection is not open on the demo database file', checkDemo: false);
        $this->assertSame(0, (int) (new PDO('sqlite:'.$other))->query("select count(*) from sqlite_master where name = 'users'")->fetchColumn());
    }

    public function test_refuses_when_the_live_read_connection_is_another_file(): void
    {
        touch($other = $this->directory.'/project.sqlite');
        DB::connection(self::DEMO)->setReadPdo(new PDO('sqlite:'.$other));

        $this->assertRefused('read connection is not open on the demo database file');
    }

    public function test_refuses_an_attached_database(): void
    {
        touch($other = $this->directory.'/project.sqlite');
        DB::connection(self::DEMO)->statement('ATTACH DATABASE '.DB::connection(self::DEMO)->getPdo()->quote($other).' AS project');

        $this->assertRefused('other databases attached');
    }

    public function test_refuses_an_unmigrated_database(): void
    {
        // Windows cannot delete a file SQLite still has open: release the connection,
        // then point the seeder and its connection at a second, empty file instead of
        // deleting and recreating the migrated one.
        DB::purge(self::DEMO);
        touch($empty = $this->directory.'/anis-demo-empty.sqlite');
        DemoDataSeederOnFile::$file = $empty;
        config(['database.connections.'.self::DEMO.'.database' => $empty]);

        $this->assertRefused('not migrated', checkDemo: false);
        $this->assertSame(0, (int) (new PDO('sqlite:'.$empty))->query('select count(*) from sqlite_master')->fetchColumn());
    }

    public function test_refuses_a_database_with_another_account(): void
    {
        User::factory()->create(['email' => 'someone@example.com']);

        $this->assertRefused('not demo accounts', expectedDemoUsers: 1);
    }

    public function test_refuses_a_database_with_a_beneficiary_that_is_not_a_demo_account(): void
    {
        User::factory()->patient()->create(['phone' => '966599999999']);

        $this->assertRefused('not demo accounts', expectedDemoUsers: 1);
    }

    public function test_refuses_stored_whatsapp_settings(): void
    {
        WhatsAppSetting::factory()->create();

        $this->assertRefused('WhatsApp settings');
    }

    public function test_refuses_a_whatsapp_driver_other_than_log(): void
    {
        config(['services.whatsapp.driver' => 'meta']);

        $this->assertRefused('WhatsApp driver');
    }

    public function test_refuses_a_mailer_that_can_deliver(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertRefused('mailer');
    }

    public function test_refuses_a_failover_mailer(): void
    {
        config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['log']]]);

        $this->assertRefused('mailer');
    }

    public function test_the_real_seeder_refuses_everything_but_database_anis_demo_sqlite(): void
    {
        // Same valid setup, but the real seeder only accepts database/anis-demo.sqlite.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the demo database file');

        try {
            $this->app->make(DemoDataSeeder::class)->setContainer($this->app)->__invoke();
        } finally {
            $this->assertSame(0, $this->demoUsers());
            $this->assertSame(0, $this->suiteUsers());
        }
    }

    private function assertRefused(string $reason, bool $checkDemo = true, int $expectedDemoUsers = 0): void
    {
        try {
            $this->app->make(DemoDataSeederOnFile::class)->setContainer($this->app)->__invoke();
            $this->fail('The demo seeder ran although it should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Demo data refused:', $exception->getMessage());
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        if ($checkDemo) {
            $this->assertSame($expectedDemoUsers, $this->demoUsers());
        }

        $this->assertSame(0, $this->suiteUsers(), 'The suite database must stay untouched.');
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    private function useDemoConnection(): void
    {
        config(['database.default' => self::DEMO]);
        DB::setDefaultConnection(self::DEMO);
    }

    private function demoUsers(): int
    {
        return (int) (new PDO('sqlite:'.$this->demoFile))->query('select count(*) from users')->fetchColumn();
    }

    private function suiteUsers(): int
    {
        return DB::connection($this->suiteConnection)->table('users')->count();
    }

    private function setVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * @param  array{server: mixed, env: mixed, putenv: string|false}  $saved
     */
    private function restoreVariable(string $name, array $saved): void
    {
        $saved['server'] === null ? $this->unsetServer($name) : $_SERVER[$name] = $saved['server'];
        $saved['env'] === null ? $this->unsetEnv($name) : $_ENV[$name] = $saved['env'];
        $saved['putenv'] === false ? putenv($name) : putenv("{$name}={$saved['putenv']}");
    }

    private function unsetServer(string $name): void
    {
        unset($_SERVER[$name]);
    }

    private function unsetEnv(string $name): void
    {
        unset($_ENV[$name]);
    }
}
