<?php

namespace Tests\Feature\Http\Controllers\Staff;

use App\AccountStatus;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_sent_to_staff_login(): void
    {
        $this->get(route('staff.team'))->assertRedirect(route('staff.login'));
    }

    public function test_staff_member_who_is_not_an_administrator_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('staff.team'))
            ->assertForbidden();
    }

    public function test_lists_staff_with_pending_requests_first_and_hides_beneficiaries(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'المدير']);
        User::factory()->create(['name' => 'مرشد مفعّل']);
        User::factory()->pending()->create(['name' => 'متقدم جديد', 'qualifications' => 'ماجستير في الدراسات الإسلامية']);
        User::factory()->patient()->create(['name' => 'مريض لا يظهر']);

        $this->actingAs($admin)->get(route('staff.team'))
            ->assertOk()
            ->assertSeeInOrder(['متقدم جديد', 'المدير', 'مرشد مفعّل'])
            ->assertSee('ماجستير في الدراسات الإسلامية')
            ->assertDontSee('مريض لا يظهر');
    }

    public function test_filters_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->suspended()->create(['name' => 'عضو موقوف']);
        User::factory()->pending()->create(['name' => 'متقدم جديد']);

        $this->actingAs($admin)->get(route('staff.team', ['status' => 'suspended']))
            ->assertSee('عضو موقوف')
            ->assertDontSee('متقدم جديد');
    }

    public function test_searches_by_name_email_or_phone(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'سالم الحربي', 'email' => 'salem@example.com', 'phone' => '966551112233']);
        User::factory()->create(['name' => 'خالد العتيبي']);

        $this->actingAs($admin)->get(route('staff.team', ['q' => 'الحربي']))
            ->assertSee('سالم الحربي')->assertDontSee('خالد العتيبي');

        $this->get(route('staff.team', ['q' => 'salem@']))
            ->assertSee('سالم الحربي')->assertDontSee('خالد العتيبي');

        $this->get(route('staff.team', ['q' => '٠٥٥١١١']))
            ->assertSee('سالم الحربي')->assertDontSee('خالد العتيبي');
    }

    public function test_adding_a_member_creates_an_active_account_and_emails_an_invitation(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('staff.team.store'), [
            'name' => 'د. هند القحطاني',
            'role' => UserRole::Medical->value,
            'email' => 'hind@example.com',
            'phone' => '0577778888',
        ])->assertRedirect(route('staff.team'))->assertSessionHas('status');

        $member = User::firstWhere('email', 'hind@example.com');
        $this->assertSame(UserRole::Medical, $member->role);
        $this->assertSame(AccountStatus::Active, $member->status);
        $this->assertNull($member->password);
        Notification::assertSentTo($member, TeamInvitationNotification::class);
    }

    public function test_adding_a_member_validates_every_field(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('staff.team.store'), [])->assertSessionHasErrors([
            'name' => 'اكتب اسم العضو.',
            'role' => 'اختر الدور.',
            'email' => 'اكتب البريد الإلكتروني.',
            'phone' => 'اكتب رقم الجوال، فرمز الدخول يُرسل إليه.',
        ]);
    }

    public function test_cannot_add_a_beneficiary_through_the_team_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('staff.team.store'), [
            'name' => 'مريض',
            'role' => UserRole::Patient->value,
            'email' => 'p@example.com',
            'phone' => '0577778888',
        ])->assertSessionHasErrors(['role' => 'اختر دوراً من القائمة.']);
    }

    public function test_non_administrator_cannot_add_a_member(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('staff.team.store'), ['name' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejecting_a_join_request_deletes_it(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pending()->create();

        $this->actingAs($admin)->delete(route('staff.team.destroy', $applicant))
            ->assertRedirect(route('staff.team'));

        $this->assertModelMissing($applicant);
    }

    public function test_active_member_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $guide = User::factory()->create();

        $this->actingAs($admin)->from(route('staff.team'))
            ->delete(route('staff.team.destroy', $guide))
            ->assertSessionHasErrors('team');

        $this->assertModelExists($guide);
    }

    public function test_beneficiary_accounts_are_not_reachable_from_team_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = User::factory()->patient()->pending()->create();

        $this->actingAs($admin)->delete(route('staff.team.destroy', $patient))->assertNotFound();
        $this->assertModelExists($patient);
    }
}
