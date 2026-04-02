<?php

namespace App\Support\Transformers;

use App\Models\WhatsAppCallSession;
use Illuminate\Support\Arr;

class CallAnalyticsTransformer
{
    /**
     * @return array<string, mixed>
     */
    public function transformSession(WhatsAppCallSession $session): array
    {
        $customer = $session->relationLoaded('customer') ? $session->customer : null;
        $conversation = $session->relationLoaded('conversation') ? $session->conversation : null;
        $initiatedBy = $session->relationLoaded('initiatedBy') ? $session->initiatedBy : null;

        return array_merge($session->toApiArray(), [
            'customer_label' => trim((string) ($customer?->name ?? $conversation?->customer?->name ?? 'Customer')),
            'customer_contact' => trim((string) ($customer?->display_contact ?? $customer?->phone_e164 ?? $conversation?->customer?->display_contact ?? '-')),
            'conversation_label' => trim((string) ($customer?->name ?? $conversation?->summary ?? 'Conversation')),
            'initiated_by' => $initiatedBy !== null
                ? [
                    'id' => (int) $initiatedBy->id,
                    'name' => (string) $initiatedBy->name,
                    'email' => (string) $initiatedBy->email,
                ]
                : null,
            'status_label' => $this->statusLabel((string) ($session->status ?? '')),
            'final_status_label' => $session->finalStatusLabel(),
            'outcome_label' => $session->finalStatusLabel(),
            'supports_live_audio' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function transformSummary(array $summary): array
    {
        return [
            'total_calls' => (int) ($summary['total_calls'] ?? 0),
            'completed_calls' => (int) ($summary['completed_calls'] ?? 0),
            'missed_calls' => (int) ($summary['missed_calls'] ?? 0),
            'rejected_calls' => (int) ($summary['rejected_calls'] ?? 0),
            'failed_calls' => (int) ($summary['failed_calls'] ?? 0),
            'cancelled_calls' => (int) ($summary['cancelled_calls'] ?? 0),
            'permission_pending_calls' => (int) ($summary['permission_pending_calls'] ?? 0),
            'in_progress_calls' => (int) ($summary['in_progress_calls'] ?? 0),
            'total_duration_seconds' => (int) ($summary['total_duration_seconds'] ?? 0),
            'total_duration_human' => WhatsAppCallSession::formatDurationHuman(
                (int) ($summary['total_duration_seconds'] ?? 0),
            ),
            'average_duration_seconds' => (int) ($summary['average_duration_seconds'] ?? 0),
            'average_duration_human' => WhatsAppCallSession::formatDurationHuman(
                (int) ($summary['average_duration_seconds'] ?? 0),
            ),
            'completion_rate' => round((float) ($summary['completion_rate'] ?? 0), 2),
            'missed_rate' => round((float) ($summary['missed_rate'] ?? 0), 2),
            'connected_call_count' => (int) ($summary['connected_call_count'] ?? 0),
            'avg_answer_time_seconds' => $summary['avg_answer_time_seconds'] !== null
                ? (int) $summary['avg_answer_time_seconds']
                : null,
            'avg_ringing_time_seconds' => $summary['avg_ringing_time_seconds'] !== null
                ? (int) $summary['avg_ringing_time_seconds']
                : null,
            'media_connected_rate' => $summary['media_connected_rate'] !== null
                ? round((float) $summary['media_connected_rate'], 2)
                : null,
            'permission_acceptance_rate' => $summary['permission_acceptance_rate'] !== null
                ? round((float) $summary['permission_acceptance_rate'], 2)
                : null,
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $row
     * @return array<string, mixed>
     */
    public function transformOutcomeRow(array $row): array
    {
        $status = trim((string) ($row['final_status'] ?? ''));

        return [
            'final_status' => $status,
            'label' => $this->finalStatusLabel($status),
            'count' => (int) ($row['count'] ?? 0),
            'percentage' => round((float) ($row['percentage'] ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $row
     * @return array<string, mixed>
     */
    public function transformDailyTrendRow(array $row): array
    {
        return [
            'date' => (string) ($row['date'] ?? ''),
            'total_calls' => (int) ($row['total_calls'] ?? 0),
            'completed_calls' => (int) ($row['completed_calls'] ?? 0),
            'missed_calls' => (int) ($row['missed_calls'] ?? 0),
            'failed_calls' => (int) ($row['failed_calls'] ?? 0),
            'total_duration_seconds' => (int) ($row['total_duration_seconds'] ?? 0),
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $row
     * @return array<string, mixed>
     */
    public function transformAgentBreakdownRow(array $row): array
    {
        return [
            'admin_user_id' => $row['admin_user_id'] !== null ? (int) $row['admin_user_id'] : null,
            'admin_name' => Arr::get($row, 'admin_name'),
            'total_calls' => (int) ($row['total_calls'] ?? 0),
            'completed_calls' => (int) ($row['completed_calls'] ?? 0),
            'missed_calls' => (int) ($row['missed_calls'] ?? 0),
            'failed_calls' => (int) ($row['failed_calls'] ?? 0),
            'total_duration_seconds' => (int) ($row['total_duration_seconds'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function transformConversationHistorySummary(array $summary): array
    {
        $lastCallStatus = trim((string) ($summary['last_call_status'] ?? ''));

        return [
            'total_calls' => (int) ($summary['total_calls'] ?? 0),
            'last_call_status' => $lastCallStatus !== '' ? $lastCallStatus : null,
            'last_call_label' => $lastCallStatus !== '' ? $this->finalStatusLabel($lastCallStatus) : null,
            'last_call_at' => $summary['last_call_at'] ?? null,
            'last_call_duration_seconds' => $summary['last_call_duration_seconds'] !== null
                ? (int) $summary['last_call_duration_seconds']
                : null,
            'last_call_duration_human' => WhatsAppCallSession::formatDurationHuman(
                $summary['last_call_duration_seconds'] !== null
                    ? (int) $summary['last_call_duration_seconds']
                    : null,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return [
            'supports_live_audio' => false,
            'supports_call_recording' => false,
            'supports_call_transfer' => false,
            'supports_agent_pickup' => false,
            'supports_webrtc_signaling' => false,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            WhatsAppCallSession::STATUS_INITIATED => 'Memanggil',
            WhatsAppCallSession::STATUS_PERMISSION_REQUESTED => 'Meminta izin',
            WhatsAppCallSession::STATUS_RINGING => 'Berdering',
            WhatsAppCallSession::STATUS_CONNECTING => 'Menyambungkan',
            WhatsAppCallSession::STATUS_CONNECTED => 'Terhubung',
            WhatsAppCallSession::STATUS_REJECTED => 'Ditolak',
            WhatsAppCallSession::STATUS_MISSED => 'Tidak dijawab',
            WhatsAppCallSession::STATUS_ENDED => 'Berakhir',
            WhatsAppCallSession::STATUS_FAILED => 'Gagal',
            default => 'Status tidak diketahui',
        };
    }

    private function finalStatusLabel(string $status): string
    {
        return match ($status) {
            WhatsAppCallSession::FINAL_STATUS_COMPLETED => 'Berhasil',
            WhatsAppCallSession::FINAL_STATUS_MISSED => 'Tidak dijawab',
            WhatsAppCallSession::FINAL_STATUS_REJECTED => 'Ditolak',
            WhatsAppCallSession::FINAL_STATUS_FAILED => 'Gagal',
            WhatsAppCallSession::FINAL_STATUS_CANCELLED => 'Dibatalkan',
            WhatsAppCallSession::FINAL_STATUS_PERMISSION_PENDING => 'Menunggu izin',
            default => 'Sedang berlangsung',
        };
    }
}
