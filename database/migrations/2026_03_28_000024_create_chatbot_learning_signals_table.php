<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_learning_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_message_id')->constrained('conversation_messages')->cascadeOnDelete()->unique();
            $table->foreignId('outbound_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->text('user_message')->nullable();
            $table->text('context_summary')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->json('understanding_result')->nullable();
            $table->string('chosen_action')->nullable()->index();
            $table->json('grounded_facts')->nullable();
            $table->longText('final_response')->nullable();
            $table->json('final_response_meta')->nullable();
            $table->string('resolution_status')->default('answered')->index();
            $table->boolean('fallback_used')->default(false)->index();
            $table->boolean('handoff_happened')->default(false)->index();
            $table->boolean('admin_takeover_active')->default(false)->index();
            $table->boolean('outbound_sent')->default(false);
            $table->string('failure_type')->nullable()->index();
            $table->json('failure_signals')->nullable();
            $table->boolean('corrected_by_admin')->default(false)->index();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_learning_signals');
    }
};
