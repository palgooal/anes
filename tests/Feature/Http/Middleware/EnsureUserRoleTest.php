<?php

namespace Tests\Feature\Http\Middleware;

use App\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EnsureUserRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_member_opening_the_portal_is_sent_to_the_staff_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('portal'))
            ->assertRedirect(route('staff.dashboard'));
    }

    public function test_beneficiary_opening_the_staff_dashboard_is_sent_to_the_portal(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->get(route('staff.dashboard'))
            ->assertRedirect(route('portal'));
    }

    public function test_account_suspended_while_signed_in_is_signed_out(): void
    {
        $guide = User::factory()->create();
        $this->actingAs($guide)->get(route('staff.dashboard'))->assertOk();

        $guide->update(['status' => AccountStatus::Suspended]);

        $this->get(route('staff.dashboard'))->assertRedirect(route('staff.login'));
        $this->assertGuest();
    }
}
