<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BeneficiaryLoginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_page_renders_phone_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('أرسل رمز التحقق عبر واتساب');
    }

    public function test_rejects_phone_that_is_not_a_saudi_mobile(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['phone' => '12345'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['phone' => 'اكتب رقم جوال من ١٠ أرقام يبدأ بـ 05.']);
    }

    public function test_unregistered_number_shows_whatsapp_registration_without_sending_a_code(): void
    {
        Notification::fake();

        $this->post(route('login'), ['phone' => '0500000000'])
            ->assertOk()
            ->assertViewIs('auth.beneficiary.unknown')
            ->assertSee('هذا الرقم غير مسجّل لدينا');

        Notification::assertNothingSent();
    }

    public function test_staff_number_is_treated_as_unregistered_on_beneficiary_login(): void
    {
        $guide = User::factory()->create();

        $this->post(route('login'), ['phone' => $guide->phone])
            ->assertViewIs('auth.beneficiary.unknown');
    }

    public function test_suspended_beneficiary_cannot_request_a_code(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->suspended()->create();

        $this->from(route('login'))
            ->post(route('login'), ['phone' => $patient->phone])
            ->assertSessionHasErrors(['phone' => 'هذا الحساب غير مفعّل. تواصل معنا عبر واتساب.']);

        Notification::assertNothingSent();
    }

    public function test_registered_number_receives_code_and_goes_to_code_screen(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create(['phone' => '0551234567']);

        // Arabic-Indic digits and an international prefix are normalised.
        $this->post(route('login'), ['phone' => '+966 ٥٥١٢٣٤٥٦٧'])
            ->assertRedirect(route('login.verify'));

        Notification::assertSentTo($patient, LoginCodeNotification::class, fn (LoginCodeNotification $n) => strlen($n->code) === 4);
        $this->get(route('login.verify'))->assertOk()->assertSee('0551234567');
    }

    public function test_correct_code_signs_patient_in_and_opens_portal(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();

        $this->post(route('login'), ['phone' => $patient->phone]);
        $code = $this->sentCode($patient);

        $this->post(route('login.verify'), ['code' => str_split($code)])
            ->assertRedirect(route('portal'));

        $this->assertAuthenticatedAs($patient);
        $this->assertNotNull(OneTimeCode::firstWhere('user_id', $patient->id)->consumed_at);
    }

    public function test_companion_number_signs_in_as_the_companion(): void
    {
        Notification::fake();
        $companion = User::factory()->companion()->create();

        $this->post(route('login'), ['phone' => $companion->phone]);

        $this->post(route('login.verify'), ['code' => $this->sentCode($companion)])
            ->assertRedirect(route('portal'));

        $this->assertAuthenticatedAs($companion);
    }

    public function test_wrong_code_shows_remaining_attempts(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);

        $this->from(route('login.verify'))
            ->post(route('login.verify'), ['code' => $this->wrongCode($patient)])
            ->assertRedirect(route('login.verify'))
            ->assertSessionHasErrors(['code' => 'الرمز غير صحيح. بقي لك ٤ محاولات.']);

        $this->assertGuest();
    }

    public function test_five_wrong_codes_lock_the_account_even_for_the_right_code(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);
        $wrong = $this->wrongCode($patient);

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('login.verify'), ['code' => $wrong]);
        }

        $this->post(route('login.verify'), ['code' => $wrong])->assertViewIs('auth.locked');
        $this->post(route('login.verify'), ['code' => $this->sentCode($patient)])->assertViewIs('auth.locked');
        $this->assertGuest();
    }

    public function test_expired_code_is_rejected(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);
        $code = $this->sentCode($patient);

        $this->travel(11)->minutes();

        $this->post(route('login.verify'), ['code' => $code])
            ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        $this->assertGuest();
    }

    public function test_code_screen_without_a_pending_login_returns_to_phone_form(): void
    {
        $this->get(route('login.verify'))->assertRedirect(route('login'));
        $this->post(route('login.verify'), ['code' => '1234'])->assertRedirect(route('login'));
    }

    public function test_resend_waits_a_minute_before_sending_a_new_code(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);

        $this->post(route('login.resend'))->assertRedirect(route('login.verify'));
        Notification::assertSentToTimes($patient, LoginCodeNotification::class, 1);

        $this->travel(61)->seconds();

        $this->post(route('login.resend'))->assertRedirect(route('login.verify'));
        Notification::assertSentToTimes($patient, LoginCodeNotification::class, 2);
    }

    public function test_new_code_replaces_the_previous_one(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);

        $this->travel(61)->seconds();
        $this->post(route('login.resend'));

        $this->assertSame(1, OneTimeCode::where('user_id', $patient->id)->where('purpose', CodePurpose::Login)->count());
        $this->post(route('login.verify'), ['code' => $this->sentCode($patient)])->assertRedirect(route('portal'));
    }

    public function test_signed_in_user_is_sent_away_from_login(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    private function sentCode(User $user): string
    {
        return Notification::sent($user, LoginCodeNotification::class)->last()->code;
    }

    private function wrongCode(User $user): string
    {
        return $this->sentCode($user) === '0000' ? '1111' : '0000';
    }
}
