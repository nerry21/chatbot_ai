<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->json('selected_seats')->nullable()->after('passenger_count');
            $table->json('passenger_names')->nullable()->after('passenger_name');
            $table->text('pickup_full_address')->nullable()->after('pickup_location');
            $table->string('contact_number')->nullable()->after('payment_method');
            $table->boolean('contact_same_as_sender')->nullable()->after('contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'selected_seats',
                'passenger_names',
                'pickup_full_address',
                'contact_number',
                'contact_same_as_sender',
            ]);
        });
    }
};
