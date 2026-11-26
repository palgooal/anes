<?php

namespace Database\Seeders\Support;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use RuntimeException;
use Throwable;

/**
 * Refuses to write demo data anywhere but the dedicated local demo database
 * (ANIS-SEC-01, F3). Every condition must hold, before anything is written:
 *
 * 1. The explicit opt-in ANIS_DEMO_SEED=1, read from the environment itself.
 * 2. The configuration is not cached.
 * 3. The application environment is exactly "local".
 * 4. The default connection is SQLite on exactly database/anis-demo.sqlite: no URL,
 *    no read/write split, no :memory:. Checked on the configuration and again on the
 *    live connection, through both the read and the write PDO, so a connection that
 *    is configured for the demo file but actually opened elsewhere is refused.
 * 5. The database holds no account outside the demo accounts, and no WhatsApp
 *    settings row.
 * 6. Nothing can be delivered: the effective WhatsApp settings write to the log (a
 *    stored settings row cannot override that, because none may exist), and the
 *    default mailer only uses the "log" or "array" transport.
 * 7. It is never called from DatabaseSeeder or any script: only by an explicit
 *    "php artisan db:seed --class=Database\Seeders\DemoDataSeeder".
 *
 * Anything that cannot be proven is refused. It never changes a setting, a file or
 * any data. The test-suite guard (tests/Support/TestDatabaseGuard.php) is separate
 * and unchanged.
 */
final class DemoSeedGuard
{
    public const OPT_IN = 'ANIS_DEMO_SEED';

    /** Transports that never deliver a message. */
    private const FAKE_TRANSPORTS = ['log', 'array'];

    /**
     * @param  list<string>  $demoEmails  Emails of the demo accounts.
     * @param  list<string>  $demoPhones  Phones of the demo accounts (international digits).
     */
    public static function assertSafe(Application $app, string $databaseFile, array $demoEmails, array $demoPhones): void
    {
        $name = (string) $app['config']->get('database.default');

        try {
            $problem = self::configurationProblem(
                configCached: $app->configurationIsCached(),
                environment: (string) $app->environment(),
                optIn: Env::get(self::OPT_IN),
                configuredDefault: $name,
                activeDefault: (string) $app['db']->getDefaultConnection(),
                connection: is_array($c = $app['config']->get("database.connections.{$name}")) ? $c : null,
                databaseFile: $databaseFile,
            );

            if ($problem === null) {
                $connection = $app['db']->connection($name);

                $problem = self::connectionProblem($connection, $databaseFile)
                    ?? self::modelProblem($name)
                    ?? self::dataProblem($connection, $demoEmails, $demoPhones)
                    ?? self::deliveryProblem($app);
            }
        } catch (Throwable $exception) {
            $problem = 'the database could not be proven to be the demo database ('.$exception::class.')';
        }

        if ($problem !== null) {
            throw new RuntimeException('Demo data refused: '.$problem.'. Nothing was written.');
        }
    }

    /**
     * Checks that need no connection.
     *
     * @param  ?array<string, mixed>  $connection
     */
    public static function configurationProblem(
        bool $configCached,
        string $environment,
        mixed $optIn,
        string $configuredDefault,
        string $activeDefault,
        ?array $connection,
        string $databaseFile,
    ): ?string {
        if ($optIn !== '1' && $optIn !== 1) {
            return 'the explicit opt-in '.self::OPT_IN.'=1 is missing';
        }

        if ($configCached) {
            return 'the configuration is cached; run php artisan config:clear';
        }

        if ($environment !== 'local') {
            return "the application environment is \"{$environment}\", not \"local\"";
        }

        if ($configuredDefault === '' || $configuredDefault !== $activeDefault) {
            return 'the default database connection is ambiguous';
        }

        if ($connection === null) {
            return 'the default database connection is not configured';
        }

        $url = $connection['url'] ?? null;

        if ($url !== null && (! is_string($url) || trim($url) !== '')) {
            return 'a database connection URL is set';
        }

        if (($connection['driver'] ?? null) !== 'sqlite') {
            return 'the database driver is not SQLite';
        }

        if (isset($connection['read']) || isset($connection['write'])) {
            return 'read/write connections are not allowed';
        }

        $database = $connection['database'] ?? null;

        if (! is_string($database) || $database === '' || str_contains($database, ':memory:') || str_starts_with($database, 'file:')) {
            return 'the SQLite database is not a file';
        }

        if (! self::sameFile($database, $databaseFile)) {
            return 'the SQLite database is not the demo database file';
        }

        return null;
    }

    /**
     * Checks on the live connection: the file SQLite actually has open, through
     * both the read and the write PDO, and nothing else attached.
     */
    public static function connectionProblem(Connection $connection, string $databaseFile): ?string
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return 'the live connection is not SQLite';
        }

        $paths = [
            'write' => $connection->selectFromWriteConnection('PRAGMA database_list'),
            'read' => $connection->select('PRAGMA database_list', [], true),
        ];

        foreach ($paths as $side => $rows) {
            $main = array_values(array_filter($rows, fn ($row) => ($row->name ?? null) === 'main'));
            $other = array_filter($rows, fn ($row) => ! in_array($row->name ?? null, ['main', 'temp'], true));

            if (count($main) !== 1 || $other !== []) {
                return "the {$side} connection has other databases attached";
            }

            if (! self::sameFile((string) $main[0]->file, $databaseFile)) {
                return "the {$side} connection is not open on the demo database file";
            }
        }

        return null;
    }

    /**
     * The models the demo data uses must write through the default connection.
     */
    public static function modelProblem(string $defaultConnection): ?string
    {
        foreach ([User::class, WhatsAppSetting::class] as $model) {
            $name = (new $model)->getConnectionName();

            if ($name !== null && $name !== $defaultConnection) {
                return class_basename($model).' uses another connection';
            }
        }

        return null;
    }

    /**
     * The database must be migrated and hold only demo accounts, and no WhatsApp settings.
     *
     * @param  list<string>  $demoEmails
     * @param  list<string>  $demoPhones
     */
    public static function dataProblem(Connection $connection, array $demoEmails, array $demoPhones): ?string
    {
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('users')) {
            return 'the demo database is not migrated';
        }

        $foreign = $connection->table('users')
            ->where(function ($query) use ($demoEmails, $demoPhones) {
                $query->where(fn ($q) => $q->whereNull('email')->orWhereNotIn('email', $demoEmails))
                    ->where(fn ($q) => $q->whereNull('phone')->orWhereNotIn('phone', $demoPhones));
            })
            ->count();

        if ($foreign > 0) {
            return 'the database holds accounts that are not demo accounts';
        }

        if ($schema->hasTable('whatsapp_settings') && $connection->table('whatsapp_settings')->exists()) {
            return 'the database holds WhatsApp settings';
        }

        return null;
    }

    /**
     * Nothing may be delivered while the demo data is written or used.
     */
    public static function deliveryProblem(Application $app): ?string
    {
        if ($app['config']->get('services.whatsapp.driver') !== 'log') {
            return 'the WhatsApp driver is not "log"';
        }

        $whatsApp = $app->make(WhatsAppSettings::class)->current();

        if ($whatsApp->source !== WhatsAppConfig::SOURCE_ENV || $whatsApp->driver !== WhatsAppConfig::DRIVER_LOG) {
            return 'the effective WhatsApp settings do not write to the log';
        }

        $mailer = (string) $app['config']->get('mail.default');
        $transport = $app['config']->get("mail.mailers.{$mailer}.transport");

        if (! in_array($transport, self::FAKE_TRANSPORTS, true)) {
            return 'the default mailer can deliver email (only "log" or "array" is allowed)';
        }

        return null;
    }

    /**
     * Both paths name the same existing regular file.
     */
    private static function sameFile(string $path, string $expected): bool
    {
        $real = realpath($path);
        $target = realpath($expected);

        return $real !== false && $target !== false && is_file($real) && $real === $target;
    }
}
