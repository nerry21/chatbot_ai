<?php

namespace App\Services\Chatbot;

use App\Models\AiLog;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use Carbon\Carbon;

class DailySummaryReportService
{
    /**
     * Generate summary text untuk hari yang diberikan (default: hari ini WIB).
     *
     * @return array{text: string, stats: array<string, mixed>}
     */
    public function generate(?Carbon $date = null): array
    {
        $date = ($date ?? now('Asia/Jakarta'))->copy()->setTimezone('Asia/Jakarta')->startOfDay();
        $start = $date->copy();
        $end = $date->copy()->endOfDay();

        $stats = $this->collectStats($start, $end);
        $text = $this->formatText($date, $stats);

        return ['text' => $text, 'stats' => $stats];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectStats(Carbon $start, Carbon $end): array
    {
        $logs = AiLog::query()
            ->where('task_type', 'llm_agent')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $totalCalls = $logs->count();
        $tier1 = $logs->filter(fn ($l) => ($l->parsed_output['tier'] ?? null) === 'tier1')->count();
        $tier2 = $logs->filter(fn ($l) => ($l->parsed_output['tier'] ?? null) === 'tier2')->count();

        $tier2Reasons = [
            'vip' => 0,
            'sentiment_negative' => 0,
            'multi_turn' => 0,
            'escalation_pending' => 0,
            'complaint_keyword' => 0,
        ];
        foreach ($logs as $log) {
            if (($log->parsed_output['tier'] ?? null) !== 'tier2') {
                continue;
            }
            foreach (($log->parsed_output['tier_reasons'] ?? []) as $reason) {
                if (isset($tier2Reasons[$reason])) {
                    $tier2Reasons[$reason]++;
                }
            }
        }

        $totalTokensIn = (int) $logs->sum('token_input');
        $totalTokensOut = (int) $logs->sum('token_output');
        $totalTokens = $totalTokensIn + $totalTokensOut;

        $estimatedUsd = ($totalTokensIn / 1_000_000) * 0.40 + ($totalTokensOut / 1_000_000) * 1.50;
        $estimatedIdr = $estimatedUsd * 16500;

        $uniqueCustomersChat = ConversationMessage::query()
            ->whereBetween('conversation_messages.created_at', [$start, $end])
            ->where('direction', \App\Enums\MessageDirection::Inbound->value)
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->distinct('conversations.customer_id')
            ->count('conversations.customer_id');

        $newCustomers = Customer::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $escalations = Escalation::query()
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $fallbackCount = AiLog::query()
            ->where('task_type', 'llm_agent')
            ->where('status', 'failed')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        return [
            'total_calls' => $totalCalls,
            'tier1' => $tier1,
            'tier2' => $tier2,
            'tier2_reasons' => $tier2Reasons,
            'total_tokens_in' => $totalTokensIn,
            'total_tokens_out' => $totalTokensOut,
            'total_tokens' => $totalTokens,
            'estimated_usd' => $estimatedUsd,
            'estimated_idr' => $estimatedIdr,
            'unique_customers' => $uniqueCustomersChat,
            'new_customers' => $newCustomers,
            'escalations' => $escalations,
            'fallback_count' => $fallbackCount,
            'status' => $totalCalls === 0
                ? '⚠️ Tidak ada chat AI hari ini'
                : ($fallbackCount > $totalCalls * 0.1 ? '⚠️ Fallback rate >10%' : '✅ Healthy'),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function formatText(Carbon $date, array $stats): string
    {
        $dateStr = $date->locale('id')->translatedFormat('j F Y');
        $tier2 = $stats['tier2_reasons'];

        $tier2Breakdown = [];
        if ($tier2['vip'] > 0) {
            $tier2Breakdown[] = "{$tier2['vip']} VIP";
        }
        if ($tier2['sentiment_negative'] > 0) {
            $tier2Breakdown[] = "{$tier2['sentiment_negative']} sentimen negatif";
        }
        if ($tier2['complaint_keyword'] > 0) {
            $tier2Breakdown[] = "{$tier2['complaint_keyword']} komplain";
        }
        if ($tier2['escalation_pending'] > 0) {
            $tier2Breakdown[] = "{$tier2['escalation_pending']} eskalasi pending";
        }
        if ($tier2['multi_turn'] > 0) {
            $tier2Breakdown[] = "{$tier2['multi_turn']} multi-turn";
        }
        $tier2Detail = $tier2Breakdown === [] ? '' : ' ('.implode(', ', $tier2Breakdown).')';

        $idrFormatted = 'Rp '.number_format($stats['estimated_idr'], 0, ',', '.');
        $usdFormatted = '$'.number_format($stats['estimated_usd'], 4);

        return <<<TEXT
📊 *Laporan Bot JET Travel — {$dateStr}*

🤖 *STATISTIK BOT*
Total chat AI hari ini: {$stats['total_calls']}
├─ Tier 1 (mini): {$stats['tier1']} chat
└─ Tier 2 (standar): {$stats['tier2']} chat{$tier2Detail}

💬 *CUSTOMER*
Customer unik chat hari ini: {$stats['unique_customers']}
Customer baru hari ini: {$stats['new_customers']}
Eskalasi ke admin: {$stats['escalations']}

💰 *BIAYA AI (estimasi)*
Token input: {$stats['total_tokens_in']}
Token output: {$stats['total_tokens_out']}
Total token: {$stats['total_tokens']}
Estimasi: {$usdFormatted} ({$idrFormatted})

🛡️ *KESEHATAN SISTEM*
Fallback ke sistem lama: {$stats['fallback_count']}
Status: {$stats['status']}

📌 *CATATAN*
Statistik operasional (penumpang per jam, uang admin per driver) menyusul setelah integrasi LKT One ↔ Chatbot AI (PR-CRM-6).
TEXT;
    }
}
