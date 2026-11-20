<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

/**
 * Regression: the tests refuse any database that is not an isolated test database,
 * before any migration (TestDatabaseGuard).
 */
class TestDatabaseGuardTest extends TestCase
{
    private const PROJECT = ['database' => 'anis', 'username' => 'root'];

    // --- Configuration rules (no connection) ---------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function allowedConfigurations(): array
    {
        return [
            'SQLite in memory' => [['driver' => 'sqlite', 'database' => ':memory:', 'url' => '']],
            'SQLite in memory, no URL key' => [['driver' => 'sqlite', 'database' => ':memory:']],
            'temporary MariaDB' => [self::mariadb()],
            'temporary MySQL driver' => [self::mariadb(['driver' => 'mysql'])],
            'localhost' => [self::mariadb(['host' => 'localhost'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    #[DataProvider('allowedConfigurations')]
    public function test_allowed_configurations(array $connection): void
    {
        $this->assertNull(TestDatabaseGuard::configurationProblem(false, 'testing', $connection, '1', self::PROJECT));
    }

    /**
     * @return array<string, array{bool, string, ?array<string, mixed>, mixed, ?array<string, string>, string}>
     */
    public static function refusedConfigurations(): array
    {
        $mariadb = self::mariadb();

        return [
            'cached configuration' => [true, 'testing', ['driver' => 'sqlite', 'database' => ':memory:'], null, self::PROJECT, 'cached'],
            'local environment' => [false, 'local', ['driver' => 'sqlite', 'database' => ':memory:'], null, self::PROJECT, 'not "testing"'],
            'production environment' => [false, 'production', $mariadb, '1', self::PROJECT, 'not "testing"'],
            'no connection' => [false, 'testing', null, '1', self::PROJECT, 'not configured'],
            'connection URL' => [false, 'testing', ['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'mysql://root@127.0.0.1/anis'], null, self::PROJECT, 'URL'],
            'SQLite file' => [false, 'testing', ['driver' => 'sqlite', 'database' => '/var/www/database.sqlite'], null, self::PROJECT, 'in memory'],
            'PostgreSQL' => [false, 'testing', ['driver' => 'pgsql', 'database' => 'anes_tmp_x'], '1', self::PROJECT, 'not allowed'],
            'MariaDB without opt-in' => [false, 'testing', $mariadb, null, self::PROJECT, 'opt-in'],
            'MariaDB with opt-in other than 1' => [false, 'testing', $mariadb, 'true', self::PROJECT, 'opt-in'],
            'remote host' => [false, 'testing', self::mariadb(['host' => '10.0.0.5']), '1', self::PROJECT, 'loopback'],
            'socket' => [false, 'testing', self::mariadb(['unix_socket' => '/run/mysqld/mysqld.sock']), '1', self::PROJECT, 'socket'],
            'read/write connections' => [false, 'testing', self::mariadb(['read' => ['host' => ['127.0.0.1']]]), '1', self::PROJECT, 'read/write'],
            'project database name' => [false, 'testing', self::mariadb(['database' => 'anis']), '1', self::PROJECT, 'anes_tmp_'],
            'name without the prefix' => [false, 'testing', self::mariadb(['database' => 'anis_test']), '1', self::PROJECT, 'anes_tmp_'],
            'prefix only' => [false, 'testing', self::mariadb(['database' => 'anes_tmp_']), '1', self::PROJECT, 'anes_tmp_'],
            'same database as .env' => [false, 'testing', $mariadb, '1', ['database' => 'ANES_TMP_ONE', 'username' => 'root'], "project's database"],
            'project user' => [false, 'testing', self::mariadb(['username' => 'root']), '1', self::PROJECT, "project's database user"],
            'empty user' => [false, 'testing', self::mariadb(['username' => '']), '1', self::PROJECT, 'empty'],
            'unreadable .env' => [false, 'testing', $mariadb, '1', null, '.env'],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $connection
     * @param  ?array<string, string>  $project
     */
    #[DataProvider('refusedConfigurations')]
    public function test_refused_configurations(bool $cached, string $environment, ?array $connection, mixed $optIn, ?array $project, string $reason): void
    {
        $problem = TestDatabaseGuard::configurationProblem($cached, $environment, $connection, $optIn, $project);

        $this->assertNotNull($problem);
        $this->assertStringContainsString($reason, $problem);
    }

    // --- Effective privileges (facts as MariaDB reports them) -----------------------------------

    public function test_a_user_limited_to_temporary_databases_is_allowed(): void
    {
        $this->assertNull(TestDatabaseGuard::privilegeProblem(self::facts(), 'anes_tmp_one', 'anis'));

        $this->assertNull(TestDatabaseGuard::privilegeProblem(self::facts([
            'schema_privileges' => [self::schemaRow('anes\\_tmp\\_%')],
        ]), 'anes_tmp_one', 'anis'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedPrivileges(): array
    {
        $other = "'mysql'@'localhost'";

        return [
            'direct role' => [['roles' => [['GRANTEE' => 'anes_tmp@127.0.0.1', 'ROLE_NAME' => 'r_any', 'IS_GRANTABLE' => 'NO', 'IS_DEFAULT' => 'NO']]], 'roles'],
            'enabled role' => [['enabled_roles' => ['r_any']], 'roles'],
            'proxy' => [['grants' => ["GRANT PROXY ON ''@'%' TO `anes_tmp`@`127.0.0.1`"]], 'cannot be explained'],
            'routine grant' => [['grants' => ['GRANT EXECUTE ON PROCEDURE `anes\\_tmp\\_one`.`p` TO `anes_tmp`@`127.0.0.1`']], 'routines'],
            'routine defined by another account' => [['foreign_definers' => ['root@localhost']], 'defined by another account'],
            'global privilege' => [['user_privileges' => [self::userRow('SELECT')]], 'global privilege'],
            'SHOW DATABASES' => [['user_privileges' => [self::userRow('SHOW DATABASES')]], 'global privilege'],
            'grantable usage' => [['user_privileges' => [self::userRow('USAGE', 'YES')]], 'global privilege'],
            'sees other accounts' => [['user_privileges' => [self::userRow('USAGE'), self::userRow('SELECT', 'NO', $other)]], 'other accounts'],
            'sees other schema grants' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_one'), self::schemaRow('x', 'NO', $other)]], 'other accounts'],
            'project database' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_one'), self::schemaRow('anis')]], 'not anes_tmp_*'],
            'unescaped prefix' => [['schema_privileges' => [self::schemaRow('anes_tmp_one')]], 'not anes_tmp_*'],
            'one unescaped underscore' => [['schema_privileges' => [self::schemaRow('anes\\_tmp_one')]], 'not anes_tmp_*'],
            'upper-case prefix' => [['schema_privileges' => [self::schemaRow('ANES\\_TMP\\_one')]], 'not anes_tmp_*'],
            'prefix only' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_')]], 'not anes_tmp_*'],
            'project database matched by a temporary pattern' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_%')], 'grants' => []], "project's database", 'anes_tmp_prod'],
            'wildcard everything' => [['schema_privileges' => [self::schemaRow('%')]], 'not anes_tmp_*'],
            'wildcard inside the prefix' => [['schema_privileges' => [self::schemaRow('anes%')]], 'not anes_tmp_*'],
            'wildcard instead of a letter' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_one'), self::schemaRow('_nes\\_tmp\\_x')]], 'not anes_tmp_*'],
            'table on the project database' => [['table_privileges' => [self::schemaRow('anis')]], 'not anes_tmp_*'],
            'column on the project database' => [['column_privileges' => [self::schemaRow('anis')]], 'not anes_tmp_*'],
            'with grant option' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_one', 'YES')]], 'grant'],
            'sees the project database' => [['visible_schemas' => ['information_schema', 'anes_tmp_one', 'anis']], 'can see'],
            'sees mysql' => [['visible_schemas' => ['information_schema', 'mysql']], 'can see'],
            'sees an upper-case look-alike' => [['visible_schemas' => ['information_schema', 'anes_tmp_one', 'ANES_TMP_ONE']], 'can see'],
            'no privilege on the test database' => [['schema_privileges' => [self::schemaRow('anes\\_tmp\\_other')]], 'cannot be explained'],
            'unknown user' => [['current_user' => 'anes_tmp'], 'identified'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('refusedPrivileges')]
    public function test_refused_privileges(array $override, string $reason, string $project = 'anis'): void
    {
        $problem = TestDatabaseGuard::privilegeProblem(self::facts($override), 'anes_tmp_one', $project);

        $this->assertNotNull($problem);
        $this->assertStringContainsString($reason, $problem);
    }

    /**
     * SHOW GRANTS lines as MariaDB prints them for the user anes_tmp@127.0.0.1.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function grantLines(): array
    {
        $to = ' TO `anes_tmp`@`127.0.0.1`';

        return [
            'usage' => ['GRANT USAGE ON *.*'.$to, null],
            'usage with password' => ["GRANT USAGE ON *.*{$to} IDENTIFIED BY PASSWORD '*4EBE5C9776BCF0904B4F3E69444005AAC9A025AE'", null],
            'usage with a plugin' => ["GRANT USAGE ON *.*{$to} IDENTIFIED VIA ed25519 USING 'abc'", null],
            'escaped prefix and wildcard' => ['GRANT ALL PRIVILEGES ON `anes\\_tmp\\_%`.*'.$to, null],
            'one escaped database' => ['GRANT SELECT, INSERT ON `anes\\_tmp\\_one`.*'.$to, null],
            'table in a temporary database' => ['GRANT SELECT ON `anes\\_tmp\\_one`.`users`'.$to, null],
            'unescaped prefix' => ['GRANT ALL PRIVILEGES ON `anes_tmp_one`.*'.$to, 'not anes_tmp_*'],
            'unescaped prefix and wildcard' => ['GRANT ALL PRIVILEGES ON `anes_tmp_%`.*'.$to, 'not anes_tmp_*'],
            'everything' => ['GRANT ALL PRIVILEGES ON `%`.*'.$to, 'not anes_tmp_*'],
            'project database' => ['GRANT SELECT ON `anis`.*'.$to, 'not anes_tmp_*'],
            'table in the project database' => ['GRANT SELECT ON `anis`.`users`'.$to, 'not anes_tmp_*'],
            'global privilege' => ['GRANT SELECT ON *.*'.$to, 'cannot be explained'],
            'procedure' => ['GRANT EXECUTE ON PROCEDURE `anes\\_tmp\\_one`.`p`'.$to, 'routines'],
            'function' => ['GRANT EXECUTE ON FUNCTION `anis`.`f`'.$to, 'routines'],
            'package' => ['GRANT EXECUTE ON PACKAGE `anis`.`p`'.$to, 'routines'],
            'package body' => ['GRANT EXECUTE ON PACKAGE BODY `anis`.`p`'.$to, 'routines'],
            'proxy' => ["GRANT PROXY ON ''@'%'".$to, 'cannot be explained'],
            'role' => ['GRANT `r_any`'.$to, 'cannot be explained'],
            'default role' => ['SET DEFAULT ROLE `r_any` FOR `anes_tmp`@`127.0.0.1`', 'cannot be explained'],
            'with grant option' => ['GRANT ALL PRIVILEGES ON `anes\\_tmp\\_%`.*'.$to.' WITH GRANT OPTION', 'cannot be explained'],
            'another account' => ['GRANT ALL PRIVILEGES ON `anes\\_tmp\\_%`.* TO `root`@`localhost`', 'cannot be explained'],
            'unknown form' => ['GRANT SOMETHING NEW', 'cannot be explained'],
        ];
    }

    #[DataProvider('grantLines')]
    public function test_every_grant_must_be_explained(string $grant, ?string $reason): void
    {
        $problem = TestDatabaseGuard::grantProblem($grant, 'anes_tmp@127.0.0.1', 'anis');

        if ($reason === null) {
            $this->assertNull($problem);
        } else {
            $this->assertNotNull($problem);
            $this->assertStringContainsString($reason, $problem);
        }
    }

    public function test_only_patterns_that_cannot_leave_the_prefix_are_temporary(): void
    {
        foreach (['anes\\_tmp\\_one', 'anes\\_tmp\\_%', 'anes\\_tmp\\__', 'anes\\_tmp\\_x%y'] as $pattern) {
            $this->assertTrue(TestDatabaseGuard::patternIsTemporary($pattern), $pattern);
        }

        foreach (['anes_tmp_one', 'anes\\_tmp_one', 'anes_tmp\\_one', 'anes\\_tmp\\_', 'anes%', '%', '_nes\\_tmp\\_x', 'ANES\\_TMP\\_one', 'Anes\\_tmp\\_x'] as $pattern) {
            $this->assertFalse(TestDatabaseGuard::patternIsTemporary($pattern), $pattern);
        }
    }

    public function test_grant_patterns_are_matched_as_mysql_does(): void
    {
        $this->assertTrue(TestDatabaseGuard::likeMatches('anes_tmp_%', 'anes_tmp_one'));
        $this->assertTrue(TestDatabaseGuard::likeMatches('anes_tmp_one', 'anesXtmpYone'));
        $this->assertFalse(TestDatabaseGuard::likeMatches('anes\_tmp\_one', 'anesXtmpYone'));
        $this->assertTrue(TestDatabaseGuard::likeMatches('%', 'anis'));
        $this->assertTrue(TestDatabaseGuard::likeMatches('AN_S', 'anis'));
        $this->assertFalse(TestDatabaseGuard::likeMatches('anes_tmp_%', 'anis'));
    }

    // --- The refusal comes before any table (separate PHPUnit runs) ------------------------------

    public function test_an_exported_sqlite_file_is_refused_before_any_table(): void
    {
        $file = $this->sentinel();

        $this->assertRefusedBeforeTables(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file], 'SQLite is only allowed in memory', $file);
    }

    public function test_a_cached_configuration_is_refused_before_any_table(): void
    {
        $file = $this->sentinel();
        $config = config()->all();
        $config['app']['env'] = 'local';
        $config['database']['default'] = 'sqlite';
        $config['database']['connections']['sqlite']['database'] = $file;
        $base = tempnam(sys_get_temp_dir(), 'anis-config-');
        $cache = $base.'.php';
        file_put_contents($cache, '<?php return '.var_export($config, true).';');

        try {
            $this->assertRefusedBeforeTables(['APP_CONFIG_CACHE' => $cache], 'configuration is cached', $file);
        } finally {
            @unlink($cache);
            @unlink($base);
        }
    }

    public function test_a_local_environment_is_refused(): void
    {
        $this->assertRefusedBeforeTables(['APP_ENV' => 'local'], 'the application environment is');
    }

    public function test_a_connection_url_is_refused(): void
    {
        $this->assertRefusedBeforeTables(['DB_URL' => 'sqlite::memory:'], 'URL');
    }

    public function test_mariadb_without_the_opt_in_is_refused_without_connecting(): void
    {
        // Port 1 is closed: a connection attempt would fail with another message.
        $this->assertRefusedBeforeTables(self::mariadbEnv(['ANIS_TEST_DB_ISOLATED' => false]), 'opt-in');
    }

    public function test_mariadb_on_the_project_database_is_refused_without_connecting(): void
    {
        $this->assertRefusedBeforeTables(self::mariadbEnv(['DB_DATABASE' => 'anis']), 'anes_tmp_');
    }

    // --- Helpers ---------------------------------------------------------------------------------

    /**
     * Run one simple test in a separate PHPUnit process with the given environment, and
     * check it is refused by the guard and that no table was created.
     *
     * @param  array<string, string|false>  $env
     */
    private function assertRefusedBeforeTables(array $env, string $reason, ?string $sqlite = null): void
    {
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', '--filter', 'TestDatabaseRefreshTest::test_the_suite_migrates_in_the_testing_environment', '--no-progress'],
            base_path(),
            $env + ['ANIS_TEST_DB_ISOLATED' => false],
            timeout: 120,
        );
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('Unsafe test database', $output);
        $this->assertStringContainsString($reason, $output);
        $this->assertStringContainsString('No migration was run', $output);

        if ($sqlite !== null) {
            $tables = (new PDO('sqlite:'.$sqlite))->query("select count(*) from sqlite_master where type = 'table'")->fetchColumn();
            $this->assertSame(0, (int) $tables);
            @unlink($sqlite);
        }
    }

    private function sentinel(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'anis-sentinel-');
        $this->beforeApplicationDestroyed(fn () => @unlink($file));

        return $file;
    }

    /**
     * @param  array<string, string|false>  $override
     * @return array<string, string|false>
     */
    private static function mariadbEnv(array $override = []): array
    {
        return $override + [
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'anes_tmp_guard_check',
            'DB_USERNAME' => 'anes_tmp_nobody',
            'DB_PASSWORD' => 'none',
            'ANIS_TEST_DB_ISOLATED' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function mariadb(array $override = []): array
    {
        return array_replace([
            'driver' => 'mariadb',
            'url' => null,
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'anes_tmp_one',
            'username' => 'anes_tmp',
            'unix_socket' => '',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function facts(array $override = []): array
    {
        return array_replace([
            'current_user' => 'anes_tmp@127.0.0.1',
            'roles' => [],
            'enabled_roles' => [null],
            'user_privileges' => [self::userRow('USAGE')],
            'schema_privileges' => [self::schemaRow('anes\\_tmp\\_one'), self::schemaRow('anes\\_tmp\\_%')],
            'table_privileges' => [],
            'column_privileges' => [],
            'visible_schemas' => ['information_schema', 'anes_tmp_one'],
            'grants' => [
                "GRANT USAGE ON *.* TO `anes_tmp`@`127.0.0.1` IDENTIFIED BY PASSWORD '*4EBE5C9776BCF0904B4F3E69444005AAC9A025AE'",
                'GRANT ALL PRIVILEGES ON `anes\\_tmp\\_%`.* TO `anes_tmp`@`127.0.0.1`',
            ],
            'foreign_definers' => [],
        ], $override);
    }

    /**
     * @return array<string, string>
     */
    private static function userRow(string $privilege, string $grantable = 'NO', string $grantee = "'anes_tmp'@'127.0.0.1'"): array
    {
        return ['GRANTEE' => $grantee, 'PRIVILEGE_TYPE' => $privilege, 'IS_GRANTABLE' => $grantable];
    }

    /**
     * @return array<string, string>
     */
    private static function schemaRow(string $schema, string $grantable = 'NO', string $grantee = "'anes_tmp'@'127.0.0.1'"): array
    {
        return ['GRANTEE' => $grantee, 'TABLE_SCHEMA' => $schema, 'PRIVILEGE_TYPE' => 'SELECT', 'IS_GRANTABLE' => $grantable];
    }
}
