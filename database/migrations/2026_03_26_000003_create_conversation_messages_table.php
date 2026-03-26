<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction')->index();          // inbound | outbound
            $table->string('sender_type');                 // customer | bot | agent | system
            $table->string('message_type')->default('text');
            $table->longText('message_text')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('wa_message_id')->nullable()->unique();
            $table->string('ai_intent')->nullable();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
