<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\CodePurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
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

    public function test_rejects_phone_that_is_not_a_mobile_number(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['phone' => '12345', 'country' => '966'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['phone' => 'رقم الجوال غير صحيح. اكتبه هكذا 05XXXXXXXX، وتأكد من اختيار الدولة.']);
    }

    public function test_rejects_country_that_is_not_enabled(): void
    {
        config(['auth.phone_countries' => ['966']]);
        User::factory()->patient()->create(['phone' => '970599123456']);

        $this->post(route('login'), ['phone' => '0599123456', 'country' => '970'])
            ->assertViewIs('auth.beneficiary.unknown');
        $this->post(route('login'), ['phone' => '+970599123456'])
            ->assertSessionHasErrors('phone');
    }

    public function test_palestinian_number_signs_in_when_the_country_is_enabled(): void
    {
        config(['auth.phone_countries' => ['966', '970', '972']]);
        Notification::fake();
        $developer = User::factory()->patient()->create(['phone' => '970599123456']);

        $this->post(route('login'), ['phone' => '0599123456', 'country' => '970'])
            ->assertRedirect(route('login.verify'));
        $this->get(route('login.verify'))->assertSee('+970 59 912 3456');

        $this->post(route('login.verify'), ['code' => $this->sentCode($developer)])
            ->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($developer);
    }

    public function test_same_national_number_in_another_country_is_a_different_account(): void
    {
        config(['auth.phone_countries' => ['966', '970']]);
        Notification::fake();
        $saudi = User::factory()->patient()->create(['phone' => '966599123456']);

        $this->post(route('login'), ['phone' => '0599123456', 'country' => '970'])
            ->assertViewIs('auth.beneficiary.unknown');
        $this->post(route('login'), ['phone' => '0599123456', 'country' => '966'])
            ->assertRedirect(route('login.verify'));

        Notification::assertSentTo($saudi, LoginCodeNotification::class);
    }

    public function test_whatsapp_failure_shows_a_message_instead_of_an_error_page(): void
    {
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.phone_number_id' => '1122334455', 'services.whatsapp.api_version' => 'v23.0']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/v23.0/1122334455/messages' => Http::response(['error' => ['message' => 'Template not found']], 400)]);
        Exceptions::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = [$event->message, $event->context];
        });
        $patient = User::factory()->patient()->create();

        $this->from(route('login'))
            ->post(route('login'), ['phone' => '+'.$patient->phone])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['send' => 'تعذّر إرسال الرمز على واتساب الآن. حاول بعد قليل، وإن تكرر ذلك تواصل معنا.']);

        // Security review after PH-WA-7: the raw exception, whose message carries Meta's
        // answer, is no longer reported; a fixed line with the HTTP status is logged instead.
        Exceptions::assertNotReported(RequestException::class);
        $this->assertContains(['WhatsApp: the code could not be sent.', ['http_status' => 400, 'meta_code' => null]], $logged);
        $this->get(route('login.verify'))->assertRedirect(route('login'));
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
        $patient = User::factory()->patient()->create(['phone' => '966551234567']);

        // Arabic-Indic digits and an international prefix are normalised.
        $this->post(route('login'), ['phone' => '+966 ٥٥١٢٣٤٥٦٧'])
            ->assertRedirect(route('login.verify'));

        Notification::assertSentTo($patient, LoginCodeNotification::class, fn (LoginCodeNotification $n) => preg_match('/^\d{6}$/', $n->code) === 1);
        $this->get(route('login.verify'))->assertOk()->assertSee('+966 55 123 4567');
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

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->post(route('login.verify'), ['code' => $code])
            ->assertSessionHasErrors(['code' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
        $this->assertGuest();
    }

    public function test_code_screen_without_a_pending_login_returns_to_phone_form(): void
    {
        $this->get(route('login.verify'))->assertRedirect(route('login'));
        $this->post(route('login.verify'), ['code' => '123456'])->assertRedirect(route('login'));
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

    public function test_code_is_still_accepted_just_before_five_minutes(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);
        $code = $this->sentCode($patient);

        $this->travel(4)->minutes();
        $this->travel(59)->seconds();

        $this->post(route('login.verify'), ['code' => $code])->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($patient);
    }

    public function test_code_that_is_not_six_digits_is_rejected(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);
        $code = $this->sentCode($patient);

        foreach ([substr($code, 0, 4), substr($code, 0, 5), $code.'0', 'abcdef'] as $invalid) {
            $this->from(route('login.verify'))
                ->post(route('login.verify'), ['code' => $invalid])
                ->assertSessionHasErrors(['code' => 'اكتب الرمز كاملاً.']);
        }

        $this->assertGuest();
    }

    public function test_issued_codes_are_six_digits_keep_leading_zeros_and_expire_in_five_minutes(): void
    {
        $patient = User::factory()->patient()->create();
        $codes = [];

        for ($i = 0; $i < 300; $i++) {
            $codes[] = OneTimeCode::issueFor($patient, CodePurpose::Login);
        }

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
        $this->assertNotEmpty(array_filter($codes, fn (string $code) => str_starts_with($code, '0')));

        $latest = OneTimeCode::latestFor($patient, CodePurpose::Login);
        $this->assertTrue($latest->matches(end($codes)));
        $this->assertEqualsWithDelta(now()->addMinutes(5)->timestamp, $latest->expires_at->timestamp, 2);
    }

    public function test_code_starting_with_zero_is_accepted(): void
    {
        $patient = User::factory()->patient()->create();
        OneTimeCode::factory()->for($patient)->create(['code' => '012345']);

        $this->withSession(['auth.pending' => ['user_id' => $patient->id, 'purpose' => CodePurpose::Login->value, 'remember' => true]])
            ->post(route('login.verify'), ['code' => str_split('012345')])
            ->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($patient);
    }

    public function test_verify_screen_shows_six_code_boxes(): void
    {
        Notification::fake();
        $patient = User::factory()->patient()->create();
        $this->post(route('login'), ['phone' => $patient->phone]);

        $html = $this->get(route('login.verify'))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'name="code[]"'));
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
        return $this->sentCode($user) === '000000' ? '111111' : '000000';
    }
}
