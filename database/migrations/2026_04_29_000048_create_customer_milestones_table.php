<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('milestone_key', 128);
            $table->string('milestone_category', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('achieved_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'milestone_key'], 'cm_customer_key_unique');
            $table->index(['customer_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_milestones');
    }
};
