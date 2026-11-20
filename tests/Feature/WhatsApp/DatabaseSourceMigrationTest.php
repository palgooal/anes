<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * PH-WA-6.1: the migration that adds database_source_activated_at (REQ-WA-65).
 *
 * Existing rows keep being the OTP source; rolling back is refused while a row is
 * being prepared. DatabaseMigrations runs the real migrations (no transaction),
 * because the schema itself changes here, and resets the shared migrated state.
 */
class DatabaseSourceMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const COLUMN = 'database_source_activated_at';

    public function test_rows_that_existed_before_the_migration_stay_the_otp_source(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('whatsapp_settings', self::COLUMN));

        // A row created before PH-WA-6, when any row was the OTP source.
        DB::table('whatsapp_settings')->insert([
            'singleton' => true,
            'mode' => 'production',
            'created_at' => '2026-11-19 08:00:00',
            'updated_at' => '2026-11-19 10:30:00',
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('whatsapp_settings', self::COLUMN));
        $this->assertSame('2026-11-19 10:30:00', (string) DB::table('whatsapp_settings')->value(self::COLUMN));
    }

    public function test_a_row_without_timestamps_is_still_marked_active(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('whatsapp_settings')->insert(['singleton' => true, 'mode' => 'test']);

        $migration->up();

        $this->assertNotNull(DB::table('whatsapp_settings')->value(self::COLUMN));
    }

    public function test_rolling_back_is_refused_while_a_row_is_being_prepared_and_nothing_changes(): void
    {
        DB::table('whatsapp_settings')->insert(['singleton' => true, 'mode' => 'disabled', self::COLUMN => null]);

        try {
            $this->migration()->down();
            $this->fail('The rollback was not refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('being prepared', $exception->getMessage());
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('whatsapp_settings', self::COLUMN));
        $this->assertSame(1, DB::table('whatsapp_settings')->count());
        $this->assertNull(DB::table('whatsapp_settings')->value(self::COLUMN));
        $this->assertSame('disabled', DB::table('whatsapp_settings')->value('mode'));

        // The rollback that ends the test would be refused too, by the same guard.
        DB::table('whatsapp_settings')->delete();
    }

    public function test_rolling_back_works_when_the_row_is_active(): void
    {
        DB::table('whatsapp_settings')->insert(['singleton' => true, 'mode' => 'production', self::COLUMN => '2026-11-20 09:00:00']);
        $migration = $this->migration();

        $migration->down();

        $this->assertFalse(Schema::hasColumn('whatsapp_settings', self::COLUMN));
        $this->assertSame(1, DB::table('whatsapp_settings')->count());

        // Migrating again keeps the row active (its activation time is not restored),
        // and puts the column back for the rollback that ends the test.
        $migration->up();
        $this->assertNotNull(DB::table('whatsapp_settings')->value(self::COLUMN));
    }

    public function test_rolling_back_works_without_any_row(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('whatsapp_settings', self::COLUMN));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('whatsapp_settings', self::COLUMN));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_20_400000_add_database_source_activated_at_to_whatsapp_settings_table.php');
    }
}
