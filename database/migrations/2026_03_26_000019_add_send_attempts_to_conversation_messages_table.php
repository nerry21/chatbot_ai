<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            // Number of send attempts made for this outbound message.
            // Incremented at the start of each SendWhatsAppMessageJob execution.
            // Stays at 0 for inbound messages (never sent outbound).
            $table->unsignedInteger('send_attempts')->default(0)->after('failed_at');

            // Timestamp of the most recent send attempt.
            // Null if no attempt has been made yet.
            $table->timestamp('last_send_attempt_at')->nullable()->after('send_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropColumn(['send_attempts', 'last_send_attempt_at']);
        });
    }
};
