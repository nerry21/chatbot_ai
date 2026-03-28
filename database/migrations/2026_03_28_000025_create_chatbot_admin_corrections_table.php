<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_admin_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_signal_id')->nullable()->constrained('chatbot_learning_signals')->nullOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('bot_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->foreignId('admin_message_id')->constrained('conversation_messages')->cascadeOnDelete()->unique();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('failure_type')->nullable()->index();
            $table->string('reason')->nullable();
            $table->text('customer_message_text')->nullable();
            $table->longText('bot_response_text')->nullable();
            $table->longText('admin_correction_text')->nullable();
            $table->json('correction_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_admin_corrections');
    }
};
