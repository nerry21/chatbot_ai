<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/webhook.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'meta/data-deletion-callback',
        ]);

        $middleware->alias([
            'chatbot.admin' => \App\Http\Middleware\EnsureChatbotAdminAccess::class,
            'mobile.auth' => \App\Http\Middleware\AuthenticateMobileCustomer::class,
            'admin.mobile.auth' => \App\Http\Middleware\AuthenticateAdminMobile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isMobileApiRequest = static fn (Request $request): bool => $request->is('api/mobile/*') || $request->is('api/admin-mobile/*');

        $exceptions->render(function (ValidationException $e, Request $request) use ($isMobileApiRequest) {
            if (! $isMobileApiRequest($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Validasi request gagal.',
                'data' => [
                    'errors' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isMobileApiRequest) {
            if (! $isMobileApiRequest($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Autentikasi API gagal.',
                'data' => null,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isMobileApiRequest) {
            if (! $isMobileApiRequest($request)) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Akses ke resource API ditolak.',
                'data' => null,
            ], 403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isMobileApiRequest) {
            if (! $isMobileApiRequest($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : \Symfony\Component\HttpFoundation\Response::$statusTexts[$status],
                'data' => null,
            ], $status);
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isMobileApiRequest) {
            if (! $isMobileApiRequest($request)) {
                return null;
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal pada API.',
                'data' => null,
            ], 500);
        });
    })->create();
