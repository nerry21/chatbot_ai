<?php

namespace App\Services\Support;

use App\Models\AdminNotification;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Escalation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ChatbotHealthService
{
    /**
     * Run all health checks and return a structured result array.
     *
     * @return array{
     *   status: string,
     *   checks: list<array{key: string, status: string, message: string, value: mixed}>,
     *   summary: array<string, mixed>
     * }
     */
    public function run(): array
    {
        $checks = [
            $this->checkWhatsAppEnabled(),
            $this->checkLlmEnabled(),
            $this->checkFailedMessages24h(),
            $this->checkStalePendingMessages(),
            $this->checkOpenEscalations(),
            $this->checkUnreadNotifications(),
            $this->checkQueueBacklog(),
            $this->checkActiveTakeovers(),
        ];

        $overallStatus = $this->computeOverallStatus($checks);

        // Collect key values for the summary map
        $valMap = array_column($checks, 'value', 'key');

        $summary = [
            'failed_messages_24h'    => $valMap['failed_messages_24h']    ?? 0,
            'pending_messages_stale' => $valMap['pending_messages_stale'] ?? 0,
            'open_escalations'       => $valMap['open_escalations']       ?? 0,
            'unread_notifications'   => $valMap['unread_notifications']   ?? 0,
            'active_takeovers'       => $valMap['active_takeovers']       ?? 0,
            'queue_backlog'          => $valMap['queue_backlog']           ?? null,
        ];

        return [
            'status'  => $overallStatus,
            'checks'  => $checks,
            'summary' => $summary,
        ];
    }

    // -------------------------------------------------------------------------
    // Individual checks
    // -------------------------------------------------------------------------

    private function checkWhatsAppEnabled(): array
    {
        $enabled = config('chatbot.whatsapp.enabled', false)
            && ! empty(config('chatbot.whatsapp.phone_number_id'))
            && ! empty(config('chatbot.whatsapp.access_token'));

        return [
            'key'     => 'whatsapp_enabled',
            'status'  => $enabled ? 'ok' : 'warning',
            'message' => $enabled
                ? 'WhatsApp sender aktif dan terkonfigurasi.'
                : 'WhatsApp sender tidak aktif atau kredensial belum diisi.',
            'value'   => $enabled,
        ];
    }

    private function checkLlmEnabled(): array
    {
        $enabled = config('chatbot.llm.enabled', true);

        return [
            'key'     => 'llm_enabled',
            'status'  => $enabled ? 'ok' : 'warning',
            'message' => $enabled
                ? 'LLM (AI) aktif.'
                : 'LLM (AI) dinonaktifkan — semua respons AI akan menggunakan fallback.',
            'value'   => $enabled,
        ];
    }

    private function checkFailedMessages24h(): array
    {
        $threshold = config('chatbot.reliability.health.failed_message_threshold', 10);

        try {
            $count = ConversationMessage::where('direction', 'outbound')
                ->where('delivery_status', 'failed')
                ->where('created_at', '>=', now()->subHours(24))
                ->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count failed messages', ['error' => $e->getMessage()]);
            $count = 0;
        }

        $status = match (true) {
            $count === 0           => 'ok',
            $count < $threshold    => 'warning',
            default                => 'critical',
        };

        return [
            'key'     => 'failed_messages_24h',
            'status'  => $status,
            'message' => "Pesan outbound gagal dalam 24 jam terakhir: {$count} (threshold: {$threshold}).",
            'value'   => $count,
        ];
    }

    /**
     * Detect outbound messages stuck in 'pending' for more than 10 minutes.
     * These are messages whose SendWhatsAppMessageJob may have silently failed.
     */
    private function checkStalePendingMessages(): array
    {
        try {
            $count = ConversationMessage::where('direction', 'outbound')
                ->where('delivery_status', 'pending')
                ->where('created_at', '<', now()->subMinutes(10))
                ->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count stale pending messages', ['error' => $e->getMessage()]);
            $count = 0;
        }

        $status = match (true) {
            $count === 0  => 'ok',
            $count < 5    => 'warning',
            default       => 'critical',
        };

        return [
            'key'     => 'pending_messages_stale',
            'status'  => $status,
            'message' => "Pesan outbound pending > 10 menit: {$count}.",
            'value'   => $count,
        ];
    }

    private function checkOpenEscalations(): array
    {
        $threshold = config('chatbot.reliability.health.open_escalation_threshold', 20);

        try {
            $count = Escalation::where('status', 'open')->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count open escalations', ['error' => $e->getMessage()]);
            $count = 0;
        }

        $status = match (true) {
            $count === 0         => 'ok',
            $count < $threshold  => 'warning',
            default              => 'critical',
        };

        return [
            'key'     => 'open_escalations',
            'status'  => $status,
            'message' => "Eskalasi open: {$count} (threshold: {$threshold}).",
            'value'   => $count,
        ];
    }

    private function checkUnreadNotifications(): array
    {
        try {
            $count = AdminNotification::where('is_read', false)->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count unread notifications', ['error' => $e->getMessage()]);
            $count = 0;
        }

        $status = match (true) {
            $count === 0   => 'ok',
            $count < 50    => 'warning',
            default        => 'critical',
        };

        return [
            'key'     => 'unread_notifications',
            'status'  => $status,
            'message' => "Notifikasi belum dibaca: {$count}.",
            'value'   => $count,
        ];
    }

    /**
     * Estimate queue backlog from the jobs table.
     * Gracefully returns null/ok if the jobs table does not exist.
     */
    private function checkQueueBacklog(): array
    {
        $threshold = config('chatbot.reliability.health.queue_backlog_threshold', 50);

        try {
            if (! Schema::hasTable('jobs')) {
                return [
                    'key'     => 'queue_backlog',
                    'status'  => 'ok',
                    'message' => 'Tabel jobs tidak ditemukan — queue backlog tidak dapat dicek.',
                    'value'   => null,
                ];
            }

            $count = DB::table('jobs')->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count queue backlog', ['error' => $e->getMessage()]);
            return [
                'key'     => 'queue_backlog',
                'status'  => 'ok',
                'message' => 'Queue backlog tidak dapat dicek (error saat query).',
                'value'   => null,
            ];
        }

        $failedCount = $this->countFailedJobs();

        $status = match (true) {
            $count < $threshold && $failedCount === 0 => 'ok',
            $count >= $threshold || $failedCount > 0  => 'warning',
            default                                   => 'ok',
        };

        $message = "Queue pending: {$count} (threshold: {$threshold})";
        if ($failedCount > 0) {
            $message .= ", failed_jobs: {$failedCount}";
            $status   = $failedCount >= 5 ? 'critical' : 'warning';
        }
        $message .= '.';

        return [
            'key'     => 'queue_backlog',
            'status'  => $status,
            'message' => $message,
            'value'   => ['pending' => $count, 'failed' => $failedCount],
        ];
    }

    private function countFailedJobs(): int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return 0;
            }
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Count conversations currently under admin takeover.
     */
    private function checkActiveTakeovers(): array
    {
        try {
            $count = Conversation::where('handoff_mode', 'admin')->count();
        } catch (\Throwable $e) {
            Log::warning('[ChatbotHealthService] Could not count active takeovers', ['error' => $e->getMessage()]);
            $count = 0;
        }

        return [
            'key'     => 'active_takeovers',
            'status'  => 'ok', // Takeovers are intentional; just informational
            'message' => "Admin takeover aktif: {$count} percakapan.",
            'value'   => $count,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function computeOverallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('critical', $statuses, true)) {
            return 'critical';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        return 'ok';
    }
}
