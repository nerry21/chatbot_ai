<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table): void {
            $table->id();

            // Pemilik kontak (admin yang menyimpan). Bisa null jika kontak global.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Tautan opsional ke customer aktif (jika sudah pernah chat).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // Tautan opsional ke conversation pertama yang dibuat saat kontak ditambahkan.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            // Identitas kontak (mirror gaya WhatsApp).
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 200);

            // Nomor WhatsApp dalam format E.164 (contoh: +6282252499510).
            $table->string('phone_e164', 30);

            // Nomor "raw" yang diketik admin (untuk audit), opsional.
            $table->string('phone_raw', 50)->nullable();

            // Field tambahan opsional.
            $table->string('email', 150)->nullable();
            $table->string('country_code', 5)->nullable();

            // Apakah nomor ini terverifikasi terdaftar di WhatsApp Business API.
            $table->boolean('is_whatsapp_verified')->default(false);

            // Apakah kontak disinkronkan ke perangkat (sesuai toggle di UI).
            $table->boolean('sync_to_device')->default(true);

            // Sumber kontak (admin_mobile, manual, import_csv, dll).
            $table->string('source', 50)->default('admin_mobile');

            // Avatar opsional.
            $table->string('avatar_url', 500)->nullable();

            // Catatan internal admin.
            $table->text('notes')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indeks: cegah duplikasi nomor untuk admin yang sama.
            $table->unique(['user_id', 'phone_e164'], 'whatsapp_contacts_user_phone_unique');

            // Indeks untuk lookup cepat berdasarkan nomor.
            $table->index('phone_e164', 'whatsapp_contacts_phone_index');
            $table->index('customer_id', 'whatsapp_contacts_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};