<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_sent_to_the_matching_login_screen(): void
    {
        $this->get(route('portal'))->assertRedirect(route('login'));
        $this->get(route('staff.dashboard'))->assertRedirect(route('staff.login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_link_redirects_each_user_to_their_own_page(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->get(route('dashboard'))->assertRedirect(route('portal'));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))->assertRedirect(route('staff.dashboard'));
    }

    public function test_patient_portal_passes_the_patient_name_to_the_page(): void
    {
        $patient = User::factory()->patient()->create(['name' => 'عبدالله محمد']);

        $this->actingAs($patient)->get(route('portal'))
            ->assertOk()
            ->assertViewHas('config', fn (array $config) => $config['role'] === 'patient'
                && $config['kind'] === 'المريض'
                && $config['patientName'] === 'عبدالله محمد'
                && $config['companionName'] === null);
    }

    public function test_companion_sees_the_patient_page_under_their_own_name(): void
    {
        $patient = User::factory()->patient()->create(['name' => 'عبدالله محمد']);
        $companion = User::factory()->companion($patient)->create(['name' => 'منى عبدالله']);

        $this->actingAs($companion)->get(route('portal'))
            ->assertOk()
            ->assertViewHas('config', fn (array $config) => $config['kind'] === 'المرافق'
                && $config['patientName'] === 'عبدالله محمد'
                && $config['companionName'] === 'منى عبدالله');
    }

    public function test_each_staff_role_gets_its_own_panel(): void
    {
        $panels = [
            'guide' => UserRole::Guide,
            'fiqh' => UserRole::Fiqh,
            'med' => UserRole::Medical,
            'legal' => UserRole::Legal,
            'admin' => UserRole::Admin,
        ];

        foreach ($panels as $panel => $role) {
            $this->actingAs(User::factory()->role($role)->create(['name' => 'سالم']))
                ->get(route('staff.dashboard'))
                ->assertOk()
                ->assertViewHas('config', fn (array $config) => $config['role'] === $panel
                    && $config['who'] === 'سالم · '.$role->label());
        }
    }

    public function test_page_includes_logout_form_and_service_whatsapp_number(): void
    {
        config(['services.whatsapp.number' => '966500000000']);

        $this->actingAs(User::factory()->create())
            ->get(route('staff.dashboard'))
            ->assertSee('id="logout-form"', false)
            ->assertSee(route('logout'))
            ->assertViewHas('config', fn (array $config) => $config['waNumber'] === '966500000000');
    }
}
