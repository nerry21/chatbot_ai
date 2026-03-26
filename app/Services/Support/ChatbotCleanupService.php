<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChatbotCleanupService
{
    /**
     * Run operational cleanup tasks.
     *
     * When $dryRun is true, queries are executed as SELECT COUNT(*) only —
     * no rows are deleted. Safe to run repeatedly.
     *
     * @return array{
     *   dry_run: bool,
     *   actions: list<array{key: string, affected: int, note: string|null}>
     * }
     */
    public function run(bool $dryRun = true): array
    {
        $actions = [];

        $actions[] = $this->cleanOldReadNotifications($dryRun);
        $actions[] = $this->cleanOldAuditLogs($dryRun);
        $actions[] = $this->cleanOldAiLogs($dryRun);
        $actions[] = $this->cleanOldClosedEscalations($dryRun);

        if (config('chatbot.reliability.cleanup.prune_expired_conversation_states', true)) {
            $actions[] = $this->pruneExpiredConversationStates($dryRun);
        }

        return [
            'dry_run' => $dryRun,
            'actions' => array_values(array_filter($actions)),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual cleanup tasks
    // -------------------------------------------------------------------------

    /**
     * Delete admin_notifications that are read and older than X days.
     * Unread notifications are never deleted automatically.
     */
    private function cleanOldReadNotifications(bool $dryRun): array
    {
        $days      = config('chatbot.reliability.cleanup.delete_old_read_notifications_days', 30);
        $cutoff    = now()->subDays($days);
        $table     = 'admin_notifications';

        try {
            if (! Schema::hasTable($table)) {
                return $this->skipped('delete_old_read_notifications', "Tabel {$table} tidak ada.");
            }

            $query = DB::table($table)
                ->where('is_read', true)
                ->where('created_at', '<', $cutoff);

            $affected = $dryRun ? $query->count() : $query->delete();

            return $this->result('delete_old_read_notifications', $affected, "Notifikasi dibaca > {$days} hari.");

        } catch (\Throwable $e) {
            Log::error('[ChatbotCleanupService] cleanOldReadNotifications failed', ['error' => $e->getMessage()]);
            return $this->skipped('delete_old_read_notifications', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete audit_logs older than X days.
     * Audit logs are append-only records so deleting old ones is safe after
     * the retention window.
     */
    private function cleanOldAuditLogs(bool $dryRun): array
    {
        $days   = config('chatbot.reliability.cleanup.delete_old_audit_logs_days', 90);
        $cutoff = now()->subDays($days);
        $table  = 'audit_logs';

        try {
            if (! Schema::hasTable($table)) {
                return $this->skipped('delete_old_audit_logs', "Tabel {$table} tidak ada.");
            }

            $query = DB::table($table)->where('created_at', '<', $cutoff);

            $affected = $dryRun ? $query->count() : $query->delete();

            return $this->result('delete_old_audit_logs', $affected, "Audit log > {$days} hari.");

        } catch (\Throwable $e) {
            Log::error('[ChatbotCleanupService] cleanOldAuditLogs failed', ['error' => $e->getMessage()]);
            return $this->skipped('delete_old_audit_logs', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete ai_logs older than X days.
     * AI logs are verbose and grow quickly; regular cleanup keeps the table lean.
     */
    private function cleanOldAiLogs(bool $dryRun): array
    {
        $days   = config('chatbot.reliability.cleanup.delete_old_ai_logs_days', 60);
        $cutoff = now()->subDays($days);
        $table  = 'ai_logs';

        try {
            if (! Schema::hasTable($table)) {
                return $this->skipped('delete_old_ai_logs', "Tabel {$table} tidak ada.");
            }

            $query = DB::table($table)->where('created_at', '<', $cutoff);

            $affected = $dryRun ? $query->count() : $query->delete();

            return $this->result('delete_old_ai_logs', $affected, "AI log > {$days} hari.");

        } catch (\Throwable $e) {
            Log::error('[ChatbotCleanupService] cleanOldAiLogs failed', ['error' => $e->getMessage()]);
            return $this->skipped('delete_old_ai_logs', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete escalations with status resolved or closed that are older than X days.
     *
     * Safety: only touches escalations in terminal states (resolved/closed).
     * Does NOT touch conversations, messages, customers, or bookings.
     */
    private function cleanOldClosedEscalations(bool $dryRun): array
    {
        $days   = config('chatbot.reliability.cleanup.delete_old_closed_escalations_days', 90);
        $cutoff = now()->subDays($days);
        $table  = 'escalations';

        try {
            if (! Schema::hasTable($table)) {
                return $this->skipped('delete_old_closed_escalations', "Tabel {$table} tidak ada.");
            }

            $query = DB::table($table)
                ->whereIn('status', ['resolved', 'closed'])
                ->where('created_at', '<', $cutoff);

            $affected = $dryRun ? $query->count() : $query->delete();

            return $this->result('delete_old_closed_escalations', $affected, "Eskalasi resolved/closed > {$days} hari.");

        } catch (\Throwable $e) {
            Log::error('[ChatbotCleanupService] cleanOldClosedEscalations failed', ['error' => $e->getMessage()]);
            return $this->skipped('delete_old_closed_escalations', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete conversation_states rows whose expires_at is in the past.
     * These are ephemeral key-value state entries that have naturally expired.
     */
    private function pruneExpiredConversationStates(bool $dryRun): array
    {
        $table = 'conversation_states';

        try {
            if (! Schema::hasTable($table)) {
                return $this->skipped('prune_expired_conversation_states', "Tabel {$table} tidak ada.");
            }

            $query = DB::table($table)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now());

            $affected = $dryRun ? $query->count() : $query->delete();

            return $this->result('prune_expired_conversation_states', $affected, 'State percakapan kadaluarsa.');

        } catch (\Throwable $e) {
            Log::error('[ChatbotCleanupService] pruneExpiredConversationStates failed', ['error' => $e->getMessage()]);
            return $this->skipped('prune_expired_conversation_states', 'Error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Output helpers
    // -------------------------------------------------------------------------

    /** @return array{key: string, affected: int, note: string|null} */
    private function result(string $key, int $affected, ?string $note = null): array
    {
        return ['key' => $key, 'affected' => $affected, 'note' => $note];
    }

    /** @return array{key: string, affected: int, note: string|null} */
    private function skipped(string $key, string $reason): array
    {
        return ['key' => $key, 'affected' => 0, 'note' => 'Dilewati: ' . $reason];
    }
}
