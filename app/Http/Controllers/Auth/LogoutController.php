<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    /**
     * Sign out and return to the login screen the user came from.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $isBeneficiary = (bool) $request->user()?->role->isBeneficiary();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($isBeneficiary ? 'login' : 'staff.login');
    }
}
