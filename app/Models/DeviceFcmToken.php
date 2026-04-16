<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceFcmToken extends Model
{
    protected $table = 'device_fcm_tokens';

    protected $fillable = [
        'user_id',
        'fcm_token',
        'token_hash',
        'device_name',
        'platform',
        'is_active',
        'last_used_at',
        'consecutive_failures',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'last_used_at'         => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    /**
     * Token terlalu sensitif untuk di-serialize ke JSON tanpa sengaja.
     */
    protected $hidden = [
        'fcm_token',
        'token_hash',
    ];

    // ─── Relations ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Buat hash deterministik dari FCM token untuk unique constraint.
     * FCM token bisa sangat panjang (200+ karakter), hash mempermudah
     * lookup dan unique check.
     */
    public static function hashToken(string $fcmToken): string
    {
        return hash('sha256', trim($fcmToken));
    }

    /**
     * Tandai token sebagai gagal. Setelah beberapa kali gagal berturut-turut,
     * token otomatis di-deactivate (device uninstalled app / token expired).
     */
    public function recordFailure(): void
    {
        $this->increment('consecutive_failures');

        // Auto-deactivate setelah 5 kali gagal berturut-turut.
        if ($this->consecutive_failures >= 5) {
            $this->update(['is_active' => false]);
        }
    }

    /**
     * Reset failure counter setelah berhasil kirim.
     */
    public function recordSuccess(): void
    {
        $this->update([
            'consecutive_failures' => 0,
            'last_used_at' => now(),
            'is_active' => true,
        ]);
    }
}