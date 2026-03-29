<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Mobile\Concerns\RespondsWithMobileJson;
use App\Http\Requests\Api\Mobile\LoginRequest;
use App\Http\Requests\Api\Mobile\RegisterRequest;
use App\Http\Resources\Mobile\CustomerResource;
use App\Services\Mobile\MobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use RespondsWithMobileJson;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->mobileAuthService->register($request->validated());

        return $this->successResponse(
            ($result['created'] ?? false) ? 'Akun mobile berhasil dibuat.' : 'Akun mobile berhasil disinkronkan.',
            [
                'access_token' => $result['access_token'],
                'token_type' => 'Bearer',
                'customer' => CustomerResource::make($result['customer']),
            ],
            ($result['created'] ?? false) ? 201 : 200,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->mobileAuthService->login($request->validated());

        return $this->successResponse('Login mobile berhasil.', [
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'customer' => CustomerResource::make($result['customer']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $token = $this->mobileAuthService->currentAccessToken($request);

        $this->mobileAuthService->logout($customer, $token);

        return $this->successResponse('Logout mobile berhasil.');
    }

    public function me(Request $request): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);

        return $this->successResponse('Profil mobile berhasil diambil.', [
            'customer' => CustomerResource::make($customer),
        ]);
    }
}
