<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse any database that is not an isolated test database (TestDatabaseGuard)
     * before the database traits run: they migrate in setUpTraits(), right after this.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        TestDatabaseGuard::assertSafe($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Views use @vite; tests must not depend on a built asset manifest.
        $this->withoutVite();

        $this->prepareLazilyRefreshedDatabase();
    }

    /**
     * Run the lazy database refresh now, in the environment the suite runs in.
     *
     * LazilyRefreshDatabase migrates on the first query. A test that switches the
     * application to "production" before its first query made that migration run in
     * production: migrate:fresh then asks for confirmation, is cancelled, and the
     * refresh still records the database as migrated. Every later test in the same
     * process then ran on an empty database. Refreshing here, before the test body,
     * keeps production's confirmation untouched (no --force) and the test database
     * unchanged; a test still switches to production for everything it checks.
     */
    private function prepareLazilyRefreshedDatabase(): void
    {
        if (! in_array(LazilyRefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        DB::connection()->select('select 1');

        // Fail loudly instead of running tests on an empty database.
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('The test database was not migrated: check the environment the test suite runs in.');
        }
    }
}
