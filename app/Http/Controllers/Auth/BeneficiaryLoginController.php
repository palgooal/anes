<?php

namespace App\Http\Controllers\Auth;

use App\CodePurpose;
use App\Http\Controllers\Auth\Concerns\HandlesOneTimeCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PhoneLoginRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Patients and companions: phone number → WhatsApp code → their page.
 * The account type (patient or companion) comes from the number itself.
 */
class BeneficiaryLoginController extends Controller
{
    use HandlesOneTimeCodes;

    public function create(): View
    {
        return view('auth.beneficiary.phone');
    }

    public function store(PhoneLoginRequest $request): RedirectResponse|View
    {
        $user = User::query()
            ->where('phone', $request->validated('phone'))
            ->whereIn('role', UserRole::beneficiaries())
            ->first();

        if (! $user) {
            return view('auth.beneficiary.unknown', ['phone' => $request->validated('phone')]);
        }

        if (! $user->isActive()) {
            return back()->withInput()->withErrors(['phone' => 'هذا الحساب غير مفعّل. تواصل معنا عبر واتساب.']);
        }

        if ($seconds = $this->lockedSeconds($this->codeThrottleKey($user))) {
            return $this->lockedView($seconds, 'login', 'beneficiary');
        }

        return $this->sendCodeAndContinue($request, $user, CodePurpose::Login, remember: true, verifyRoute: 'login.verify');
    }

    public function show(Request $request): RedirectResponse|View
    {
        $user = $this->pendingUser($request, CodePurpose::Login);

        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.beneficiary.verify', $this->codeScreenData($user, CodePurpose::Login));
    }

    public function verify(VerifyCodeRequest $request): RedirectResponse|View
    {
        $user = $this->pendingUser($request, CodePurpose::Login);

        if (! $user) {
            return redirect()->route('login');
        }

        return $this->checkCode($user, CodePurpose::Login, $request->validated('code'), 'login', 'beneficiary')
            ?? $this->completeLogin($request, $user);
    }

    public function resend(Request $request): RedirectResponse
    {
        return $this->resendCode($request, CodePurpose::Login, 'login', 'login.verify');
    }
}
