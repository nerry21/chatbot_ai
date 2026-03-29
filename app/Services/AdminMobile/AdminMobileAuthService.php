<?php

namespace App\Services\AdminMobile;

use App\Models\AdminMobileAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminMobileAuthService
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return array{user: User, access_token: string, token: AdminMobileAccessToken}
     */
    public function login(array $credentials): array
    {
        $email = trim((string) ($credentials['email'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password admin tidak valid.'],
            ]);
        }

        if (! $user->canAccessChatbotAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini tidak memiliki akses ke admin omnichannel.'],
            ]);
        }

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $token = $this->issueToken($user, 'admin_login');

        return [
            'user' => $user->fresh() ?? $user,
            'access_token' => $token['plain_text_token'],
            'token' => $token['model'],
        ];
    }

    public function logout(User $user, ?AdminMobileAccessToken $token): void
    {
        if ($token !== null && $token->user_id === $user->id) {
            $token->delete();
        }
    }

    /**
     * @return array{0: User, 1: AdminMobileAccessToken}
     */
    public function authenticateRequest(Request $request): array
    {
        $plainTextToken = trim((string) $request->bearerToken());

        if ($plainTextToken === '') {
            throw new HttpException(401, 'Bearer token admin wajib dikirim.');
        }

        /** @var AdminMobileAccessToken|null $token */
        $token = AdminMobileAccessToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($token === null || $token->user === null || $token->isExpired()) {
            throw new HttpException(401, 'Token admin mobile tidak valid atau sudah kedaluwarsa.');
        }

        if (! $token->user->canAccessChatbotAdmin()) {
            throw new HttpException(403, 'Akses admin omnichannel ditolak.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return [$token->user->fresh() ?? $token->user, $token->fresh() ?? $token];
    }

    public function currentUser(Request $request): User
    {
        $user = $request->attributes->get('admin_mobile_user');

        if ($user instanceof User) {
            return $user;
        }

        [$user] = $this->authenticateRequest($request);

        return $user;
    }

    public function currentAccessToken(Request $request): ?AdminMobileAccessToken
    {
        $token = $request->attributes->get('admin_mobile_access_token');

        return $token instanceof AdminMobileAccessToken ? $token : null;
    }

    /**
     * @return array{plain_text_token: string, model: AdminMobileAccessToken}
     */
    private function issueToken(User $user, string $name): array
    {
        $plainTextToken = Str::random(64);

        $model = AdminMobileAccessToken::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addDays((int) config('chatbot.mobile_live_chat.auth_token_ttl_days', 30)),
        ]);

        return [
            'plain_text_token' => $plainTextToken,
            'model' => $model,
        ];
    }
}
