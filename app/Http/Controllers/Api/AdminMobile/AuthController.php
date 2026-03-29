<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminMobile\LoginRequest;
use App\Http\Resources\AdminMobile\AdminUserResource;
use App\Services\AdminMobile\AdminMobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileAuthService $adminMobileAuthService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->adminMobileAuthService->login($request->validated());

        return $this->successResponse('Login admin mobile berhasil.', [
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'user' => AdminUserResource::make($result['user']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $token = $this->adminMobileAuthService->currentAccessToken($request);

        $this->adminMobileAuthService->logout($user, $token);

        return $this->successResponse('Logout admin mobile berhasil.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);

        return $this->successResponse('Profil admin mobile berhasil diambil.', [
            'user' => AdminUserResource::make($user),
        ]);
    }
}
