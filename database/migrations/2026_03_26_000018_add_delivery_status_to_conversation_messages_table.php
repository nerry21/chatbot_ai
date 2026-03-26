<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            // delivery_status tracks the outbound send lifecycle.
            // For inbound messages this column is irrelevant (will remain 'pending').
            // Values: pending | sent | failed | skipped | delivered
            $table->string('delivery_status')->default('pending')->after('sent_at')->index();

            // Human-readable error if delivery_status = 'failed'
            $table->longText('delivery_error')->nullable()->after('delivery_status');

            // Timestamp when the message was confirmed delivered by the provider.
            // For Tahap 8: set equal to sent_at (no provider webhook yet).
            $table->timestamp('delivered_at')->nullable()->after('delivery_error');

            // Timestamp when the send permanently failed.
            $table->timestamp('failed_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex(['delivery_status']);
            $table->dropColumn(['delivery_status', 'delivery_error', 'delivered_at', 'failed_at']);
        });
    }
};
