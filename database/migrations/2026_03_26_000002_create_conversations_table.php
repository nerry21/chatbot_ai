<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->default('whatsapp')->index();
            $table->string('channel_conversation_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->string('current_intent')->nullable();
            $table->longText('summary')->nullable();
            $table->boolean('needs_human')->default(false)->index();
            $table->string('escalation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
