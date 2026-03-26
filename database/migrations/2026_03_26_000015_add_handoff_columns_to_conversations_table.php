<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            // Explicit handoff mode column — the single source of truth for whether
            // the bot pipeline is suppressed for this conversation.
            // Values: 'bot' (default, AI active) | 'admin' (human has taken over, AI suppressed)
            $table->string('handoff_mode')
                ->default('bot')
                ->after('needs_human')
                ->index();

            // Which User (admin) currently holds the takeover.
            // Nullable — no FK because the admin/User model may evolve later.
            $table->unsignedBigInteger('handoff_admin_id')
                ->nullable()
                ->after('handoff_mode');

            // Timestamp when the latest takeover/release happened.
            $table->timestamp('handoff_at')
                ->nullable()
                ->after('handoff_admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['handoff_mode']);
            $table->dropColumn(['handoff_mode', 'handoff_admin_id', 'handoff_at']);
        });
    }
};
