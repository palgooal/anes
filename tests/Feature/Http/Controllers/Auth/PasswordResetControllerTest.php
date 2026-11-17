<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registered_email_receives_reset_link(): void
    {
        Notification::fake();
        $guide = User::factory()->create();

        $this->post(route('password.email'), ['email' => $guide->email])
            ->assertSessionHas('sent_to', $guide->email);

        Notification::assertSentTo($guide, ResetPassword::class);
    }

    public function test_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHas('sent_to', 'nobody@example.com');

        Notification::assertNothingSent();
    }

    public function test_reset_link_sets_a_new_password(): void
    {
        Notification::fake();
        $guide = User::factory()->create();
        $this->post(route('password.email'), ['email' => $guide->email]);
        $token = Notification::sent($guide, ResetPassword::class)->last()->token;

        $this->get(route('password.reset', ['token' => $token, 'email' => $guide->email]))->assertOk();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $guide->email,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertRedirect(route('staff.login'));

        $this->assertTrue(Hash::check('new-secret-123', $guide->fresh()->password));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $guide = User::factory()->create();

        $this->from(route('password.reset', ['token' => 'bad']))
            ->post(route('password.update'), [
                'token' => 'bad',
                'email' => $guide->email,
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertSessionHasErrors(['email' => 'رابط الاستعادة غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.']);

        $this->assertTrue(Hash::check('password', $guide->fresh()->password));
    }

    public function test_short_or_unconfirmed_password_is_rejected(): void
    {
        $this->post(route('password.update'), [
            'token' => 'x',
            'email' => 'a@b.sa',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');
    }
}
