<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'handoff_mode')) {
                $table->string('handoff_mode')->default('bot');
            }

            if (! Schema::hasColumn('conversations', 'handoff_at')) {
                $table->timestamp('handoff_at')->nullable();
            }

            if (! Schema::hasColumn('conversations', 'human_takeover_at')) {
                $table->timestamp('human_takeover_at')->nullable();
            }

            if (! Schema::hasColumn('conversations', 'bot_paused')) {
                $table->boolean('bot_paused')->default(false);
            }

            if (! Schema::hasColumn('conversations', 'released_to_bot_at')) {
                $table->timestamp('released_to_bot_at')->nullable();
            }

            if (! Schema::hasColumn('conversations', 'last_admin_intervention_at')) {
                $table->timestamp('last_admin_intervention_at')->nullable();
            }
        });

        if (
            Schema::hasColumn('conversations', 'last_admin_intervention_at')
            && Schema::hasColumn('conversations', 'human_takeover_at')
            && Schema::hasColumn('conversations', 'handoff_at')
        ) {
            DB::table('conversations')
                ->whereNull('last_admin_intervention_at')
                ->where(function ($query): void {
                    $query->whereNotNull('human_takeover_at')
                        ->orWhereNotNull('handoff_at');
                })
                ->update([
                    'last_admin_intervention_at' => DB::raw('COALESCE(human_takeover_at, handoff_at)'),
                ]);
        }
    }

    public function down(): void
    {
        // Compatibility migration for the current takeover schema.
        // Intentionally left as a no-op to avoid dropping columns managed by earlier migrations.
    }
};
