<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Services\AdminMobile\AdminMobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileAuthService $authService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->authService->login($validated);

        return $this->successResponse('Login admin mobile berhasil.', [
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'user' => $this->userPayload($result['user']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->currentUser($request);

        return $this->successResponse('Profil admin berhasil diambil.', [
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->authService->currentUser($request);
        $token = $this->authService->currentAccessToken($request);

        $this->authService->logout($user, $token);

        return $this->successResponse('Logout admin mobile berhasil.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload($user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_chatbot_admin' => (bool) $user->is_chatbot_admin,
            'is_chatbot_operator' => (bool) $user->is_chatbot_operator,
        ];
    }
}
