<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'bot_auto_resume_enabled')) {
                $table->boolean('bot_auto_resume_enabled')->default(false)->after('reopened_by');
            }

            if (! Schema::hasColumn('conversations', 'bot_auto_resume_at')) {
                $table->timestamp('bot_auto_resume_at')->nullable()->after('bot_auto_resume_enabled');
            }

            if (! Schema::hasColumn('conversations', 'bot_last_admin_reply_at')) {
                $table->timestamp('bot_last_admin_reply_at')->nullable()->after('bot_auto_resume_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'bot_last_admin_reply_at')) {
                $table->dropColumn('bot_last_admin_reply_at');
            }
            if (Schema::hasColumn('conversations', 'bot_auto_resume_at')) {
                $table->dropColumn('bot_auto_resume_at');
            }
            if (Schema::hasColumn('conversations', 'bot_auto_resume_enabled')) {
                $table->dropColumn('bot_auto_resume_enabled');
            }
        });
    }
};
