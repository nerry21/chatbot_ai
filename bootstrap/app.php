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
            'mobile.auth' => \App\Http\Middleware\AuthenticateMobileCustomer::class,
            'admin-mobile.auth' => \App\Http\Middleware\AuthenticateAdminMobile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isMobileApiRequest = static fn (Request $request): bool => $request->is('api/mobile/*');
        $isAdminMobileApiRequest = static fn (Request $request): bool => $request->is('api/admin-mobile/*');
        $apiErrorContext = static function (Request $request) use ($isMobileApiRequest, $isAdminMobileApiRequest): ?array {
            if ($isMobileApiRequest($request)) {
                return [
                    'validation_message' => 'Validasi request gagal.',
                    'authentication_message' => 'Autentikasi mobile gagal.',
                    'authorization_message' => 'Akses ke resource mobile ditolak.',
                    'http_fallback_message' => 'Permintaan mobile API gagal.',
                    'internal_message' => 'Terjadi kesalahan internal pada mobile API.',
                ];
            }

            if ($isAdminMobileApiRequest($request)) {
                return [
                    'validation_message' => 'Validasi request admin mobile gagal.',
                    'authentication_message' => 'Autentikasi admin mobile gagal.',
                    'authorization_message' => 'Akses ke resource admin mobile ditolak.',
                    'http_fallback_message' => 'Permintaan admin mobile API gagal.',
                    'internal_message' => 'Terjadi kesalahan internal pada admin mobile API.',
                ];
            }

            return null;
        };

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiErrorContext) {
            $context = $apiErrorContext($request);

            if ($context === null) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $context['validation_message'],
                'data' => [
                    'errors' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($apiErrorContext) {
            $context = $apiErrorContext($request);

            if ($context === null) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $context['authentication_message'],
                'data' => null,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($apiErrorContext) {
            $context = $apiErrorContext($request);

            if ($context === null) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $context['authorization_message'],
                'data' => null,
            ], 403);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($apiErrorContext) {
            $context = $apiErrorContext($request);

            if ($context === null) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : (\Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? $context['http_fallback_message']),
                'data' => null,
            ], $status);
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($apiErrorContext) {
            $context = $apiErrorContext($request);

            if ($context === null) {
                return null;
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => $context['internal_message'],
                'data' => null,
            ], 500);
        });
    })->create();
