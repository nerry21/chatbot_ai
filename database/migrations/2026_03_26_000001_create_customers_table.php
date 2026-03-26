<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone_e164')->unique();
            $table->string('email')->nullable();
            $table->string('preferred_pickup')->nullable();
            $table->string('preferred_destination')->nullable();
            $table->timestamp('preferred_departure_time')->nullable();
            $table->unsignedInteger('total_bookings')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->timestamp('last_interaction_at')->nullable();
            $table->string('crm_contact_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
