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
     * @param  array<string, mixed>  $decisionTrace
     */
    public function build(
        Customer $customer,
        Conversation $conversation,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $contextSnapshot = [],
        array $decisionTrace = [],
    ): string {
        $replyText = $this->normalizeText($finalReply['text'] ?? null);
        $summary = $this->normalizeText($summaryResult['summary'] ?? null);

        $llm = is_array($decisionTrace['llm'] ?? null) ? $decisionTrace['llm'] : [];
        $policy = is_array($decisionTrace['policy_guard'] ?? null) ? $decisionTrace['policy_guard'] : [];
        $hallucination = is_array($decisionTrace['hallucination_guard'] ?? null) ? $decisionTrace['hallucination_guard'] : [];
        $finalReplyTrace = is_array($decisionTrace['final_reply'] ?? null) ? $decisionTrace['final_reply'] : [];
        $crmBefore = is_array($decisionTrace['crm_state_before_writeback'] ?? null) ? $decisionTrace['crm_state_before_writeback'] : [];
        $outcome = is_array($decisionTrace['outcome'] ?? null) ? $decisionTrace['outcome'] : [];
        $llmRuntime = is_array($decisionTrace['llm_runtime'] ?? null) ? $decisionTrace['llm_runtime'] : [];
        $runtimeHealth = $this->normalizeText($llmRuntime['overall_health'] ?? ($outcome['runtime_health'] ?? null)) ?? 'unknown';
        $understandingRuntime = is_array($llmRuntime['understanding'] ?? null) ? $llmRuntime['understanding'] : [];
        $replyDraftRuntime = is_array($llmRuntime['reply_draft'] ?? null) ? $llmRuntime['reply_draft'] : [];
        $groundedRuntime = is_array($llmRuntime['grounded_response'] ?? null) ? $llmRuntime['grounded_response'] : [];

        $lines = array_filter([
            '=== AI Decision Trace / CRM Audit Snapshot ===',
            'Trace ID        : '.($decisionTrace['trace_id'] ?? '-'),
            'Generated At    : '.($decisionTrace['generated_at'] ?? now()->toIso8601String()),
            'Pelanggan       : '.($customer->name ?? 'Tidak diketahui'),
            'Nomor           : '.($customer->phone_e164 ?? '-'),
            'Percakapan      : #'.$conversation->id,
            '',

            '--- LLM Understanding ---',
            'Intent          : '.($llm['intent'] ?? ($intentResult['intent'] ?? 'unknown')),
            'Confidence      : '.(isset($llm['confidence']) ? number_format((float) $llm['confidence'], 2, '.', '') : '-'),
            'Reasoning       : '.($llm['reasoning_short'] ?? ($intentResult['reasoning_short'] ?? '-')),
            'Clarify         : '.$this->boolToText($llm['needs_clarification'] ?? ($intentResult['needs_clarification'] ?? null)),
            'Handoff Rec     : '.$this->boolToText($llm['handoff_recommended'] ?? ($intentResult['handoff_recommended'] ?? null)),
            'Model           : '.($llm['model'] ?? ($understandingRuntime['model'] ?? '-')),
            'Provider        : '.($llm['provider'] ?? ($understandingRuntime['provider'] ?? '-')),
            '',

            '--- AI Runtime Quality ---',
            'Overall Health  : '.$this->healthLabel($runtimeHealth),
            'Understanding   : '.$this->healthLabel($llmRuntime['understanding_health'] ?? 'unknown')
                .($understandingRuntime['model'] !== null && $understandingRuntime['model'] !== ''
                    ? '  ['.$understandingRuntime['model'].']'
                    : ''),
            'Reply Draft     : '.$this->healthLabel($llmRuntime['reply_draft_health'] ?? 'unknown')
                .($replyDraftRuntime['model'] !== null && ($replyDraftRuntime['model'] ?? '') !== ''
                    ? '  ['.$replyDraftRuntime['model'].']'
                    : ''),
            'Grounded Resp   : '.$this->healthLabel($llmRuntime['grounded_response_health'] ?? 'unknown')
                .($groundedRuntime['model'] !== null && ($groundedRuntime['model'] ?? '') !== ''
                    ? '  ['.$groundedRuntime['model'].']'
                    : ''),
            '',

            '--- Policy Guard ---',
            'Action          : '.($policy['action'] ?? '-'),
            'Blocked         : '.$this->boolToText($policy['blocked'] ?? null),
            'Reasons         : '.$this->joinList($policy['reasons'] ?? []),
            'CRM Source      : '.($policy['crm_policy_source'] ?? '-'),
            '',

            '--- Hallucination Guard ---',
            'Action          : '.($hallucination['action'] ?? '-'),
            'Blocked         : '.$this->boolToText($hallucination['blocked'] ?? null),
            'Reason          : '.($hallucination['reason'] ?? '-'),
            'Grounding Src   : '.($hallucination['grounding_source'] ?? '-'),
            'CRM Present     : '.$this->boolToText($hallucination['crm_grounding_present'] ?? null),
            'CRM Sections    : '.$this->joinList($hallucination['crm_grounding_sections'] ?? []),
            '',

            '--- Final Reply ---',
            'Reply Source    : '.($finalReplyTrace['source'] ?? ($finalReply['meta']['source'] ?? '-')),
            'Reply Action    : '.($finalReplyTrace['action'] ?? ($finalReply['meta']['action'] ?? '-')),
            'Force Handoff   : '.$this->boolToText($finalReplyTrace['force_handoff'] ?? ($finalReply['meta']['force_handoff'] ?? null)),
            'Is Fallback     : '.$this->boolToText($finalReplyTrace['is_fallback'] ?? ($finalReply['is_fallback'] ?? null)),
            'Used CRM Facts  : '.$this->joinList($outcome['used_crm_facts'] ?? []),
            '',

            '--- CRM State Before Writeback ---',
            'Lead Stage      : '.($crmBefore['lead_stage'] ?? '-'),
            'Conv Status     : '.($crmBefore['conversation_status'] ?? '-'),
            'Needs Human     : '.$this->boolToText($crmBefore['needs_human'] ?? null),
            'Admin Takeover  : '.$this->boolToText($crmBefore['admin_takeover'] ?? null),
            'Bot Paused      : '.$this->boolToText($crmBefore['bot_paused'] ?? null),
            'Open Escalation : '.$this->boolToText($crmBefore['open_escalation'] ?? null),
            '',

            '--- Outcome ---',
            'Final Decision  : '.($outcome['final_decision'] ?? '-'),
            'Needs Escalate  : '.$this->boolToText($outcome['needs_escalation'] ?? null),
            'Closed Loop     : '.$this->boolToText($outcome['closed_loop_ready'] ?? null),
            'Runtime Health  : '.$this->healthLabel($outcome['runtime_health'] ?? $runtimeHealth),
            '',

            'Ringkasan AI:',
            $summary ?: '-',
            '',
            'Balasan Final:',
            $replyText ?: '-',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return implode("\n", $lines);
    }

    private function healthLabel(mixed $health): string
    {
        return match ((string) ($health ?? 'unknown')) {
            'healthy'        => 'Healthy',
            'fallback_model' => 'Fallback Model',
            'degraded'       => 'Degraded',
            'fallback'       => 'Fallback (tidak sehat)',
            'schema_invalid' => 'Schema Invalid',
            default          => 'Unknown',
        };
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  mixed  $value
     */
    private function boolToText(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return (bool) $value ? 'ya' : 'tidak';
    }

    /**
     * @param  mixed  $items
     */
    private function joinList(mixed $items): string
    {
        if (! is_array($items) || $items === []) {
            return '-';
        }

        $out = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out !== [] ? implode(', ', array_values(array_unique($out))) : '-';
    }
}
