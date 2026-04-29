<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('first_booking_at')->nullable()->after('total_spent');
        });

        DB::statement("
            UPDATE customers
            SET first_booking_at = (
                SELECT MIN(br.created_at)
                FROM booking_requests br
                WHERE br.customer_id = customers.id
                  AND br.booking_status = 'confirmed'
            )
            WHERE first_booking_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('first_booking_at');
        });
    }
};
