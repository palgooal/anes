<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\AccountStatus;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamMemberStatusControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_approving_a_join_request_activates_it_and_emails_an_invitation(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pending()->create(['password' => null]);

        $this->actingAs($admin)->from(route('staff.team'))
            ->patch(route('staff.team.status', $applicant), ['status' => 'active'])
            ->assertRedirect(route('staff.team'))
            ->assertSessionHas('status');

        $this->assertSame(AccountStatus::Active, $applicant->fresh()->status);
        Notification::assertSentTo($applicant, TeamInvitationNotification::class);
    }

    public function test_approving_an_account_that_already_has_a_password_sends_no_invitation(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pending()->create();

        $this->actingAs($admin)->patch(route('staff.team.status', $applicant), ['status' => 'active']);

        $this->assertSame(AccountStatus::Active, $applicant->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_suspending_a_member_blocks_their_next_request(): void
    {
        $admin = User::factory()->admin()->create();
        $guide = User::factory()->create();

        $this->actingAs($admin)->patch(route('staff.team.status', $guide), ['status' => 'suspended']);

        $this->assertSame(AccountStatus::Suspended, $guide->fresh()->status);
        $this->actingAs($guide->fresh())->get(route('staff.dashboard'))->assertRedirect(route('staff.login'));
    }

    public function test_reactivating_a_suspended_member(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $guide = User::factory()->suspended()->create(['password' => null]);

        $this->actingAs($admin)->patch(route('staff.team.status', $guide), ['status' => 'active']);

        $this->assertSame(AccountStatus::Active, $guide->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_administrator_cannot_change_their_own_status(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from(route('staff.team'))
            ->patch(route('staff.team.status', $admin), ['status' => 'suspended'])
            ->assertSessionHasErrors(['team' => 'لا يمكنك تغيير حالة حسابك أنت.']);

        $this->assertSame(AccountStatus::Active, $admin->fresh()->status);
    }

    public function test_rejects_a_status_other_than_active_or_suspended(): void
    {
        $admin = User::factory()->admin()->create();
        $guide = User::factory()->create();

        $this->actingAs($admin)->patch(route('staff.team.status', $guide), ['status' => 'pending'])
            ->assertSessionHasErrors('status');

        $this->assertSame(AccountStatus::Active, $guide->fresh()->status);
    }

    public function test_non_administrator_cannot_change_a_status(): void
    {
        $guide = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($guide)->patch(route('staff.team.status', $other), ['status' => 'suspended'])
            ->assertForbidden();

        $this->assertSame(AccountStatus::Active, $other->fresh()->status);
    }

    public function test_beneficiary_status_cannot_be_changed_from_the_team_page(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = User::factory()->patient()->create();

        $this->actingAs($admin)->patch(route('staff.team.status', $patient), ['status' => 'suspended'])
            ->assertNotFound();
    }
}
