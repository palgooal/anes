<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LogoutControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_beneficiary_logout_returns_to_beneficiary_login(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_staff_logout_returns_to_staff_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('staff.login'));

        $this->assertGuest();
    }

    public function test_guest_cannot_post_logout(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }
}
