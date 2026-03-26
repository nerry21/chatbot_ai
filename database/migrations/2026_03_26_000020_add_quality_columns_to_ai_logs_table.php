<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 10 — Knowledge Tuning & AI Quality
 *
 * Adds two optional quality-tracking columns to ai_logs:
 *
 *  quality_label  — a short categorical label set by the pipeline after each
 *                   processed message.  Values:
 *                     'low_confidence'  intent confidence below threshold
 *                     'fallback'        bot used a hardcoded fallback reply
 *                     'knowledge_used'  knowledge base enriched the reply
 *                     'faq_direct'      FaqResolverService answered directly
 *
 *  knowledge_hits — compact JSON array of knowledge articles that were
 *                   retrieved during this task.  Each element:
 *                     [{id: 1, title: "Cara Pembayaran", score: 7.5}]
 *
 * Both columns are nullable — existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table): void {
            // Placed after the 'status' column so the table reads logically.
            $table->string('quality_label', 50)->nullable()->after('status');
            $table->json('knowledge_hits')->nullable()->after('quality_label');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table): void {
            $table->dropColumn(['quality_label', 'knowledge_hits']);
        });
    }
};
