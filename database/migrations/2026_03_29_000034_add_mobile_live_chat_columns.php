<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('mobile_user_id')->nullable()->after('email');
            $table->string('mobile_device_id')->nullable()->after('mobile_user_id');
            $table->string('preferred_channel')->nullable()->after('mobile_device_id');
            $table->string('avatar_url')->nullable()->after('preferred_channel');

            $table->unique('mobile_user_id', 'customers_mobile_user_id_unique');
            $table->index('mobile_device_id', 'customers_mobile_device_id_index');
            $table->index('preferred_channel', 'customers_preferred_channel_index');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('source_app')->nullable()->after('channel_conversation_id');
            $table->timestamp('last_read_at_customer')->nullable()->after('last_message_at');
            $table->timestamp('last_read_at_admin')->nullable()->after('last_read_at_customer');
            $table->boolean('is_from_mobile_app')->default(false)->after('needs_human');

            $table->index('source_app', 'conversations_source_app_index');
            $table->index('is_from_mobile_app', 'conversations_is_from_mobile_app_index');
            $table->index(
                ['customer_id', 'channel', 'status', 'last_message_at'],
                'conversations_customer_channel_status_last_message_index',
            );
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->string('client_message_id')->nullable()->after('raw_payload');
            $table->timestamp('read_at')->nullable()->after('sent_at');
            $table->timestamp('delivered_to_app_at')->nullable()->after('delivered_at');
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete()->after('sender_type');
            $table->string('channel_message_id')->nullable()->after('client_message_id');

            $table->index('client_message_id', 'conversation_messages_client_message_id_index');
            $table->index('channel_message_id', 'conversation_messages_channel_message_id_index');
            $table->index(
                ['conversation_id', 'client_message_id'],
                'conversation_messages_conversation_client_message_index',
            );
            $table->index(
                ['conversation_id', 'direction', 'sender_type', 'read_at', 'id'],
                'conversation_messages_mobile_read_index',
            );
            $table->index(
                ['conversation_id', 'delivered_to_app_at', 'id'],
                'conversation_messages_mobile_delivery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropIndex('conversation_messages_mobile_delivery_index');
            $table->dropIndex('conversation_messages_mobile_read_index');
            $table->dropIndex('conversation_messages_conversation_client_message_index');
            $table->dropIndex('conversation_messages_channel_message_id_index');
            $table->dropIndex('conversation_messages_client_message_id_index');
            $table->dropConstrainedForeignId('sender_user_id');
            $table->dropColumn([
                'client_message_id',
                'read_at',
                'delivered_to_app_at',
                'channel_message_id',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_customer_channel_status_last_message_index');
            $table->dropIndex('conversations_is_from_mobile_app_index');
            $table->dropIndex('conversations_source_app_index');
            $table->dropColumn([
                'source_app',
                'last_read_at_customer',
                'last_read_at_admin',
                'is_from_mobile_app',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_preferred_channel_index');
            $table->dropIndex('customers_mobile_device_id_index');
            $table->dropUnique('customers_mobile_user_id_unique');
            $table->dropColumn([
                'mobile_user_id',
                'mobile_device_id',
                'preferred_channel',
                'avatar_url',
            ]);
        });
    }
};
