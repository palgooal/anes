<?php

use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
        ]);

        // Staff pages send guests to the staff login, everything else to the beneficiary login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('staff/*') ? route('staff.login') : route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // WhatsApp secrets are never flashed back into the session after a validation error (REQ-WA-19).
        $exceptions->dontFlash(['access_token', 'app_secret', 'webhook_verify_token']);
    })->create();
