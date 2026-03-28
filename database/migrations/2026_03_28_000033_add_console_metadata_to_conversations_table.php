<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->boolean('is_urgent')->default(false)->after('last_admin_intervention_at');
            $table->timestamp('urgent_marked_at')->nullable()->after('is_urgent');
            $table->unsignedBigInteger('urgent_marked_by')->nullable()->index()->after('urgent_marked_at');
            $table->timestamp('closed_at')->nullable()->after('urgent_marked_by');
            $table->unsignedBigInteger('closed_by')->nullable()->index()->after('closed_at');
            $table->text('close_reason')->nullable()->after('closed_by');
            $table->timestamp('reopened_at')->nullable()->after('close_reason');
            $table->unsignedBigInteger('reopened_by')->nullable()->index()->after('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn([
                'is_urgent',
                'urgent_marked_at',
                'urgent_marked_by',
                'closed_at',
                'closed_by',
                'close_reason',
                'reopened_at',
                'reopened_by',
            ]);
        });
    }
};
