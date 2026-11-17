<?php

namespace App\Http\Controllers\Auth;

use App\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\JoinTeamRequest;
use App\Models\User;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * "Join the team": creates a pending staff account without a password.
 * After the administration approves it, the person sets a password with
 * the "forgot password" link.
 */
class JoinRequestController extends Controller
{
    public function create(): View
    {
        return view('auth.staff.join', ['roles' => UserRole::joinable()]);
    }

    public function store(JoinTeamRequest $request): RedirectResponse
    {
        User::create([
            ...$request->validated(),
            'status' => AccountStatus::Pending,
        ]);

        return redirect()->route('staff.join')->with('join_sent', true);
    }
}
