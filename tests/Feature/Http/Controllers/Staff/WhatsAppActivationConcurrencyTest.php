<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppSettingsAudit;
use App\Services\WhatsApp\ActivationRefusedException;
use App\Services\WhatsApp\SettingsFingerprint;
use App\Services\WhatsApp\WhatsAppActivation;
use App\Services\WhatsApp\WhatsAppReadiness;
use App\Services\WhatsApp\WhatsAppSettings;
use App\WhatsAppAuditAction;
use App\WhatsAppEnvironment;
use App\WhatsAppMode;
use ArrayObject;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * PH-WA-6.7 on a real MariaDB server, with a second connection and committed
 * transactions (no test transaction around them): the row lock, what another
 * connection sees before the commit, and a change committed by another connection
 * while the checks run (REQ-WA-67, AC-WA-47, AC-WA-48). Skipped on SQLite, which
 * has no second connection to an in-memory database.
 */
class WhatsAppActivationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const REFERENCE = 'ملف التحقق من الملكية NO-2026-117';

    private ?PDO $other = null;

    private ?Closure $duringMeta = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Needs a second connection to a MariaDB test database.');
        }

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($this->duringMeta) {
                [$callback, $this->duringMeta] = [$this->duringMeta, null];
                $callback();
            }

            return match (true) {
                str_ends_with($path, '/debug_token') => Http::response(['data' => ['is_valid' => true, 'scopes' => ['whatsapp_business_messaging'], 'expires_at' => 0]]),
                str_ends_with($path, '/message_templates') => Http::response(['data' => [['name' => 'login_code', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'AUTHENTICATION', 'components' => []]]]),
                default => Http::response(['display_phone_number' => '+966 50 000 0002']),
            };
        }]);

        $config = config('database.connections.'.config('database.default'));
        $this->other = new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public function test_a_lock_held_elsewhere_refuses_without_any_write(): void
    {
        $setting = $this->prepared();
        $before = WhatsAppActivation::storedValues($setting->fresh());

        try {
            $this->other->beginTransaction();
            $this->other->query('select id from whatsapp_settings for update')->fetchAll();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $this->activation()->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', self::REFERENCE, User::factory()->admin()->create(), request());
                $this->fail('The activation went through a held lock.');
            } catch (ActivationRefusedException $exception) {
                $this->assertSame(ActivationRefusedException::DATABASE_ERROR, $exception->reason);
                $this->assertTrue($exception->recorded);
            }

            $this->other->rollBack();

            $this->assertSame($before, WhatsAppActivation::storedValues($setting->fresh()));
            $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
            $this->assertSame('database_error', WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::ActivationRefused)->sole()->changes['reason']);
        } finally {
            $this->cleanUp();
        }
    }

    public function test_another_connection_sees_nothing_before_the_commit_and_everything_after(): void
    {
        $setting = $this->prepared();
        $seen = new ArrayObject;
        $this->app->bind(WhatsAppActivation::class, fn ($app) => new class($app->make(WhatsAppReadiness::class), $app->make(SettingsFingerprint::class), $app->make(WhatsAppSettings::class), $this->other, $seen) extends WhatsAppActivation
        {
            public function __construct(WhatsAppReadiness $readiness, SettingsFingerprint $fingerprint, WhatsAppSettings $settings, private PDO $other, private ArrayObject $seen)
            {
                parent::__construct($readiness, $fingerprint, $settings);
            }

            protected function commit(Connection $connection): void
            {
                // Written in this transaction, not yet committed.
                $this->seen['row'] = $this->other->query('select mode, database_source_activated_at from whatsapp_settings')->fetch(PDO::FETCH_ASSOC);
                $this->seen['activated'] = (int) $this->other->query("select count(*) from whatsapp_settings_audits where action = 'activated'")->fetchColumn();

                parent::commit($connection);
            }
        });

        try {
            app(WhatsAppActivation::class)->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', self::REFERENCE, User::factory()->admin()->create(), request());

            // Before the commit: still preparing, no activation entry (AC-WA-47).
            $this->assertSame(['mode' => 'disabled', 'database_source_activated_at' => null], $seen['row']);
            $this->assertSame(0, $seen['activated']);

            // After it: the source, the mode and the entry together.
            $row = $this->other->query('select mode, database_source_activated_at from whatsapp_settings')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('production', $row['mode']);
            $this->assertNotNull($row['database_source_activated_at']);
            $this->assertSame(1, (int) $this->other->query("select count(*) from whatsapp_settings_audits where action = 'activated'")->fetchColumn());
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_change_committed_by_another_connection_during_the_checks_is_refused(): void
    {
        $setting = $this->prepared();
        $before = WhatsAppActivation::storedValues($setting->fresh());
        $this->duringMeta = fn () => $this->other->exec("update whatsapp_settings set test_waba_id = '200000000000099'");

        try {
            try {
                $this->activation()->activate(WhatsAppMode::Production, WhatsAppMode::Disabled, 'preparing', self::REFERENCE, User::factory()->admin()->create(), request());
                $this->fail('A change made during the checks was activated.');
            } catch (ActivationRefusedException $exception) {
                $this->assertSame(ActivationRefusedException::SNAPSHOT_CHANGED, $exception->reason);
            }

            $after = WhatsAppActivation::storedValues($setting->fresh());
            $this->assertNull($after['database_source_activated_at']);
            $this->assertSame('200000000000099', $after['test_waba_id']);
            unset($before['test_waba_id'], $after['test_waba_id']);
            $this->assertSame($before, $after);
            $this->assertSame(0, WhatsAppSettingsAudit::where('action', WhatsAppAuditAction::Activated)->count());
        } finally {
            $this->cleanUp();
        }
    }

    private function activation(): WhatsAppActivation
    {
        return app(WhatsAppActivation::class);
    }

    private function prepared(): WhatsAppSetting
    {
        $setting = WhatsAppSetting::factory()->preparing()->mode(WhatsAppMode::Disabled)->create();

        WhatsAppSettingsAudit::record(WhatsAppAuditAction::SendTestVerification, User::factory()->admin()->create(), WhatsAppEnvironment::Production, [
            'origin' => 'panel',
            'recipient_last4' => '4567',
            'outcome' => 'verified',
            'fingerprint' => app(SettingsFingerprint::class)->production($setting),
        ]);

        return $setting;
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
