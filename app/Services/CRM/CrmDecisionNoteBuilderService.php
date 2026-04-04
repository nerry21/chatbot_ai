<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\Customer;

class CrmDecisionNoteBuilderService
{
    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $contextSnapshot
     */
    public function build(
        Customer $customer,
        Conversation $conversation,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $contextSnapshot = [],
    ): string {
        $intent = (string) ($intentResult['intent'] ?? 'unknown');
        $confidence = isset($intentResult['confidence'])
            ? number_format((float) $intentResult['confidence'], 2, '.', '')
            : null;

        $reasoning = $this->normalizeText($intentResult['reasoning_short'] ?? null);
        $summary = $this->normalizeText($summaryResult['summary'] ?? null);
        $replyText = $this->normalizeText($finalReply['text'] ?? null);
        $replyMeta = is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [];

        $crmContext = is_array($contextSnapshot['crm_context'] ?? null)
            ? $contextSnapshot['crm_context']
            : [];

        $leadStage = $crmContext['lead_pipeline']['stage'] ?? null;
        $hubspotLifecycle = $crmContext['hubspot']['lifecycle_stage'] ?? null;
        $conversationStatus = $crmContext['conversation']['status'] ?? null;
        $needsHuman = $crmContext['conversation']['needs_human'] ?? null;
        $botPaused = $crmContext['business_flags']['bot_paused'] ?? null;
        $openEscalation = $crmContext['escalation']['has_open_escalation'] ?? null;

        $lines = array_filter([
            '=== AI Decision Snapshot ===',
            'Pelanggan   : ' . ($customer->name ?? 'Tidak diketahui'),
            'Nomor       : ' . ($customer->phone_e164 ?? '-'),
            'Percakapan  : #' . $conversation->id,
            'Waktu       : ' . now()->format('d M Y H:i') . ' WIB',
            '',
            'Intent      : ' . $intent,
            'Confidence  : ' . ($confidence ?? '-'),
            'Reasoning   : ' . ($reasoning ?? '-'),
            'ReplySource : ' . ($replyMeta['source'] ?? '-'),
            'ReplyAction : ' . ($replyMeta['action'] ?? '-'),
            '',
            'Lead Stage  : ' . ($leadStage ?: '-'),
            'Lifecycle   : ' . ($hubspotLifecycle ?: '-'),
            'Conv Status : ' . ($conversationStatus ?: '-'),
            'NeedsHuman  : ' . $this->boolToText($needsHuman),
            'BotPaused   : ' . $this->boolToText($botPaused),
            'Escalation  : ' . $this->boolToText($openEscalation),
            '',
            'Ringkasan AI:',
            $summary ?: '-',
            '',
            'Balasan Final:',
            $replyText ?: '-',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return implode("\n", $lines);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function boolToText(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return (bool) $value ? 'ya' : 'tidak';
    }
}
