<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('key', 64);
            $table->text('value')->nullable();
            $table->enum('value_type', ['string', 'int', 'bool', 'json'])->default('string');
            $table->decimal('confidence', 4, 3)->default(0.500);
            $table->enum('source', ['explicit', 'inferred', 'imported', 'manual'])->default('inferred');
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'key']);
            $table->index('key');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
    }
};
