<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps beneficiaries and staff in their own area, and signs out accounts
 * that were suspended while logged in.
 *
 * Usage: ->middleware('role:beneficiary') or ->middleware('role:staff')
 */
class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();

        if (! $user->isActive()) {
            $isBeneficiary = $user->role->isBeneficiary();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($isBeneficiary ? 'login' : 'staff.login');
        }

        $allowed = match ($area) {
            'beneficiary' => $user->role->isBeneficiary(),
            'staff' => $user->role->isStaff(),
            default => false,
        };

        if (! $allowed) {
            return redirect()->route($user->role->isBeneficiary() ? 'portal' : 'staff.dashboard');
        }

        return $next($request);
    }
}
