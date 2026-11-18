<?php

namespace Tests\Feature\Console\Commands;

use App\AccountStatus;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CreateBeneficiaryCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registers_an_active_patient_with_an_international_number(): void
    {
        config(['auth.phone_countries' => ['966', '970']]);

        $this->artisan('beneficiary:create', ['name' => 'مطوّر الاختبار', 'phone' => '0599123456', '--country' => '970'])
            ->assertSuccessful();

        $patient = User::firstWhere('phone', '970599123456');
        $this->assertSame(UserRole::Patient, $patient->role);
        $this->assertSame(AccountStatus::Active, $patient->status);
        $this->assertSame('مطوّر الاختبار', $patient->name);
    }

    public function test_registers_a_companion_linked_to_the_patient(): void
    {
        config(['auth.phone_countries' => ['966', '970', '972']]);
        $patient = User::factory()->patient()->create(['phone' => '970599123456']);

        $this->artisan('beneficiary:create', ['name' => 'مرافق', 'phone' => '+972561234567', '--companion-of' => '+970599123456'])
            ->assertSuccessful();

        $companion = User::firstWhere('phone', '972561234567');
        $this->assertSame(UserRole::Companion, $companion->role);
        $this->assertTrue($companion->patient->is($patient));
    }

    public function test_refuses_a_number_that_is_already_registered(): void
    {
        User::factory()->patient()->create(['phone' => '966551234567']);

        $this->artisan('beneficiary:create', ['name' => 'مكرر', 'phone' => '0551234567'])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_refuses_a_companion_of_an_unknown_patient(): void
    {
        $this->artisan('beneficiary:create', ['name' => 'مرافق', 'phone' => '0551234567', '--companion-of' => '0550000000'])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_a_country_that_is_not_enabled(): void
    {
        config(['auth.phone_countries' => ['966']]);

        $this->artisan('beneficiary:create', ['name' => 'x', 'phone' => '+970599123456'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
