<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\Customer;

class CrmDecisionNoteBuilderService
{
    /**
     * Bangun teks note CRM dari decision trace.
     *
     * Mendukung trace_version 1 (legacy) dan 2 (v2 baku).
     * Untuk v2: baca dari section baru (understanding, policy, grounding, final_action, crm_effects).
     * Untuk v1: fallback ke legacy.* atau field lama langsung.
     *
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
        $traceVersion = (int) ($decisionTrace['trace_version'] ?? 1);
        $isV2 = $traceVersion >= 2;

        // --- Resolve sections: v2 first, fallback to legacy ---
        $understanding = $this->resolveSection($decisionTrace, 'understanding', 'llm', $isV2);
        $policy        = $this->resolveSection($decisionTrace, 'policy', 'policy_guard', $isV2);
        $grounding     = $this->resolveSection($decisionTrace, 'grounding', 'hallucination_guard', $isV2);
        $finalAction   = $this->resolveSection($decisionTrace, 'final_action', 'final_reply', $isV2);
        $crmEffects    = $this->resolveSection($decisionTrace, 'crm_effects', 'crm_state_before_writeback', $isV2);
        $llmRuntime    = is_array($decisionTrace['llm_runtime'] ?? null) ? $decisionTrace['llm_runtime'] : [];
        $legacy        = is_array($decisionTrace['legacy'] ?? null) ? $decisionTrace['legacy'] : [];
        $outcome       = is_array($legacy['outcome'] ?? null) ? $legacy['outcome'] : [];

        // --- Runtime health ---
        $runtimeHealth = $this->normalizeText($llmRuntime['overall_health'] ?? null)
            ?? $this->normalizeText($outcome['runtime_health'] ?? null)
            ?? 'unknown';

        // --- Used CRM facts: union from crm_effects + grounding + outcome ---
        $usedCrmFacts = $this->mergeStringLists([
            $crmEffects['used_crm_facts'] ?? [],
            $grounding['used_crm_facts'] ?? [],
            $outcome['used_crm_facts'] ?? [],
        ]);

        // --- Escalation reason (first non-empty) ---
        $escalationReason = $this->normalizeText($finalAction['handoff_reason'] ?? null)
            ?? $this->normalizeText($grounding['reason'] ?? null)
            ?? $this->joinList($policy['reasons'] ?? []);

        // --- Summary & reply text ---
        $summaryText = $this->normalizeText($crmEffects['summary']['text'] ?? null)
            ?? $this->normalizeText($legacy['summary']['text'] ?? null)
            ?? $this->normalizeText($summaryResult['summary'] ?? null);

        $replyText = $this->normalizeText($finalReply['text'] ?? null);

        // --- Build note sections ---
        $blocks = [];

        $blocks[] = $this->buildHeader($decisionTrace, $customer, $conversation, $traceVersion);
        $blocks[] = $this->buildUnderstandingBlock($understanding, $llmRuntime, $intentResult);
        $blocks[] = $this->buildPolicyBlock($policy);
        $blocks[] = $this->buildGroundingBlock($grounding);
        $blocks[] = $this->buildFinalActionBlock($finalAction, $escalationReason, $runtimeHealth);
        $blocks[] = $this->buildCrmEffectsBlock($crmEffects);
        $blocks[] = $this->buildRuntimeBlock($llmRuntime, $runtimeHealth);
        $blocks[] = $this->buildSummaryBlock($summaryText, $replyText, $usedCrmFacts);

        return implode("\n\n", array_filter($blocks, static fn (string $b): bool => $b !== ''));
    }

    // -------------------------------------------------------------------------
    // Note block builders
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $trace
     */
    private function buildHeader(
        array $trace,
        Customer $customer,
        Conversation $conversation,
        int $traceVersion,
    ): string {
        $lines = [
            '=== AI Decision Trace (v'.$traceVersion.') ===',
            $this->line('Trace ID',    $trace['trace_id'] ?? '-'),
            $this->line('Generated',   $trace['generated_at'] ?? now()->toIso8601String()),
            $this->line('Pelanggan',   ($customer->name ?? 'Tidak diketahui').' / #'.$conversation->id),
            $this->line('Nomor',       $customer->phone_e164 ?? '-'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $understanding
     * @param  array<string, mixed>  $llmRuntime
     * @param  array<string, mixed>  $intentResult
     */
    private function buildUnderstandingBlock(
        array $understanding,
        array $llmRuntime,
        array $intentResult,
    ): string {
        $intent     = $this->normalizeText($understanding['intent'] ?? null)
            ?? $this->normalizeText($intentResult['intent'] ?? null)
            ?? 'unknown';
        $confidence = isset($understanding['confidence'])
            ? number_format((float) $understanding['confidence'], 2, '.', '')
            : (isset($intentResult['confidence']) ? number_format((float) $intentResult['confidence'], 2, '.', '') : '-');
        $reasoning  = $this->normalizeText($understanding['reasoning_short'] ?? null)
            ?? $this->normalizeText($intentResult['reasoning_short'] ?? null)
            ?? '-';
        $health     = $this->normalizeText($understanding['runtime_health'] ?? null)
            ?? $this->normalizeText($llmRuntime['understanding_health'] ?? null)
            ?? 'unknown';
        $model      = $this->normalizeText(
            ($understanding['runtime']['model'] ?? null)
            ?? ($llmRuntime['understanding']['model'] ?? null)
        ) ?? '-';

        $lines = [
            '--- [1] Understanding ---',
            $this->line('Intent',    $intent.'  (confidence: '.$confidence.')'),
            $this->line('Reasoning', $reasoning),
            $this->line('Handoff',   $this->boolToText($understanding['handoff_recommended'] ?? ($intentResult['handoff_recommended'] ?? null))),
            $this->line('Clarify',   $this->boolToText($understanding['needs_clarification'] ?? ($intentResult['needs_clarification'] ?? null))),
            $this->line('Runtime',   $this->healthLabel($health).'  ['.$model.']'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function buildPolicyBlock(array $policy): string
    {
        $verdict = $this->normalizeText($policy['verdict'] ?? null) ?? '-';
        $action  = $this->normalizeText($policy['action'] ?? null) ?? '-';

        $lines = [
            '--- [2] Policy ---',
            $this->line('Verdict',    $verdict),
            $this->line('Action',     $action),
            $this->line('Blocked',    $this->boolToText($policy['blocked'] ?? null)),
            $this->line('Handoff',    $this->boolToText($policy['force_handoff'] ?? null)),
            $this->line('Reasons',    $this->joinList($policy['reasons'] ?? [])),
            $this->line('CRM Source', $this->normalizeText($policy['crm_policy_source'] ?? null) ?? '-'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $grounding
     */
    private function buildGroundingBlock(array $grounding): string
    {
        $verdict = $this->normalizeText($grounding['verdict'] ?? null) ?? '-';
        $action  = $this->normalizeText($grounding['action'] ?? null) ?? '-';

        $lines = [
            '--- [3] Grounding ---',
            $this->line('Verdict',      $verdict),
            $this->line('Action',       $action),
            $this->line('Blocked',      $this->boolToText($grounding['blocked'] ?? null)),
            $this->line('Reason',       $this->normalizeText($grounding['reason'] ?? null) ?? '-'),
            $this->line('CRM Sections', $this->joinList($grounding['crm_grounding_sections'] ?? [])),
            $this->line('CRM Present',  $this->boolToText($grounding['crm_grounding_present'] ?? null)),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $finalAction
     */
    private function buildFinalActionBlock(
        array $finalAction,
        ?string $escalationReason,
        string $runtimeHealth,
    ): string {
        $decision    = $this->normalizeText($finalAction['final_decision'] ?? null) ?? '-';
        $replyAction = $this->normalizeText($finalAction['reply_action'] ?? null) ?? '-';
        $replySource = $this->normalizeText($finalAction['reply_source'] ?? null) ?? '-';

        $lines = [
            '--- [4] Final Action ---',
            $this->line('Decision',       $decision),
            $this->line('Reply Action',   $replyAction),
            $this->line('Reply Source',   $replySource),
            $this->line('Needs Escalate', $this->boolToText($finalAction['needs_escalation'] ?? null)),
            $this->line('Escalation Why', $escalationReason ?? '-'),
            $this->line('Force Handoff',  $this->boolToText($finalAction['force_handoff'] ?? null)),
            $this->line('Is Fallback',    $this->boolToText($finalAction['is_fallback'] ?? null)),
            $this->line('Policy V.',      $this->normalizeText($finalAction['policy_verdict'] ?? null) ?? '-'),
            $this->line('Grounding V.',   $this->normalizeText($finalAction['grounding_guard_verdict'] ?? null) ?? '-'),
            $this->line('Runtime',        $this->healthLabel($finalAction['runtime_health'] ?? $runtimeHealth)),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $crmEffects
     */
    private function buildCrmEffectsBlock(array $crmEffects): string
    {
        $lines = [
            '--- [5] CRM Effects ---',
            $this->line('Lead Stage',  $this->normalizeText($crmEffects['lead_stage'] ?? null) ?? '-'),
            $this->line('Conv Status', $this->normalizeText($crmEffects['conversation_status'] ?? null) ?? '-'),
            $this->line('Needs Human', $this->boolToText($crmEffects['needs_human'] ?? null)),
            $this->line('Bot Paused',  $this->boolToText($crmEffects['bot_paused'] ?? null)),
            $this->line('Escalation',  $this->boolToText($crmEffects['open_escalation'] ?? null)),
            $this->line('HubSpot ID',  $this->normalizeText($crmEffects['hubspot_contact_id'] ?? null) ?? '-'),
            $this->line('HS Stage',    $this->normalizeText($crmEffects['hubspot_lifecycle_stage'] ?? null) ?? '-'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $llmRuntime
     */
    private function buildRuntimeBlock(array $llmRuntime, string $runtimeHealth): string
    {
        $understanding = is_array($llmRuntime['understanding'] ?? null) ? $llmRuntime['understanding'] : [];
        $replyDraft    = is_array($llmRuntime['reply_draft'] ?? null) ? $llmRuntime['reply_draft'] : [];
        $grounded      = is_array($llmRuntime['grounded_response'] ?? null) ? $llmRuntime['grounded_response'] : [];

        $lines = [
            '--- [6] LLM Runtime ---',
            $this->line('Overall',      $this->healthLabel($runtimeHealth)),
            $this->line('Understanding', $this->healthLabel($llmRuntime['understanding_health'] ?? 'unknown')
                .$this->modelSuffix($understanding['model'] ?? null)),
            $this->line('Reply Draft',  $this->healthLabel($llmRuntime['reply_draft_health'] ?? 'unknown')
                .$this->modelSuffix($replyDraft['model'] ?? null)),
            $this->line('Grounded',     $this->healthLabel($llmRuntime['grounded_response_health'] ?? 'unknown')
                .$this->modelSuffix($grounded['model'] ?? null)),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $usedCrmFacts
     */
    private function buildSummaryBlock(
        ?string $summaryText,
        ?string $replyText,
        array $usedCrmFacts,
    ): string {
        $lines = [
            '--- Summary & Balasan ---',
            $this->line('Used CRM Facts', $this->joinList($usedCrmFacts)),
            '',
            'Ringkasan AI:',
            $summaryText ?: '-',
            '',
            'Balasan Final:',
            $replyText ?: '-',
        ];

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Section resolver
    // -------------------------------------------------------------------------

    /**
     * Baca section dari v2 trace, fallback ke legacy jika tidak ada.
     *
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    private function resolveSection(
        array $trace,
        string $v2Key,
        string $legacyKey,
        bool $isV2,
    ): array {
        if ($isV2 && is_array($trace[$v2Key] ?? null)) {
            return $trace[$v2Key];
        }

        $legacy = is_array($trace['legacy'] ?? null) ? $trace['legacy'] : [];

        if (is_array($legacy[$legacyKey] ?? null)) {
            return $legacy[$legacyKey];
        }

        // v1 trace: field lama langsung di root
        if (is_array($trace[$legacyKey] ?? null)) {
            return $trace[$legacyKey];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Formatting helpers
    // -------------------------------------------------------------------------

    private function line(string $label, string $value): string
    {
        return str_pad($label, 14, ' ').' : '.$value;
    }

    private function modelSuffix(mixed $model): string
    {
        $text = $this->normalizeText($model);

        return $text !== null ? '  ['.$text.']' : '';
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

    /**
     * @param  array<int, array<int, string>|mixed>  $lists
     * @return array<int, string>
     */
    private function mergeStringLists(array $lists): array
    {
        $merged = [];

        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $item) {
                if (! is_scalar($item)) {
                    continue;
                }

                $text = trim((string) $item);

                if ($text !== '') {
                    $merged[] = $text;
                }
            }
        }

        return array_values(array_unique($merged));
    }
}
