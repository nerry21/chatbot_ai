<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Models\DeviceFcmToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    use RespondsWithAdminMobileJson;

    /**
     * POST /api/admin-mobile/device-token/register
     *
     * Flutter app memanggil endpoint ini setelah mendapat FCM registration token
     * dari FirebaseMessaging.instance.getToken(). Token disimpan ke database
     * agar backend bisa mengirim push notification ke device tersebut.
     */
    public function register(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:500'],
            'device_name' => ['nullable', 'string', 'max:150'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        $fcmToken = trim((string) $request->input('fcm_token'));
        $tokenHash = DeviceFcmToken::hashToken($fcmToken);

        // Upsert: update kalau token sudah ada, insert kalau belum.
        $deviceToken = DeviceFcmToken::query()
            ->updateOrCreate(
                ['token_hash' => $tokenHash],
                [
                    'user_id' => (int) $user->id,
                    'fcm_token' => $fcmToken,
                    'device_name' => $request->input('device_name', 'Android Device'),
                    'platform' => $request->input('platform', 'android'),
                    'is_active' => true,
                    'consecutive_failures' => 0,
                ]
            );

        return $this->successResponse('Device token berhasil didaftarkan.', [
            'device_token_id' => (int) $deviceToken->id,
            'is_active' => true,
        ]);
    }

    /**
     * POST /api/admin-mobile/device-token/unregister
     *
     * Dipanggil saat admin logout dari app, supaya tidak menerima
     * push notification lagi di device tersebut.
     */
    public function unregister(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $fcmToken = trim((string) $request->input('fcm_token'));
        $tokenHash = DeviceFcmToken::hashToken($fcmToken);

        $deleted = DeviceFcmToken::query()
            ->where('token_hash', $tokenHash)
            ->where('user_id', (int) $user->id)
            ->delete();

        return $this->successResponse(
            $deleted > 0
                ? 'Device token berhasil dihapus.'
                : 'Token tidak ditemukan (sudah dihapus atau belum terdaftar).',
            ['removed' => $deleted > 0]
        );
    }
}