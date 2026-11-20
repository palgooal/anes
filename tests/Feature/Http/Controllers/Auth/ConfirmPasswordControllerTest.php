<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Password confirmation before sensitive WhatsApp settings actions (REQ-WA-42, AC-WA-26).
 */
class ConfirmPasswordControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_is_sent_to_the_staff_login(): void
    {
        $this->get(route('password.confirm'))->assertRedirect(route('staff.login'));
    }

    public function test_page_asks_for_the_password(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertSee('تأكيد كلمة المرور');
    }

    public function test_wrong_password_is_refused_and_not_confirmed(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('password.confirm'))
            ->post(route('password.confirm'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors(['password' => 'كلمة المرور غير صحيحة.'])
            ->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_after_confirming_the_admin_returns_to_the_settings_page_and_can_save(): void
    {
        WhatsAppSetting::factory()->create();
        $admin = User::factory()->admin()->create();

        // The sensitive action sends the admin to confirm their password first...
        $this->actingAs($admin)
            ->from(route('staff.whatsapp'))
            ->put(route('staff.whatsapp.secrets', 'test'), ['app_secret' => 'fake-app-secret-abcd'])
            ->assertRedirect(route('password.confirm'));

        // ...then back to the settings page, where the action now goes through.
        $this->post(route('password.confirm'), ['password' => 'password'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHas('auth.password_confirmed_at');

        $this->put(route('staff.whatsapp.secrets', 'test'), ['app_secret' => 'fake-app-secret-abcd'])
            ->assertRedirect(route('staff.whatsapp'))
            ->assertSessionHasNoErrors();

        $this->assertSame('fake-app-secret-abcd', WhatsAppSetting::sole()->test_app_secret);
    }

    public function test_the_password_is_never_flashed_back(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('password.confirm'))
            ->post(route('password.confirm'), ['password' => 'wrong-password']);

        $this->assertStringNotContainsString('wrong-password', json_encode(session()->all()));
    }
}
