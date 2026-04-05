<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Support\Str;

class CrmDecisionTraceBuilderService
{
    /**
     * Bangun decision trace final yang menyatukan:
     * - hasil intent/understanding
     * - policy guard
     * - hallucination guard
     * - final reply
     * - snapshot CRM sebelum writeback
     * - outcome final untuk CRM audit
     *
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $contextSnapshot
     * @return array<string, mixed>
     */
    public function build(
        Customer $customer,
        Conversation $conversation,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $contextSnapshot = [],
    ): array {
        $crmContext = is_array($contextSnapshot['crm_context'] ?? null)
            ? $contextSnapshot['crm_context']
            : [];

        $policyGuard = is_array($contextSnapshot['policy_guard'] ?? null)
            ? $contextSnapshot['policy_guard']
            : [];

        $hallucinationGuard = is_array($contextSnapshot['hallucination_guard'] ?? null)
            ? $contextSnapshot['hallucination_guard']
            : [];

        $orchestration = is_array($contextSnapshot['orchestration'] ?? null)
            ? $contextSnapshot['orchestration']
            : [];

        $replyMeta = is_array($finalReply['meta'] ?? null)
            ? $finalReply['meta']
            : [];

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmBusinessFlags = is_array($crmContext['business_flags'] ?? null)
            ? $crmContext['business_flags']
            : [];

        $crmEscalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];

        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        $crmHubspot = is_array($crmContext['hubspot'] ?? null)
            ? $crmContext['hubspot']
            : [];

        $usedCrmFacts = $this->uniqueStrings(array_merge(
            $this->toStringList($finalReply['used_crm_facts'] ?? []),
            $this->toStringList($hallucinationGuard['used_crm_facts'] ?? []),
            $this->toStringList($replyMeta['used_crm_facts'] ?? []),
            $this->prefixSections(
                $this->toStringList($hallucinationGuard['crm_grounding_sections'] ?? []),
                'crm.'
            ),
            $this->normalizePolicySource($policyGuard['crm_policy_source'] ?? null),
        ));

        $needsEscalation =
            (bool) ($intentResult['handoff_recommended'] ?? false)
            || (bool) ($intentResult['needs_human_review'] ?? false)
            || (bool) ($finalReply['should_escalate'] ?? false)
            || (($replyMeta['force_handoff'] ?? false) === true)
            || (bool) ($summaryResult['needs_human_followup'] ?? false)
            || (bool) ($crmConversation['needs_human'] ?? false)
            || (bool) ($crmEscalation['has_open_escalation'] ?? false);

        $finalDecision = $this->deriveFinalDecision(
            intentResult: $intentResult,
            finalReply: $finalReply,
            policyGuard: $policyGuard,
            hallucinationGuard: $hallucinationGuard,
        );

        return [
            'trace_version' => 1,
            'trace_id' => (string) Str::uuid(),
            'generated_at' => now()->toIso8601String(),
            'source' => 'crm_llm_closed_loop_trace',

            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone_e164,
            ],

            'conversation' => [
                'id' => $conversation->id,
                'status' => $conversation->status ?? null,
                'admin_takeover_runtime' => method_exists($conversation, 'isAdminTakeover')
                    ? $conversation->isAdminTakeover()
                    : false,
            ],

            'llm' => [
                'intent' => (string) ($intentResult['intent'] ?? 'unknown'),
                'confidence' => isset($intentResult['confidence'])
                    ? (float) $intentResult['confidence']
                    : 0.0,
                'reasoning_short' => $this->normalizeText($intentResult['reasoning_short'] ?? null),
                'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
                'clarification_question' => $this->normalizeText($intentResult['clarification_question'] ?? null),
                'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
                'uses_previous_context' => (bool) ($intentResult['uses_previous_context'] ?? false),
                'understanding_source' => $this->normalizeText($intentResult['understanding_source'] ?? null),
            ],

            'policy_guard' => [
                'action' => $this->normalizeText($policyGuard['action'] ?? null) ?? 'unknown',
                'blocked' => (bool) ($policyGuard['block_auto_reply'] ?? false),
                'reasons' => $this->toStringList($policyGuard['reasons'] ?? []),
                'crm_policy_source' => $this->normalizeText($policyGuard['crm_policy_source'] ?? null),
                'crm_policy_snapshot' => is_array($policyGuard['crm_policy_snapshot'] ?? null)
                    ? $policyGuard['crm_policy_snapshot']
                    : [],
                'decision_trace_policy' => is_array($policyGuard['decision_trace_policy'] ?? null)
                    ? $policyGuard['decision_trace_policy']
                    : [],
            ],

            'hallucination_guard' => [
                'action' => $this->normalizeText($hallucinationGuard['action'] ?? null) ?? 'unknown',
                'blocked' => (bool) ($hallucinationGuard['blocked'] ?? false),
                'reason' => $this->normalizeText($hallucinationGuard['reason'] ?? null),
                'grounding_source' => $this->normalizeText($hallucinationGuard['grounding_source'] ?? null),
                'crm_grounding_present' => (bool) ($hallucinationGuard['crm_grounding_present'] ?? false),
                'crm_grounding_sections' => $this->toStringList($hallucinationGuard['crm_grounding_sections'] ?? []),
                'used_crm_facts' => $this->toStringList($hallucinationGuard['used_crm_facts'] ?? []),
                'decision_trace_hallucination' => is_array($hallucinationGuard['decision_trace_hallucination'] ?? null)
                    ? $hallucinationGuard['decision_trace_hallucination']
                    : [],
            ],

            'final_reply' => [
                'source' => $this->normalizeText($replyMeta['source'] ?? null),
                'action' => $this->normalizeText($replyMeta['action'] ?? null),
                'grounding_source' => $this->normalizeText($replyMeta['grounding_source'] ?? null),
                'crm_grounding_present' => (bool) ($replyMeta['crm_grounding_present'] ?? false),
                'crm_grounding_sections' => $this->toStringList($replyMeta['crm_grounding_sections'] ?? []),
                'force_handoff' => (bool) ($replyMeta['force_handoff'] ?? false),
                'is_fallback' => (bool) ($finalReply['is_fallback'] ?? false),
                'should_escalate' => (bool) ($finalReply['should_escalate'] ?? false),
                'used_crm_facts' => $this->toStringList($finalReply['used_crm_facts'] ?? []),
            ],

            'crm_state_before_writeback' => [
                'lead_stage' => $this->normalizeText($crmLeadPipeline['stage'] ?? null),
                'conversation_status' => $this->normalizeText($crmConversation['status'] ?? null),
                'needs_human' => (bool) ($crmConversation['needs_human'] ?? false),
                'admin_takeover' => (bool) ($crmConversation['admin_takeover'] ?? false),
                'bot_paused' => (bool) ($crmBusinessFlags['bot_paused'] ?? false),
                'admin_takeover_active' => (bool) ($crmBusinessFlags['admin_takeover_active'] ?? false),
                'open_escalation' => (bool) ($crmEscalation['has_open_escalation'] ?? false),
                'hubspot_contact_id' => $this->normalizeText($crmHubspot['contact_id'] ?? null),
                'hubspot_lifecycle_stage' => $this->normalizeText($crmHubspot['lifecycle_stage'] ?? null),
            ],

            'summary' => [
                'text' => $this->normalizeText($summaryResult['summary'] ?? null),
                'sentiment' => $this->normalizeText($summaryResult['sentiment'] ?? null),
                'needs_human_followup' => (bool) ($summaryResult['needs_human_followup'] ?? false),
            ],

            'orchestration' => [
                'reply_force_handoff' => (bool) ($orchestration['reply_force_handoff'] ?? false),
                'booking_action' => $this->normalizeText($orchestration['booking_action'] ?? null),
                'final_reply_source' => $this->normalizeText($orchestration['final_reply_source'] ?? null),
            ],

            'outcome' => [
                'final_decision' => $finalDecision,
                'needs_escalation' => $needsEscalation,
                'used_crm_facts' => $usedCrmFacts,
                'closed_loop_ready' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $policyGuard
     * @param  array<string, mixed>  $hallucinationGuard
     */
    private function deriveFinalDecision(
        array $intentResult,
        array $finalReply,
        array $policyGuard,
        array $hallucinationGuard,
    ): string {
        if (($finalReply['should_escalate'] ?? false) === true || (($finalReply['meta']['force_handoff'] ?? false) === true)) {
            return 'handoff';
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return 'clarify';
        }

        if (in_array(($policyGuard['action'] ?? null), ['handoff', 'blocked_admin_takeover', 'blocked_bot_paused', 'blocked_takeover'], true)) {
            return 'handoff';
        }

        if (in_array(($hallucinationGuard['action'] ?? null), ['handoff', 'clarify'], true)) {
            return (string) $hallucinationGuard['action'];
        }

        return 'allow';
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    private function prefixSections(array $items, string $prefix): array
    {
        $out = [];

        foreach ($items as $item) {
            $text = trim($item);

            if ($text !== '') {
                $out[] = $prefix.$text;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<int, string>
     */
    private function normalizePolicySource(mixed $value): array
    {
        if (! is_scalar($value)) {
            return [];
        }

        $text = trim((string) $value);

        return $text !== '' ? [$text] : [];
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
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    private function uniqueStrings(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $text = trim((string) $item);

            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }
}
