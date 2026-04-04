<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_update_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_update_id')->constrained('status_updates')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            $table->unique(['status_update_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_update_views');
    }
};
