<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * "Forgot password" for staff, using Laravel's password broker (email link).
 */
class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.staff.forgot-password');
    }

    /**
     * Always answer the same way, so the form does not reveal which emails exist.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'اكتب بريدك الإلكتروني.',
            'email.email' => 'اكتب بريداً إلكترونياً صحيحاً.',
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with('sent_to', $request->string('email')->toString());
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.staff.reset-password', ['token' => $token, 'email' => $request->string('email')->toString()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'email.required' => 'اكتب بريدك الإلكتروني.',
            'email.email' => 'اكتب بريداً إلكترونياً صحيحاً.',
            'password.required' => 'اكتب كلمة المرور الجديدة.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
            'password.min' => 'كلمة المرور ٨ أحرف على الأقل.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return back()->onlyInput('email')->withErrors(['email' => 'رابط الاستعادة غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.']);
        }

        return redirect()->route('staff.login')->with('status', 'تم تعيين كلمة المرور الجديدة. سجّل الدخول بها.');
    }
}
