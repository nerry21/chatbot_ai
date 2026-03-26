<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('alias_name');
            $table->string('source')->nullable()->comment('Origin of this alias: whatsapp_profile, crm, manual, etc.');
            $table->timestamps();

            // A customer should not have the same alias recorded twice
            $table->unique(['customer_id', 'alias_name']);

            // Allow fast lookups by alias name across all customers
            $table->index('alias_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_aliases');
    }
};
