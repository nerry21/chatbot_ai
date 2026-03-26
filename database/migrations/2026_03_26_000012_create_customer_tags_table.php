<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('tag')->index();

            $table->timestamps();

            // A customer cannot have the same tag twice.
            $table->unique(['customer_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tags');
    }
};
