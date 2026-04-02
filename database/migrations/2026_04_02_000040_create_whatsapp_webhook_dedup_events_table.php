<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_dedup_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 50);
            $table->string('dedup_key', 191)->unique();
            $table->string('wa_call_id')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'received_at'], 'wa_webhook_dedup_event_type_received_idx');
            $table->index(['wa_call_id', 'received_at'], 'wa_webhook_dedup_call_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_dedup_events');
    }
};
