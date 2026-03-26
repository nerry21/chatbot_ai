<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();

            // Categorical type for filtering: escalation, booking, system, etc.
            $table->string('type')->index();

            $table->string('title');
            $table->longText('body');

            // Arbitrary structured data for the admin UI to act on.
            $table->json('payload')->nullable();

            $table->boolean('is_read')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
