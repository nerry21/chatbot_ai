<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_case_memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_signal_id')->nullable()->constrained('chatbot_learning_signals')->nullOnDelete()->unique();
            $table->foreignId('admin_correction_id')->nullable()->constrained('chatbot_admin_corrections')->nullOnDelete()->unique();
            $table->string('source_type')->default('learning_signal')->index();
            $table->string('intent')->nullable()->index();
            $table->string('sub_intent')->nullable()->index();
            $table->text('user_message')->nullable();
            $table->text('context_summary')->nullable();
            $table->longText('successful_response')->nullable();
            $table->json('example_payload')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_case_memories');
    }
};
