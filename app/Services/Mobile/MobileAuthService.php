<?php

namespace App\Services\Mobile;

use App\Enums\ConversationChannel;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileAuthService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{customer: Customer, access_token: string, token: MobileAccessToken, created: bool}
     */
    public function register(array $payload): array
    {
        $mobileUserId = $this->resolveMobileUserId($payload['mobile_user_id'] ?? null);
        $deviceId = trim((string) $payload['device_id']);
        $email = $this->nullableString($payload['email'] ?? null);

        return DB::transaction(function () use ($mobileUserId, $deviceId, $email, $payload): array {
            $customer = $this->findCustomerForRegistration($mobileUserId, $email);
            $created = $customer === null;

            if ($customer === null) {
                $customer = new Customer();
                $customer->phone_e164 = $this->syntheticPhone($mobileUserId);
                $customer->status = 'active';
            }

            if ($customer->mobile_device_id !== null && $customer->mobile_device_id !== $deviceId) {
                throw ValidationException::withMessages([
                    'device_id' => ['Perangkat ini tidak cocok dengan akun mobile yang terdaftar.'],
                ]);
            }

            $customer->fill([
                'mobile_user_id' => $mobileUserId,
                'mobile_device_id' => $deviceId,
                'preferred_channel' => $payload['preferred_channel'] ?? ConversationChannel::MobileLiveChat->value,
                'avatar_url' => $payload['avatar_url'] ?? $customer->avatar_url,
                'name' => $payload['name'] ?? $customer->name ?? 'Mobile Customer',
                'email' => $email ?? $customer->email,
                'last_interaction_at' => now(),
            ]);
            $customer->save();

            $customer->mobileAccessTokens()
                ->where('device_id', $deviceId)
                ->delete();

            $token = $this->issueToken($customer, $deviceId, 'register');

            return [
                'customer' => $customer->fresh() ?? $customer,
                'access_token' => $token['plain_text_token'],
                'token' => $token['model'],
                'created' => $created,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{customer: Customer, access_token: string, token: MobileAccessToken}
     */
    public function login(array $payload): array
    {
        $mobileUserId = trim((string) $payload['mobile_user_id']);
        $deviceId = trim((string) $payload['device_id']);

        return DB::transaction(function () use ($mobileUserId, $deviceId): array {
            $customer = Customer::query()
                ->where('mobile_user_id', $mobileUserId)
                ->first();

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'mobile_user_id' => ['Akun mobile tidak ditemukan.'],
                ]);
            }

            if ($customer->mobile_device_id !== null && $customer->mobile_device_id !== $deviceId) {
                throw ValidationException::withMessages([
                    'device_id' => ['Perangkat ini tidak diizinkan untuk akun mobile tersebut.'],
                ]);
            }

            $customer->forceFill([
                'mobile_device_id' => $deviceId,
                'preferred_channel' => $customer->preferred_channel ?: ConversationChannel::MobileLiveChat->value,
                'last_interaction_at' => now(),
            ])->save();

            $customer->mobileAccessTokens()
                ->where('device_id', $deviceId)
                ->delete();

            $token = $this->issueToken($customer, $deviceId, 'login');

            return [
                'customer' => $customer->fresh() ?? $customer,
                'access_token' => $token['plain_text_token'],
                'token' => $token['model'],
            ];
        });
    }

    public function logout(Customer $customer, ?MobileAccessToken $token): void
    {
        if ($token !== null && $token->customer_id === $customer->id) {
            $token->delete();
        }
    }

    /**
     * @return array{0: Customer, 1: MobileAccessToken}
     */
    public function authenticateRequest(Request $request): array
    {
        $plainTextToken = trim((string) $request->bearerToken());

        if ($plainTextToken === '') {
            throw new HttpException(401, 'Bearer token mobile wajib dikirim.');
        }

        $token = MobileAccessToken::query()
            ->with('customer')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($token === null || $token->customer === null || $token->isExpired()) {
            throw new HttpException(401, 'Token mobile tidak valid atau sudah kedaluwarsa.');
        }

        if (($token->customer->status ?? 'active') !== 'active') {
            throw new HttpException(403, 'Akun mobile tidak aktif.');
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $token->customer->forceFill(['last_interaction_at' => now()])->save();

        return [$token->customer->fresh() ?? $token->customer, $token->fresh() ?? $token];
    }

    public function currentCustomer(Request $request): Customer
    {
        $customer = $request->attributes->get('mobile_customer');

        if ($customer instanceof Customer) {
            return $customer;
        }

        [$customer] = $this->authenticateRequest($request);

        return $customer;
    }

    public function currentAccessToken(Request $request): ?MobileAccessToken
    {
        $token = $request->attributes->get('mobile_access_token');

        return $token instanceof MobileAccessToken ? $token : null;
    }

    /**
     * @return array{plain_text_token: string, model: MobileAccessToken}
     */
    private function issueToken(Customer $customer, string $deviceId, string $name): array
    {
        $plainTextToken = Str::random(64);

        $model = MobileAccessToken::create([
            'customer_id' => $customer->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'device_id' => $deviceId,
            'expires_at' => now()->addDays((int) config('chatbot.mobile_live_chat.auth_token_ttl_days', 30)),
        ]);

        return [
            'plain_text_token' => $plainTextToken,
            'model' => $model,
        ];
    }

    private function findCustomerForRegistration(string $mobileUserId, ?string $email): ?Customer
    {
        $customer = Customer::query()
            ->where('mobile_user_id', $mobileUserId)
            ->first();

        if ($customer !== null) {
            return $customer;
        }

        if ($email === null) {
            return null;
        }

        return Customer::query()
            ->where('email', $email)
            ->first();
    }

    private function resolveMobileUserId(?string $mobileUserId): string
    {
        $normalized = $this->nullableString($mobileUserId);

        return $normalized ?? 'mlc_usr_'.Str::lower((string) Str::ulid());
    }

    private function syntheticPhone(string $mobileUserId): string
    {
        return 'mlc:'.substr(hash('sha256', $mobileUserId), 0, 32);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
