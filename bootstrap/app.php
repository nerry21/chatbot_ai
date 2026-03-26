<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Webhook routes loaded separately so CSRF can be excluded cleanly
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/webhook.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exclude webhook paths from CSRF token verification
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);

        // Register named middleware aliases
        $middleware->alias([
            'chatbot.admin' => \App\Http\Middleware\EnsureChatbotAdminAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
