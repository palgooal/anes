<?php

use App\Http\Controllers\Auth\BeneficiaryLoginController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\JoinRequestController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Staff\TeamController;
use App\Http\Controllers\Staff\TeamMemberStatusController;
use App\Http\Controllers\Staff\WhatsAppSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
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

/*
| Re-enter the password before a sensitive action (Laravel "password.confirm" middleware).
*/
Route::prefix('staff')->middleware(['auth', 'role:staff'])->group(function () {
    Route::get('/confirm-password', [ConfirmPasswordController::class, 'create'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store'])->middleware('throttle:6,1');
});

/*
| Administration: WhatsApp Cloud API settings (docs/ANIS-WHATSAPP-SETTINGS-001.md, REQ-WA-41).
| Saving secrets and moving management to the panel require a password confirmed within the
| last 15 minutes (REQ-WA-42). The timeout is set on these routes only; auth.password_timeout is unchanged.
| The permission is checked before the role, so every non-administrator gets 403 (AC-WA-25).
*/
Route::prefix('staff/settings/whatsapp')->middleware(['auth', 'can:manage-whatsapp', 'role:staff'])->group(function () {
    Route::get('/', [WhatsAppSettingsController::class, 'show'])->name('staff.whatsapp');
    Route::put('/general', [WhatsAppSettingsController::class, 'updateGeneral'])->name('staff.whatsapp.general');
    Route::put('/environments/{environment}', [WhatsAppSettingsController::class, 'updateEnvironment'])->name('staff.whatsapp.environment');

    Route::middleware(RequirePassword::using(null, WhatsAppSettingsController::PASSWORD_CONFIRMATION_SECONDS))->group(function () {
        Route::post('/take-over', [WhatsAppSettingsController::class, 'takeOver'])->name('staff.whatsapp.take-over');
        Route::put('/environments/{environment}/secrets', [WhatsAppSettingsController::class, 'updateSecrets'])->name('staff.whatsapp.secrets');
        Route::put('/webhook-token', [WhatsAppSettingsController::class, 'updateWebhookToken'])->name('staff.whatsapp.webhook-token');
    });
});
