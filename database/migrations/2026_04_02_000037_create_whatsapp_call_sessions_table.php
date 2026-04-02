<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_call_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel')->default('whatsapp');
            $table->string('direction')->default('business_initiated');
            $table->string('call_type')->default('audio');
            $table->string('status')->default('initiated');
            $table->string('wa_call_id')->nullable();
            $table->string('permission_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->json('meta_payload')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index('wa_call_id');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_call_sessions');
    }
};
