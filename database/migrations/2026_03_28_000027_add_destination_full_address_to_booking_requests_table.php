<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('booking_requests', 'destination_full_address')) {
                $table->text('destination_full_address')->nullable()->after('destination');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('booking_requests', 'destination_full_address')) {
                $table->dropColumn('destination_full_address');
            }
        });
    }
};
