<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_fcm_tokens', function (Blueprint $table): void {
            $table->id();

            // Admin yang memiliki device ini.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // FCM registration token dari device.
            $table->text('fcm_token');

            // Hash dari token untuk lookup cepat (unique constraint).
            $table->string('token_hash', 64)->unique();

            // Info device opsional.
            $table->string('device_name', 150)->nullable();
            $table->string('platform', 20)->default('android'); // android|ios|web

            // Status token.
            $table->boolean('is_active')->default(true);

            // Kapan terakhir berhasil kirim notif ke token ini.
            $table->timestamp('last_used_at')->nullable();

            // Kapan token ini gagal berturut-turut (untuk auto-cleanup).
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();

            // Index untuk query "kirim ke semua device admin".
            $table->index(['user_id', 'is_active'], 'device_fcm_tokens_user_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_fcm_tokens');
    }
};