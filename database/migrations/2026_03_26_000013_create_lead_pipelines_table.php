<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_pipelines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('booking_request_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
             * Stages (in lifecycle order):
             * new_lead → engaged → awaiting_confirmation → confirmed
             *         → paid → completed
             *         → cancelled
             *         → complaint
             */
            $table->string('stage')->default('new_lead')->index();

            // No FK — admin model not final.
            $table->unsignedBigInteger('owner_admin_id')->nullable();

            $table->longText('notes')->nullable();

            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_pipelines');
    }
};
