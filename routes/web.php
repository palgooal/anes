<?php

use App\Http\Controllers\Auth\BeneficiaryLoginController;
use App\Http\Controllers\Auth\JoinRequestController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Staff\TeamController;
use App\Http\Controllers\Staff\TeamMemberStatusController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

/*
| Beneficiaries (patient or companion): phone number + WhatsApp code.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [BeneficiaryLoginController::class, 'create'])->name('login');
    Route::post('/login', [BeneficiaryLoginController::class, 'store'])->middleware('throttle:login-codes');
    Route::get('/login/verify', [BeneficiaryLoginController::class, 'show'])->name('login.verify');
    Route::post('/login/verify', [BeneficiaryLoginController::class, 'verify']);
    Route::post('/login/resend', [BeneficiaryLoginController::class, 'resend'])->middleware('throttle:login-codes')->name('login.resend');
});

/*
| Staff: email + password, then a two-factor code on WhatsApp.
*/
Route::prefix('staff')->middleware('guest')->group(function () {
    Route::get('/login', [StaffLoginController::class, 'create'])->name('staff.login');
    Route::post('/login', [StaffLoginController::class, 'store'])->middleware('throttle:staff-login');
    Route::get('/verify', [StaffLoginController::class, 'show'])->name('staff.verify');
    Route::post('/verify', [StaffLoginController::class, 'verify']);
    Route::post('/resend', [StaffLoginController::class, 'resend'])->middleware('throttle:login-codes')->name('staff.resend');
    Route::get('/account-status', [StaffLoginController::class, 'status'])->name('staff.status');

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:login-codes')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    Route::get('/join', [JoinRequestController::class, 'create'])->name('staff.join');
    Route::post('/join', [JoinRequestController::class, 'store'])->middleware('throttle:login-codes');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'redirect'])->name('dashboard');
    Route::get('/portal', [DashboardController::class, 'show'])->middleware('role:beneficiary')->name('portal');
    Route::get('/staff/dashboard', [DashboardController::class, 'show'])->middleware('role:staff')->name('staff.dashboard');
    Route::post('/logout', LogoutController::class)->name('logout');
});

/*
| Administration (staff area, administrators only).
*/
Route::prefix('staff')->middleware(['auth', 'role:staff', 'can:manage-team'])->group(function () {
    Route::get('/team', [TeamController::class, 'index'])->name('staff.team');
    Route::post('/team', [TeamController::class, 'store'])->name('staff.team.store');
    Route::delete('/team/{member}', [TeamController::class, 'destroy'])->name('staff.team.destroy');
    Route::patch('/team/{member}/status', [TeamMemberStatusController::class, 'update'])->name('staff.team.status');
});
