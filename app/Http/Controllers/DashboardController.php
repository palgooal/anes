<?php

namespace App\Http\Controllers;

use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The role dashboards. One view serves every role; the dashboard script
 * picks the panel from the "role" value passed here.
 */
class DashboardController extends Controller
{
    /**
     * /dashboard: send the user to their own page.
     */
    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->role->isBeneficiary() ? 'portal' : 'staff.dashboard');
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        $role = $user->role;
        $patient = $role === UserRole::Companion ? $user->patient : $user;

        return view('dashboard', [
            'config' => [
                'role' => $role->panel(),
                'who' => $role->isStaff() ? $user->name.' · '.$role->label() : '',
                'kind' => $role === UserRole::Companion ? 'المرافق' : 'المريض',
                'patientName' => $patient?->name ?? $user->name,
                'companionName' => $role === UserRole::Companion ? $user->name : null,
                'waNumber' => config('services.whatsapp.number'),
            ],
        ]);
    }
}
