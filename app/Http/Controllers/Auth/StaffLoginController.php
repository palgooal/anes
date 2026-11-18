<?php

namespace App\Http\Controllers\Auth;

use App\AccountStatus;
use App\CodePurpose;
use App\Http\Controllers\Auth\Concerns\HandlesOneTimeCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Guides, consultants and administrators: email + password → two-factor
 * code on WhatsApp → the dashboard of their role.
 */
class StaffLoginController extends Controller
{
    use HandlesOneTimeCodes;

    public function create(): View
    {
        return view('auth.staff.login');
    }

    public function store(StaffLoginRequest $request): RedirectResponse|View
    {
        $throttleKey = $request->throttleKey();

        if ($seconds = $this->lockedSeconds($throttleKey)) {
            return $this->lockedView($seconds, 'staff.login', 'staff');
        }

        $user = User::staff()->where('email', $request->validated('email'))->first();

        if (! $user || ! $user->password || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($throttleKey, (int) config('auth.one_time_codes.lockout_seconds'));

            if ($seconds = $this->lockedSeconds($throttleKey)) {
                return $this->lockedView($seconds, 'staff.login', 'staff');
            }

            $left = RateLimiter::remaining($throttleKey, (int) config('auth.one_time_codes.max_attempts'));

            return back()->onlyInput('email', 'remember')->withErrors([
                'credentials' => 'البريد أو كلمة المرور غير صحيحة.'.($left <= 3 ? ' بقي لك '.self::arabicCount($left).' قبل الإيقاف المؤقت.' : ''),
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->status !== AccountStatus::Active) {
            return redirect()->route('staff.status')->with('account_status', $user->status->value);
        }

        if (! $user->phone) {
            return back()->onlyInput('email')->withErrors([
                'credentials' => 'لا يوجد رقم جوال مرتبط بحسابك لإرسال رمز التحقق. تواصل مع الإدارة.',
            ]);
        }

        return $this->sendCodeAndContinue($request, $user, CodePurpose::TwoFactor, $request->boolean('remember'), 'staff.verify');
    }

    public function show(Request $request): RedirectResponse|View
    {
        $user = $this->pendingUser($request, CodePurpose::TwoFactor);

        if (! $user) {
            return redirect()->route('staff.login');
        }

        return view('auth.staff.verify', $this->codeScreenData($user, CodePurpose::TwoFactor));
    }

    public function verify(VerifyCodeRequest $request): RedirectResponse|View
    {
        $user = $this->pendingUser($request, CodePurpose::TwoFactor);

        if (! $user) {
            return redirect()->route('staff.login');
        }

        return $this->checkCode($user, CodePurpose::TwoFactor, $request->validated('code'), 'staff.login', 'staff')
            ?? $this->completeLogin($request, $user);
    }

    public function resend(Request $request): RedirectResponse
    {
        return $this->resendCode($request, CodePurpose::TwoFactor, 'staff.login', 'staff.verify');
    }

    /**
     * "Pending review" or "suspended" screen, reached right after a correct password.
     */
    public function status(Request $request): RedirectResponse|View
    {
        $status = AccountStatus::tryFrom((string) $request->session()->get('account_status'));

        if (! in_array($status, [AccountStatus::Pending, AccountStatus::Suspended], true)) {
            return redirect()->route('staff.login');
        }

        return view('auth.staff.status', ['status' => $status]);
    }
}
