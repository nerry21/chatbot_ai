<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_seat_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_request_id')->constrained()->cascadeOnDelete();
            $table->date('departure_date');
            $table->string('departure_time', 5);
            $table->string('seat_code', 50);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['departure_date', 'departure_time', 'seat_code'], 'booking_seat_unique');
            $table->index(['booking_request_id', 'departure_date', 'departure_time'], 'booking_seat_booking_idx');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_seat_reservations');
    }
};
