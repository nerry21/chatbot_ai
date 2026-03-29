<?php

namespace App\Http\Middleware;

use App\Services\Mobile\MobileAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileCustomer
{
    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$customer, $token] = $this->mobileAuthService->authenticateRequest($request);

        $request->attributes->set('mobile_customer', $customer);
        $request->attributes->set('mobile_access_token', $token);

        return $next($request);
    }
}
