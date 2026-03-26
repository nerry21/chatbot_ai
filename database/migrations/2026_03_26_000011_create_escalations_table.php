<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('reason')->nullable();

            // normal | high | urgent
            $table->string('priority')->default('normal')->index();

            // open | assigned | resolved | closed
            $table->string('status')->default('open')->index();

            // No FK yet — admin model is not final.
            $table->unsignedBigInteger('assigned_admin_id')->nullable();

            // AI-generated or agent-written summary of the conversation.
            $table->longText('summary')->nullable();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
