<?php

namespace App\Http\Middleware;

use App\Services\AdminMobile\AdminMobileAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminMobile
{
    public function __construct(
        private readonly AdminMobileAuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$user, $token] = $this->authService->authenticateRequest($request);

        $request->attributes->set('admin_mobile_user', $user);
        $request->attributes->set('admin_mobile_access_token', $token);

        return $next($request);
    }
}
