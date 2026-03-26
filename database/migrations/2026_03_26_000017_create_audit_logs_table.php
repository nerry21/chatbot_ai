<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Who performed the action (nullable for system/queue-driven actions)
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();

            // What was done — corresponds to AuditActionType enum values
            $table->string('action_type')->index();

            // Optional polymorphic target (e.g. ConversationMessage, Escalation)
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_index');

            // Denormalised for fast conversation-scoped queries
            $table->unsignedBigInteger('conversation_id')->nullable()->index();

            // Human-readable description of the event
            $table->longText('message')->nullable();

            // Arbitrary JSON context (IDs, status before/after, error messages, etc.)
            $table->json('context')->nullable();

            // Request metadata for security investigations
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Immutable timestamp — no updated_at
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
