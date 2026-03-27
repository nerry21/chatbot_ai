<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->string('trip_key')->nullable()->after('destination');
            $table->index('trip_key');
        });

        Schema::table('booking_seat_reservations', function (Blueprint $table): void {
            $table->string('trip_key')->nullable()->after('departure_time');
            $table->index(['departure_date', 'departure_time', 'trip_key'], 'booking_seat_trip_idx');
        });

        DB::table('booking_seat_reservations')
            ->whereNull('trip_key')
            ->update(['trip_key' => 'global']);
    }

    public function down(): void
    {
        Schema::table('booking_seat_reservations', function (Blueprint $table): void {
            $table->dropIndex('booking_seat_trip_idx');
            $table->dropColumn('trip_key');
        });

        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->dropIndex(['trip_key']);
            $table->dropColumn('trip_key');
        });
    }
};
