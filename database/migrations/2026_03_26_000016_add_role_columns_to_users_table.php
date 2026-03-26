<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Boolean flags for chatbot admin area access.
            // is_chatbot_admin    → full access: takeover, release, reply, escalation management
            // is_chatbot_operator → read + reply access (if config allow_operator_actions = true)
            $table->boolean('is_chatbot_admin')    ->default(false)->after('remember_token');
            $table->boolean('is_chatbot_operator') ->default(false)->after('is_chatbot_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_chatbot_admin', 'is_chatbot_operator']);
        });
    }
};
