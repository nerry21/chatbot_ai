<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->foreignId('customer_id')
                  ->constrained()
                  ->restrictOnDelete();

            $table->string('pickup_location')->nullable();
            $table->string('destination')->nullable();
            $table->date('departure_date')->nullable();
            $table->string('departure_time', 5)->nullable()   // HH:MM
                  ->comment('24-hour time string, e.g. 08:00');
            $table->unsignedTinyInteger('passenger_count')->nullable();
            $table->string('passenger_name')->nullable();
            $table->longText('special_notes')->nullable();
            $table->decimal('price_estimate', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('booking_status')->default('draft')->index();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('conversation_id');
            $table->index(['conversation_id', 'booking_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
    }
};
