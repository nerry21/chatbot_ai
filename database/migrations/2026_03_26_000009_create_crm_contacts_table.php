<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider')->default('hubspot');

            // ID from the external CRM (e.g. HubSpot contact ID).
            $table->string('external_contact_id')->nullable()->index();

            $table->timestamp('last_synced_at')->nullable();

            // pending | synced | failed | local_only
            $table->string('sync_status')->default('pending')->index();

            // Last payload sent to / received from the CRM.
            $table->json('sync_payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
