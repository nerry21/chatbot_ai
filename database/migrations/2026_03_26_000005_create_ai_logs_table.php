<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->foreignId('message_id')
                  ->nullable()
                  ->constrained('conversation_messages')
                  ->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('task_type')->index();
            $table->longText('prompt_snapshot')->nullable();
            $table->longText('response_snapshot')->nullable();
            $table->json('parsed_output')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('token_input')->nullable();
            $table->unsignedInteger('token_output')->nullable();
            $table->string('status')->default('success')->index();
            $table->longText('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
    }
};
