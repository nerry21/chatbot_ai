<?php

namespace App\Services\AI;

class ResponseValidationService
{
    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $ruleEvaluation
     * @return array<string, mixed>
     */
    public function validateAndFinalize(
        array $replyResult,
        array $context,
        array $intentResult = [],
        array $ruleEvaluation = [],
    ): array {
        $validated = $this->sanitizeReplyResult($replyResult);

        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];

        $actions = is_array($ruleEvaluation['actions'] ?? null) ? $ruleEvaluation['actions'] : [];
        $ruleHits = is_array($ruleEvaluation['rule_hits'] ?? null) ? $ruleEvaluation['rule_hits'] : [];

        if (($actions['force_handoff'] ?? false) === true) {
            $validated['should_escalate'] = true;
            $validated['meta']['force_handoff'] = true;

            if (empty($validated['handoff_reason'])) {
                $validated['handoff_reason'] = 'Forced by rule evaluation';
            }
        }

        if (($flags['admin_takeover_active'] ?? false) === true || ($context['admin_takeover'] ?? false) === true) {
            $validated['should_escalate'] = true;
            $validated['meta']['force_handoff'] = true;
            $validated['handoff_reason'] = $validated['handoff_reason'] ?: 'Admin takeover active';
        }

        if (($conversation['needs_human'] ?? false) === true || (($intentResult['should_escalate'] ?? false) === true)) {
            $validated['should_escalate'] = true;
            $validated['handoff_reason'] = $validated['handoff_reason'] ?: 'Conversation marked as needs human';
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            if (($validated['next_action'] ?? '') === 'answer_question') {
                $validated['next_action'] = 'ask_missing_data';
            }

            if (empty($validated['data_requests'])) {
                $validated['data_requests'] = array_values($booking['missing_fields']);
            }
        }

        $validated['reply'] = $this->applyClaimBlocking(
            (string) ($validated['reply'] ?? ''),
            is_array($actions['block_claims'] ?? null) ? $actions['block_claims'] : []
        );

        if (trim((string) $validated['reply']) === '') {
            $validated['reply'] = 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.';
            $validated['safety_notes'][] = 'Empty reply after validation fallback';
        }

        if (($validated['should_escalate'] ?? false) === true && trim((string) ($validated['reply'] ?? '')) === '') {
            $validated['reply'] = 'Baik, percakapan ini akan saya teruskan ke admin kami agar dapat ditangani lebih tepat ya.';
        }

        $validated['safety_notes'] = array_values(array_unique(array_merge(
            is_array($validated['safety_notes'] ?? null) ? $validated['safety_notes'] : [],
            $ruleHits
        )));

        $validated['text'] = $validated['reply'];

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @return array<string, mixed>
     */
    private function sanitizeReplyResult(array $replyResult): array
    {
        return [
            'reply' => trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? '')),
            'tone' => trim((string) ($replyResult['tone'] ?? 'ramah')) ?: 'ramah',
            'should_escalate' => (bool) ($replyResult['should_escalate'] ?? false),
            'handoff_reason' => $replyResult['handoff_reason'] ?? null,
            'next_action' => trim((string) ($replyResult['next_action'] ?? 'answer_question')) ?: 'answer_question',
            'data_requests' => array_values(array_filter(
                is_array($replyResult['data_requests'] ?? null) ? $replyResult['data_requests'] : []
            )),
            'used_crm_facts' => array_values(array_filter(
                is_array($replyResult['used_crm_facts'] ?? null) ? $replyResult['used_crm_facts'] : []
            )),
            'safety_notes' => array_values(array_filter(
                is_array($replyResult['safety_notes'] ?? null) ? $replyResult['safety_notes'] : []
            )),
            'meta' => is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [],
            'is_fallback' => (bool) ($replyResult['is_fallback'] ?? false),
            'used_knowledge' => (bool) ($replyResult['used_knowledge'] ?? false),
            'used_faq' => (bool) ($replyResult['used_faq'] ?? false),
        ];
    }

    /**
     * @param  array<int, string>  $blockClaims
     */
    private function applyClaimBlocking(string $reply, array $blockClaims = []): string
    {
        $normalized = $reply;

        if (in_array('booking_confirmation', $blockClaims, true)) {
            $normalized = str_ireplace(
                ['booking anda sudah dikonfirmasi', 'siap berangkat'],
                ['booking Anda akan saya bantu proses setelah data lengkap', 'keberangkatan akan saya bantu cek setelah data lengkap'],
                $normalized
            );
        }

        if (in_array('operational_certainty', $blockClaims, true)) {
            $normalized = str_ireplace(
                ['dipastikan', 'pasti tersedia', 'sudah dijadwalkan', 'sudah dikonfirmasi'],
                ['akan saya bantu cek', 'akan saya bantu cek ketersediaannya', 'akan saya bantu cek jadwalnya', 'akan saya bantu lanjutkan pengecekannya'],
                $normalized
            );
        }

        return trim($normalized);
    }
}
