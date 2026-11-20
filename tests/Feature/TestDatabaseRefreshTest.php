<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: a test that switches the application to "production" before its first
 * query must still get a migrated test database, and production keeps asking before
 * any migration.
 */
class TestDatabaseRefreshTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        // Start as the first database test of a fresh process would, whatever ran before.
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];

        parent::setUp();
    }

    public function test_switching_to_production_first_still_gives_a_migrated_test_database(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue($this->app->isProduction());
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertNotNull(User::factory()->create()->getKey());
    }

    public function test_the_suite_migrates_in_the_testing_environment(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    public function test_production_still_refuses_to_migrate_without_confirmation(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // --pretend: even if it were not refused, nothing would be run.
        $this->artisan('migrate', ['--pretend' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled')
            ->assertExitCode(1);

        $this->assertTrue(Schema::hasTable('users'));
    }
}
