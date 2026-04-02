<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_call_sessions', function (Blueprint $table): void {
            $table->timestamp('last_permission_requested_at')->nullable()->after('permission_status');
            $table->timestamp('rate_limited_until')->nullable()->after('last_permission_requested_at');

            $table->index('last_permission_requested_at', 'wa_call_sessions_last_permission_requested_idx');
            $table->index('rate_limited_until', 'wa_call_sessions_rate_limited_until_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_call_sessions', function (Blueprint $table): void {
            $table->dropIndex('wa_call_sessions_last_permission_requested_idx');
            $table->dropIndex('wa_call_sessions_rate_limited_until_idx');
            $table->dropColumn([
                'last_permission_requested_at',
                'rate_limited_until',
            ]);
        });
    }
};
