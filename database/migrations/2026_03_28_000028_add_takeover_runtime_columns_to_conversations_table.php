<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('human_takeover_at')
                ->nullable()
                ->after('handoff_at');

            $table->unsignedBigInteger('human_takeover_by')
                ->nullable()
                ->after('human_takeover_at');

            $table->boolean('bot_paused')
                ->default(false)
                ->after('human_takeover_by')
                ->index();

            $table->string('bot_paused_reason')
                ->nullable()
                ->after('bot_paused');

            $table->unsignedBigInteger('assigned_admin_id')
                ->nullable()
                ->after('bot_paused_reason')
                ->index();

            $table->timestamp('released_to_bot_at')
                ->nullable()
                ->after('assigned_admin_id');

            $table->timestamp('last_admin_intervention_at')
                ->nullable()
                ->after('released_to_bot_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['bot_paused']);
            $table->dropIndex(['assigned_admin_id']);
            $table->dropColumn([
                'human_takeover_at',
                'human_takeover_by',
                'bot_paused',
                'bot_paused_reason',
                'assigned_admin_id',
                'released_to_bot_at',
                'last_admin_intervention_at',
            ]);
        });
    }
};
