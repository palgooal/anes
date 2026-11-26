<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * ANIS-SEC-01 (F3): "php artisan db:seed" creates no account in any environment.
 */
class DatabaseSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeding_creates_no_account(): void
    {
        $this->seed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_seeding_creates_no_account_in_production_either(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, User::query()->count());
    }

    public function test_database_seeder_does_not_call_the_demo_seeder(): void
    {
        $source = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('->call(', $source);
        $this->assertStringNotContainsString('DemoDataSeeder::class', $source);
        $this->assertStringNotContainsString('demo1234', $source);
        $this->assertStringNotContainsString('@demo.sa', $source);
    }

    public function test_no_composer_script_seeds(): void
    {
        $scripts = json_encode(json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts'] ?? []);

        $this->assertStringNotContainsString('seed', (string) $scripts);
    }
}
