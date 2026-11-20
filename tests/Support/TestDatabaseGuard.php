<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use RuntimeException;
use Throwable;

/**
 * Refuses to run the tests on any database other than an isolated test database,
 * before any migration or query the test traits make.
 *
 * Allowed:
 * - SQLite in memory (the default of phpunit.xml).
 * - A temporary MariaDB/MySQL database, only when every condition holds: the explicit
 *   opt-in ANIS_TEST_DB_ISOLATED=1, a loopback host over TCP, a database named
 *   anes_tmp_*, a database and a user both different from the project's .env, and
 *   effective privileges limited to anes_tmp_* databases (checked on the server).
 *
 * Refused: cached configuration (it ignores phpunit.xml), an environment other than
 * "testing", a connection URL, any other driver, and anything that cannot be proven
 * isolated. It never changes a setting and never uses --force.
 */
final class TestDatabaseGuard
{
    public const OPT_IN = 'ANIS_TEST_DB_ISOLATED';

    public const TEMPORARY_DATABASE = '/^anes_tmp_[a-z0-9_]+$/';

    public const TEMPORARY_PREFIX = 'anes_tmp_';

    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /** @var array<string, true> Connections already proven isolated in this process. */
    private static array $verified = [];

    public static function assertSafe(Application $app): void
    {
        $name = (string) $app['config']->get('database.default');
        $connection = $app['config']->get("database.connections.{$name}");

        $problem = self::configurationProblem(
            configCached: $app->configurationIsCached(),
            environment: (string) $app->environment(),
            connection: is_array($connection) ? $connection : null,
            optIn: Env::get(self::OPT_IN),
            project: self::projectDatabase($app->environmentFilePath()),
        );

        if ($problem !== null) {
            throw self::refusal($problem);
        }

        if (($connection['driver'] ?? null) === 'sqlite') {
            return;
        }

        $key = hash('sha256', json_encode([$name, $connection['host'] ?? null, $connection['port'] ?? null, $connection['database'], $connection['username']]));

        if (isset(self::$verified[$key])) {
            return;
        }

        try {
            $facts = self::privilegeFacts($app['db']->connection($name));
        } catch (Throwable $exception) {
            throw self::refusal('the privileges of the test user could not be read ('.$exception::class.')');
        }

        if ($problem = self::privilegeProblem($facts, (string) $connection['database'], self::projectDatabase($app->environmentFilePath())['database'] ?? '')) {
            throw self::refusal($problem);
        }

        self::$verified[$key] = true;
    }

    /**
     * Checks that need no connection.
     *
     * @param  ?array<string, mixed>  $connection
     * @param  ?array{database: string, username: string}  $project  From the project's .env, or null when it cannot be read.
     */
    public static function configurationProblem(bool $configCached, string $environment, ?array $connection, mixed $optIn, ?array $project): ?string
    {
        if ($configCached) {
            return 'the configuration is cached, so phpunit.xml is ignored; run php artisan config:clear';
        }

        if ($environment !== 'testing') {
            return "the application environment is \"{$environment}\", not \"testing\"";
        }

        if ($connection === null) {
            return 'the default database connection is not configured';
        }

        $url = $connection['url'] ?? null;

        if ($url !== null && (! is_string($url) || trim($url) !== '')) {
            return 'a database connection URL is set';
        }

        $driver = $connection['driver'] ?? null;

        if ($driver === 'sqlite') {
            return ($connection['database'] ?? null) === ':memory:'
                ? null
                : 'SQLite is only allowed in memory (:memory:)';
        }

        if (! in_array($driver, ['mariadb', 'mysql'], true)) {
            return 'the database driver "'.(is_scalar($driver) ? $driver : gettype($driver)).'" is not allowed for tests';
        }

        if ($optIn !== '1' && $optIn !== 1) {
            return 'a MariaDB test database needs the explicit opt-in '.self::OPT_IN.'=1';
        }

        if (isset($connection['read']) || isset($connection['write'])) {
            return 'read/write connections are not allowed for tests';
        }

        if (is_string($connection['unix_socket'] ?? null) && $connection['unix_socket'] !== '') {
            return 'a socket connection is not allowed for tests; use a loopback host';
        }

        if (! in_array(strtolower((string) ($connection['host'] ?? '')), self::LOOPBACK_HOSTS, true)) {
            return 'the MariaDB test host must be a loopback address';
        }

        $database = (string) ($connection['database'] ?? '');
        $username = (string) ($connection['username'] ?? '');

        if (! preg_match(self::TEMPORARY_DATABASE, $database)) {
            return 'the MariaDB test database must be named '.self::TEMPORARY_PREFIX.'*';
        }

        if ($username === '') {
            return 'the MariaDB test user is empty';
        }

        if ($project === null) {
            return "the project's .env cannot be read, so the test database cannot be shown to differ from it";
        }

        if (strcasecmp($database, $project['database']) === 0) {
            return "the test database is the project's database";
        }

        if (strcasecmp($username, $project['username']) === 0) {
            return "the test user is the project's database user";
        }

        return null;
    }

    /**
     * Checks on the effective privileges the server reports for the test user.
     *
     * @param  array{current_user: string, roles: list<array<string, mixed>>, enabled_roles: list<?string>, user_privileges: list<array<string, mixed>>, schema_privileges: list<array<string, mixed>>, table_privileges: list<array<string, mixed>>, column_privileges: list<array<string, mixed>>, visible_schemas: list<string>, grants: list<string>, foreign_definers: list<string>}  $facts
     */
    public static function privilegeProblem(array $facts, string $database, string $projectDatabase): ?string
    {
        $grantee = self::grantee($facts['current_user']);

        if ($grantee === null) {
            return 'the current MariaDB user cannot be identified';
        }

        // Roles, granted directly or through other roles, may carry any privilege: refused.
        if ($facts['roles'] !== [] || array_filter($facts['enabled_roles'], fn ($role) => $role !== null) !== []) {
            return 'the test user has roles; a test user must hold no role';
        }

        // Every grant must be explained: USAGE on *.*, or privileges on a database or
        // table of anes_tmp_*. Anything else is refused, routines (PROCEDURE, FUNCTION,
        // PACKAGE), PROXY and roles included, whatever they point to.
        foreach ($facts['grants'] as $grant) {
            if ($problem = self::grantProblem($grant, $facts['current_user'], $projectDatabase)) {
                return $problem;
            }
        }

        // Routines, events, triggers and views defined by another account would run
        // with that account's privileges in the test database.
        if ($facts['foreign_definers'] !== []) {
            return 'the test database holds routines, events, triggers or views defined by another account';
        }

        foreach ($facts['user_privileges'] as $row) {
            // Seeing another account's global privileges means a global privilege.
            if ($row['GRANTEE'] !== $grantee) {
                return 'the test user can read other accounts\' privileges';
            }

            if (strtoupper((string) $row['PRIVILEGE_TYPE']) !== 'USAGE' || strtoupper((string) $row['IS_GRANTABLE']) !== 'NO') {
                return 'the test user has a global privilege ('.$row['PRIVILEGE_TYPE'].')';
            }
        }

        $coversDatabase = false;

        foreach (['schema_privileges', 'table_privileges', 'column_privileges'] as $kind) {
            foreach ($facts[$kind] as $row) {
                if ($row['GRANTEE'] !== $grantee) {
                    return 'the test user can read other accounts\' privileges';
                }

                if (strtoupper((string) $row['IS_GRANTABLE']) !== 'NO') {
                    return 'the test user may grant privileges';
                }

                $pattern = (string) $row['TABLE_SCHEMA'];

                if (! self::patternIsTemporary($pattern)) {
                    return 'the test user has privileges on a database that is not '.self::TEMPORARY_PREFIX.'*';
                }

                if ($projectDatabase !== '' && self::likeMatches($pattern, $projectDatabase)) {
                    return "the test user has privileges on the project's database";
                }

                $coversDatabase = $coversDatabase || ($kind === 'schema_privileges' && self::likeMatches($pattern, $database));
            }
        }

        foreach ($facts['visible_schemas'] as $schema) {
            if ($schema !== 'information_schema' && ! str_starts_with($schema, self::TEMPORARY_PREFIX)) {
                return 'the test user can see a database that is not '.self::TEMPORARY_PREFIX.'*';
            }
        }

        if (! $coversDatabase) {
            return 'the privileges of the test user on the test database cannot be explained';
        }

        return null;
    }

    /**
     * Whether a MySQL LIKE pattern (as used in grants) matches a name.
     */
    public static function likeMatches(string $pattern, string $name): bool
    {
        return preg_match('/^'.self::likeToRegex($pattern).'$/is', $name) === 1;
    }

    /**
     * Only patterns that cannot match any name outside anes_tmp_*, existing or not.
     * MariaDB reads "_" and "%" in a database grant as wildcards unless escaped with
     * "\\" (checked: a grant on `anes_tmp_x` opens `anesQtmpQx`), and compares names
     * case-sensitively with lower_case_table_names=0. So the first nine characters
     * must be the literal, lower-case "anes\_tmp\_"; wildcards may only follow it.
     */
    public static function patternIsTemporary(string $pattern): bool
    {
        $tokens = self::likeTokens($pattern);

        if (count($tokens) <= strlen(self::TEMPORARY_PREFIX)) {
            return false;
        }

        foreach (str_split(self::TEMPORARY_PREFIX) as $index => $char) {
            [$literal, $value] = $tokens[$index];

            if (! $literal || $value !== $char) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why one SHOW GRANTS line is not allowed, or null.
     */
    public static function grantProblem(string $grant, string $currentUser, string $projectDatabase): ?string
    {
        $at = strrpos($currentUser, '@');
        $grantee = $at === false ? null : '`'.str_replace('`', '``', substr($currentUser, 0, $at)).'`@`'.str_replace('`', '``', substr($currentUser, $at + 1)).'`';

        if ($grantee === null) {
            return 'the current MariaDB user cannot be identified';
        }

        $to = ' TO '.preg_quote($grantee, '/');

        if (preg_match('/^GRANT USAGE ON \*\.\*'.$to.'( IDENTIFIED (BY PASSWORD \'[^\']*\'|VIA [A-Za-z0-9_]+( USING \'[^\']*\')?))?$/', $grant)) {
            return null;
        }

        if (preg_match('/^GRANT (?<privileges>[A-Z ,_()`]+?) ON `(?<database>(?:[^`]|``)+)`\.(?:\*|`(?:[^`]|``)+`)'.$to.'$/', $grant, $match)) {
            $pattern = str_replace('``', '`', $match['database']);

            if (! self::patternIsTemporary($pattern)) {
                return 'the test user has privileges on a database that is not '.self::TEMPORARY_PREFIX.'*';
            }

            if ($projectDatabase !== '' && self::likeMatches($pattern, $projectDatabase)) {
                return "the test user has privileges on the project's database";
            }

            return null;
        }

        if (preg_match('/\bON (PROCEDURE|FUNCTION|PACKAGE)\b/i', $grant)) {
            return 'the test user has privileges on routines; a test user must hold none';
        }

        return 'a privilege of the test user cannot be explained';
    }

    /**
     * @return list<array{bool, string}> [is literal, character]
     */
    private static function likeTokens(string $pattern): array
    {
        $tokens = [];
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $tokens[] = [true, $pattern[++$i]];
            } elseif ($char === '%' || $char === '_') {
                $tokens[] = [false, $char];
            } else {
                $tokens[] = [true, $char];
            }
        }

        return $tokens;
    }

    private static function likeToRegex(string $pattern): string
    {
        return implode('', array_map(
            fn (array $token) => $token[0] ? preg_quote($token[1], '/') : ($token[1] === '%' ? '.*' : '.'),
            self::likeTokens($pattern),
        ));
    }

    private static function grantee(string $currentUser): ?string
    {
        $at = strrpos($currentUser, '@');

        if ($at === false) {
            return null;
        }

        return "'".substr($currentUser, 0, $at)."'@'".substr($currentUser, $at + 1)."'";
    }

    /**
     * @return ?array{database: string, username: string}
     */
    private static function projectDatabase(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $values = Dotenv::parse((string) file_get_contents($path));
        } catch (Throwable) {
            return null;
        }

        // The defaults of config/database.php when .env leaves them out.
        return [
            'database' => (string) ($values['DB_DATABASE'] ?? 'laravel'),
            'username' => (string) ($values['DB_USERNAME'] ?? 'root'),
        ];
    }

    /**
     * @return array{current_user: string, roles: list<array<string, mixed>>, enabled_roles: list<?string>, user_privileges: list<array<string, mixed>>, schema_privileges: list<array<string, mixed>>, table_privileges: list<array<string, mixed>>, column_privileges: list<array<string, mixed>>, visible_schemas: list<string>, grants: list<string>, foreign_definers: list<string>}
     */
    private static function privilegeFacts(Connection $connection): array
    {
        $rows = fn (string $sql) => array_map(fn ($row) => (array) $row, $connection->select($sql));

        return [
            'current_user' => (string) $connection->scalar('select current_user()'),
            'roles' => $rows('select * from information_schema.APPLICABLE_ROLES'),
            'enabled_roles' => array_column($rows('select ROLE_NAME from information_schema.ENABLED_ROLES'), 'ROLE_NAME'),
            'user_privileges' => $rows('select GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE from information_schema.USER_PRIVILEGES'),
            'schema_privileges' => $rows('select GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE from information_schema.SCHEMA_PRIVILEGES'),
            'table_privileges' => $rows('select GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE from information_schema.TABLE_PRIVILEGES'),
            'column_privileges' => $rows('select GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE from information_schema.COLUMN_PRIVILEGES'),
            'visible_schemas' => array_column($rows('select SCHEMA_NAME from information_schema.SCHEMATA'), 'SCHEMA_NAME'),
            'grants' => array_map(fn (array $row) => (string) reset($row), $rows('show grants')),
            'foreign_definers' => array_column($connection->select(
                'select DEFINER from information_schema.ROUTINES where ROUTINE_SCHEMA = database() and DEFINER <> current_user()
                 union all select DEFINER from information_schema.EVENTS where EVENT_SCHEMA = database() and DEFINER <> current_user()
                 union all select DEFINER from information_schema.TRIGGERS where TRIGGER_SCHEMA = database() and DEFINER <> current_user()
                 union all select DEFINER from information_schema.VIEWS where TABLE_SCHEMA = database() and DEFINER <> current_user()'
            ), 'DEFINER'),
        ];
    }

    private static function refusal(string $reason): RuntimeException
    {
        return new RuntimeException("Unsafe test database: {$reason}. No migration was run.");
    }
}
