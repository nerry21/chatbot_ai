<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_conversation_states', function (Blueprint $table): void {
            $table->id();

            $table->string('channel', 50)->default('whatsapp');
            $table->string('customer_phone', 30);
            $table->string('customer_name', 150)->nullable();

            $table->string('status', 50)->default('idle');
            $table->string('current_step', 100)->nullable();

            $table->json('booking_data')->nullable();
            $table->json('schedule_change_data')->nullable();
            $table->json('meta')->nullable();

            $table->string('last_intent', 100)->nullable();
            $table->string('last_admin_notification_key', 191)->nullable();

            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_bot_message_at')->nullable();
            $table->timestamp('first_follow_up_sent_at')->nullable();
            $table->timestamp('second_follow_up_sent_at')->nullable();

            $table->timestamp('last_completed_booking_at')->nullable();
            $table->timestamp('departure_datetime')->nullable();

            $table->boolean('is_waiting_customer_reply')->default(false);
            $table->boolean('is_cancelled')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['channel', 'customer_phone'], 'chatbot_conv_states_channel_phone_unique');
            $table->index(['status', 'is_active'], 'chatbot_conv_states_status_active_idx');
            $table->index(['customer_phone', 'is_active'], 'chatbot_conv_states_phone_active_idx');
            $table->index(['is_active', 'is_waiting_customer_reply', 'first_follow_up_sent_at'], 'chatbot_conv_states_followup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversation_states');
    }
};
