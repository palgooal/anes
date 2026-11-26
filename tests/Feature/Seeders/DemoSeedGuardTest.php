<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\Support\DemoSeedGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The guard's decision on the configuration alone (ANIS-SEC-01, F3). The live
 * connection, data and delivery checks are exercised in DemoDataSeederTest.
 */
class DemoSeedGuardTest extends TestCase
{
    private string $directory;

    private string $demoFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/anis-demo-guard-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->demoFile = $this->directory.'/anis-demo.sqlite';
        touch($this->demoFile);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            is_link($file) || is_file($file) ? unlink($file) : null;
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_accepts_the_demo_file_when_every_condition_holds(): void
    {
        $this->assertNull($this->problem());
    }

    public function test_accepts_another_spelling_of_the_same_file(): void
    {
        $this->assertNull($this->problem(connection: ['driver' => 'sqlite', 'database' => $this->directory.'/./../'.basename($this->directory).'/anis-demo.sqlite']));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'no opt-in' => [['optIn' => null], 'opt-in'],
            'opt-in is not 1' => [['optIn' => '0'], 'opt-in'],
            'opt-in is "true"' => [['optIn' => 'true'], 'opt-in'],
            'cached configuration' => [['configCached' => true], 'cached'],
            'production' => [['environment' => 'production'], 'not "local"'],
            'testing' => [['environment' => 'testing'], 'not "local"'],
            'staging' => [['environment' => 'staging'], 'not "local"'],
            'ambiguous default connection' => [['activeDefault' => 'other'], 'ambiguous'],
            'no connection' => [['connection' => null], 'not configured'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('refusals')]
    public function test_refuses_when_one_condition_fails(array $change, string $reason): void
    {
        $this->assertStringContainsString($reason, (string) $this->problem(...$change));
    }

    public function test_refuses_the_project_mysql_database(): void
    {
        $problem = $this->problem(connection: ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'anis']);

        $this->assertStringContainsString('not SQLite', (string) $problem);
    }

    public function test_refuses_mariadb(): void
    {
        $this->assertStringContainsString('not SQLite', (string) $this->problem(connection: ['driver' => 'mariadb', 'database' => 'anis']));
    }

    public function test_refuses_a_connection_url(): void
    {
        $problem = $this->problem(connection: ['driver' => 'sqlite', 'database' => $this->demoFile, 'url' => 'mysql://root@127.0.0.1/anis']);

        $this->assertStringContainsString('URL', (string) $problem);
    }

    public function test_refuses_a_read_write_split(): void
    {
        $problem = $this->problem(connection: ['driver' => 'sqlite', 'database' => $this->demoFile, 'read' => ['database' => '/tmp/other.sqlite']]);

        $this->assertStringContainsString('read/write', (string) $problem);
    }

    public function test_refuses_an_in_memory_database(): void
    {
        $this->assertStringContainsString('not a file', (string) $this->problem(connection: ['driver' => 'sqlite', 'database' => ':memory:']));
    }

    public function test_refuses_a_uri_filename(): void
    {
        $problem = $this->problem(connection: ['driver' => 'sqlite', 'database' => 'file:'.$this->demoFile.'?mode=rwc']);

        $this->assertStringContainsString('not a file', (string) $problem);
    }

    public function test_refuses_another_sqlite_file(): void
    {
        touch($other = $this->directory.'/database.sqlite');

        $this->assertStringContainsString('not the demo database file', (string) $this->problem(connection: ['driver' => 'sqlite', 'database' => $other]));
    }

    public function test_refuses_a_link_to_another_file(): void
    {
        touch($other = $this->directory.'/database.sqlite');
        $link = $this->directory.'/link.sqlite';

        if (! $this->createSymlink($other, $link)) {
            // Windows only lets an account create symbolic links with the
            // SeCreateSymbolicLinkPrivilege (administrator, or Developer Mode). The
            // check itself is unchanged; it runs wherever links can be created.
            $this->markTestSkipped('This system does not allow this account to create symbolic links.');
        }

        $this->assertTrue(is_link($link));
        $this->assertStringContainsString('not the demo database file', (string) $this->problem(connection: ['driver' => 'sqlite', 'database' => $link]));
    }

    public function test_refuses_when_the_demo_file_does_not_exist(): void
    {
        unlink($this->demoFile);

        $this->assertStringContainsString('not the demo database file', (string) $this->problem());
    }

    /**
     * Create a symbolic link, or return false when the system refuses it.
     */
    private function createSymlink(string $target, string $link): bool
    {
        set_error_handler(fn () => true);

        try {
            return symlink($target, $link);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  ?array<string, mixed>  $connection
     */
    private function problem(
        bool $configCached = false,
        string $environment = 'local',
        mixed $optIn = '1',
        string $configuredDefault = 'demo',
        string $activeDefault = 'demo',
        ?array $connection = [],
    ): ?string {
        return DemoSeedGuard::configurationProblem(
            configCached: $configCached,
            environment: $environment,
            optIn: $optIn,
            configuredDefault: $configuredDefault,
            activeDefault: $activeDefault,
            connection: $connection === [] ? ['driver' => 'sqlite', 'database' => $this->demoFile] : $connection,
            databaseFile: $this->demoFile,
        );
    }
}
