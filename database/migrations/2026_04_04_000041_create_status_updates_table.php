<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('author_type', 30)->default('admin');
            $table->string('status_type', 30);

            $table->text('text')->nullable();
            $table->string('caption', 500)->nullable();

            $table->string('background_color', 20)->nullable();
            $table->string('text_color', 20)->nullable();
            $table->string('font_style', 50)->nullable();

            $table->string('media_disk', 50)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime_type', 120)->nullable();
            $table->string('media_original_name', 255)->nullable();
            $table->unsignedBigInteger('media_size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->json('music_meta')->nullable();

            $table->string('audience_scope', 50)->default('contacts_and_chatters');
            $table->boolean('is_active')->default(true);

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['author_type', 'status_type']);
            $table->index(['is_active', 'expires_at']);
            $table->index(['user_id', 'posted_at']);
            $table->index(['customer_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_updates');
    }
};
