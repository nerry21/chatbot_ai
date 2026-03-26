<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_intents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('booking_request_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('conversation_message_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();

            $table->string('detected_intent');
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('extracted_entities')->nullable();
            $table->json('raw_ai_payload')->nullable();

            $table->timestamps();

            $table->index('booking_request_id');
            $table->index('detected_intent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_intents');
    }
};
