<?php

namespace App\Services\AdminMobile;

use App\Models\AdminMobileAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminMobileAuthService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: User, access_token: string, token: AdminMobileAccessToken}
     */
    public function login(array $payload): array
    {
        $email = Str::lower(trim((string) $payload['email']));
        $password = (string) $payload['password'];
        $deviceName = $this->nullableString($payload['device_name'] ?? null) ?? 'admin-mobile';
        $deviceId = $this->nullableString($payload['device_id'] ?? null);

        return DB::transaction(function () use ($email, $password, $deviceName, $deviceId): array {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($user === null || ! Hash::check($password, (string) $user->getAuthPassword())) {
                throw new HttpException(401, 'Kredensial admin tidak valid.');
            }

            if (! $user->canAccessChatbotAdmin()) {
                throw new HttpException(403, 'Akun ini tidak memiliki akses admin mobile.');
            }

            if ($deviceId !== null) {
                $user->adminMobileAccessTokens()
                    ->where('device_id', $deviceId)
                    ->delete();
            }

            $token = $this->issueToken($user, $deviceName, $deviceId);

            return [
                'user' => $user->fresh() ?? $user,
                'access_token' => $token['plain_text_token'],
                'token' => $token['model'],
            ];
        });
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
            throw new HttpException(401, 'Bearer token admin mobile wajib dikirim.');
        }

        $token = AdminMobileAccessToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($token === null || $token->user === null || $token->isExpired()) {
            throw new HttpException(401, 'Token admin mobile tidak valid atau sudah kedaluwarsa.');
        }

        if (! $token->user->canAccessChatbotAdmin()) {
            throw new HttpException(403, 'Akun ini tidak memiliki akses admin mobile.');
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
    private function issueToken(User $user, string $deviceName, ?string $deviceId): array
    {
        $plainTextToken = Str::random(64);

        $model = AdminMobileAccessToken::create([
            'user_id' => $user->id,
            'name' => 'admin-mobile',
            'token_hash' => hash('sha256', $plainTextToken),
            'device_name' => $deviceName,
            'device_id' => $deviceId,
            'expires_at' => now()->addDays((int) config('chatbot.admin_mobile.auth_token_ttl_days', 30)),
        ]);

        return [
            'plain_text_token' => $plainTextToken,
            'model' => $model,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
