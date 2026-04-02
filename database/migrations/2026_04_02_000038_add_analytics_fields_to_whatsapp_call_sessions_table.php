<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_call_sessions', function (Blueprint $table): void {
            $table->timestamp('connected_at')->nullable()->after('answered_at');
            $table->unsignedInteger('duration_seconds')->nullable()->after('ended_at');
            $table->string('final_status')->nullable()->after('duration_seconds');
            $table->string('ended_by')->nullable()->after('end_reason');
            $table->string('disconnect_source')->nullable()->after('ended_by');
            $table->string('disconnect_reason_code')->nullable()->after('disconnect_source');
            $table->string('disconnect_reason_label')->nullable()->after('disconnect_reason_code');
            $table->timestamp('last_status_at')->nullable()->after('disconnect_reason_label');
            $table->json('timeline_snapshot')->nullable()->after('meta_payload');

            $table->index('status', 'wa_call_sessions_status_idx');
            $table->index('final_status', 'wa_call_sessions_final_status_idx');
            $table->index('ended_at', 'wa_call_sessions_ended_at_idx');
            $table->index('created_at', 'wa_call_sessions_created_at_idx');
            $table->index(['conversation_id', 'created_at'], 'wa_call_sessions_conversation_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_call_sessions', function (Blueprint $table): void {
            $table->dropIndex('wa_call_sessions_status_idx');
            $table->dropIndex('wa_call_sessions_final_status_idx');
            $table->dropIndex('wa_call_sessions_ended_at_idx');
            $table->dropIndex('wa_call_sessions_created_at_idx');
            $table->dropIndex('wa_call_sessions_conversation_created_idx');

            $table->dropColumn([
                'connected_at',
                'duration_seconds',
                'final_status',
                'ended_by',
                'disconnect_source',
                'disconnect_reason_code',
                'disconnect_reason_label',
                'last_status_at',
                'timeline_snapshot',
            ]);
        });
    }
};
