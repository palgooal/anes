<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffLoginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_page_renders_email_and_password_form(): void
    {
        $this->get(route('staff.login'))
            ->assertOk()
            ->assertSee('تسجيل الدخول')
            ->assertSee('نسيت كلمة المرور؟');
    }

    public function test_empty_form_reports_both_fields(): void
    {
        $this->from(route('staff.login'))
            ->post(route('staff.login'), [])
            ->assertSessionHasErrors([
                'email' => 'اكتب بريدك الإلكتروني.',
                'password' => 'اكتب كلمة المرور.',
            ]);
    }

    public function test_wrong_password_gives_one_generic_error_and_sends_no_code(): void
    {
        Notification::fake();
        $guide = User::factory()->create();

        $this->from(route('staff.login'))
            ->post(route('staff.login'), ['email' => $guide->email, 'password' => 'wrong-password'])
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors(['credentials' => 'البريد أو كلمة المرور غير صحيحة.']);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_five_wrong_passwords_lock_the_email_temporarily(): void
    {
        $guide = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'wrong']);
        }

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'wrong'])->assertViewIs('auth.locked');
        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])->assertViewIs('auth.locked');
    }

    public function test_correct_password_sends_two_factor_code_without_signing_in(): void
    {
        Notification::fake();
        $guide = User::factory()->create();

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.verify'));

        Notification::assertSentTo($guide, LoginCodeNotification::class);
        $this->assertGuest();
    }

    public function test_correct_code_signs_staff_in_and_opens_their_dashboard(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $this->post(route('staff.login'), ['email' => $admin->email, 'password' => 'password', 'remember' => '1']);

        $this->post(route('staff.verify'), ['code' => $this->sentCode($admin)])
            ->assertRedirect(route('staff.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull(Auth::user()->getRememberToken());
    }

    public function test_wrong_two_factor_code_keeps_the_user_signed_out(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password']);
        $wrong = $this->sentCode($guide) === '0000' ? '1111' : '0000';

        $this->post(route('staff.verify'), ['code' => $wrong])
            ->assertSessionHasErrors(['code' => 'الرمز غير صحيح. بقي لك ٤ محاولات.']);

        $this->assertGuest();
    }

    public function test_beneficiary_code_screen_does_not_accept_a_staff_login(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password']);

        $this->post(route('login.verify'), ['code' => $this->sentCode($guide)])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_pending_account_sees_review_screen(): void
    {
        Notification::fake();
        $guide = User::factory()->pending()->create();

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.status'));

        $this->get(route('staff.status'))->assertOk()->assertSee('حسابك قيد المراجعة');
        Notification::assertNothingSent();
    }

    public function test_suspended_account_sees_suspended_screen(): void
    {
        $guide = User::factory()->suspended()->create();

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertRedirect(route('staff.status'));

        $this->get(route('staff.status'))->assertOk()->assertSee('الحساب موقوف');
    }

    public function test_status_screen_without_a_login_attempt_returns_to_login(): void
    {
        $this->get(route('staff.status'))->assertRedirect(route('staff.login'));
    }

    public function test_staff_account_without_phone_cannot_receive_a_code(): void
    {
        $guide = User::factory()->create(['phone' => null]);

        $this->post(route('staff.login'), ['email' => $guide->email, 'password' => 'password'])
            ->assertSessionHasErrors('credentials');
        $this->assertGuest();
    }

    public function test_every_staff_role_lands_on_the_staff_dashboard(): void
    {
        Notification::fake();

        foreach ([UserRole::Guide, UserRole::Fiqh, UserRole::Medical, UserRole::Legal, UserRole::Admin] as $role) {
            $user = User::factory()->role($role)->create();
            $this->post(route('staff.login'), ['email' => $user->email, 'password' => 'password']);

            $this->post(route('staff.verify'), ['code' => $this->sentCode($user)])
                ->assertRedirect(route('staff.dashboard'));

            $this->post(route('logout'));
        }
    }

    private function sentCode(User $user): string
    {
        return Notification::sent($user, LoginCodeNotification::class)->last()->code;
    }
}
