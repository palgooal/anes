<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Re-enter the password before a sensitive action (Laravel's "password.confirm"
 * middleware). Used by the WhatsApp settings (REQ-WA-42). The confirmation lasts
 * for auth.password_timeout.
 */
class ConfirmPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.staff.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['password' => ['required', 'string', 'current_password']],
            [
                'password.required' => 'اكتب كلمة المرور.',
                'password.current_password' => 'كلمة المرور غير صحيحة.',
            ],
        );

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('staff.dashboard'));
    }
}
