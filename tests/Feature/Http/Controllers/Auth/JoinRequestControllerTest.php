<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\AccountStatus;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class JoinRequestControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_join_form_lists_the_roles_one_can_apply_for(): void
    {
        $this->get(route('staff.join'))
            ->assertOk()
            ->assertSee('المستشار الفقهي')
            ->assertDontSee('مدير النظام');
    }

    public function test_empty_form_reports_every_required_field(): void
    {
        $this->post(route('staff.join'), [])->assertSessionHasErrors([
            'name' => 'اكتب اسمك الكامل.',
            'email' => 'اكتب بريدك الإلكتروني.',
            'phone' => 'اكتب رقم جوالك.',
            'role' => 'اختر الدور.',
            'qualifications' => 'اكتب نبذة عن مؤهلاتك.',
        ]);
    }

    public function test_cannot_apply_as_administrator(): void
    {
        $this->post(route('staff.join'), $this->validPayload(['role' => UserRole::Admin->value]))
            ->assertSessionHasErrors(['role' => 'اختر دوراً من القائمة.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_already_registered_is_rejected(): void
    {
        $guide = User::factory()->create();

        $this->post(route('staff.join'), $this->validPayload(['email' => $guide->email]))
            ->assertSessionHasErrors('email');
    }

    public function test_valid_request_creates_pending_account_without_password(): void
    {
        $this->post(route('staff.join'), $this->validPayload())
            ->assertRedirect(route('staff.join'))
            ->assertSessionHas('join_sent', true);

        $applicant = User::firstWhere('email', 'new.guide@example.com');
        $this->assertSame(AccountStatus::Pending, $applicant->status);
        $this->assertSame(UserRole::Fiqh, $applicant->role);
        $this->assertSame('966566666666', $applicant->phone);
        $this->assertNull($applicant->password);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'أحمد سالم',
            'email' => 'new.guide@example.com',
            'phone' => '٠٥٦٦٦٦٦٦٦٦',
            'role' => UserRole::Fiqh->value,
            'qualifications' => 'ماجستير في الفقه وخبرة خمس سنوات في مرافقة المرضى.',
            ...$overrides,
        ];
    }
}
