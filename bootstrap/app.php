<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company.scope' => \App\Http\Middleware\CompanyScopeMiddleware::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'apply.settings' => \App\Http\Middleware\ApplySystemSettings::class,
            'set.locale' => \App\Http\Middleware\SetLocale::class,
            'subscription.check' => \App\Http\Middleware\CheckSubscriptionStatus::class,
        ]);

        // Exclude API routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'dcb/callback',
        ]);

        // Apply system settings globally
        $middleware->append(\App\Http\Middleware\ApplySystemSettings::class);

        // Set locale globally
        $middleware->append(\App\Http\Middleware\SetLocale::class);

        // Check subscription status globally (except for auth routes)
        $middleware->append(\App\Http\Middleware\CheckSubscriptionStatus::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, \Illuminate\Http\Request $request) {
            $loginUrl = route('login');
            $message = 'Your page has expired. Please log in again to continue.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => $message,
                    'session_expired' => true,
                    'redirect' => $loginUrl,
                ], 419);
            }

            return response()->view('errors.419', [
                'loginUrl' => $loginUrl,
                'message' => $message,
            ], 419);
        });
    })->create();
