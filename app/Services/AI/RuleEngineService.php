<?php

namespace App\Services\AI;

class RuleEngineService
{
    /**
     * Translate internal field names to customer-friendly Indonesian labels.
     */
    public static function humanizeFieldName(string $field): string
    {
        return match ($field) {
            'departure_date', 'travel_date' => 'tanggal keberangkatan',
            'departure_time', 'travel_time' => 'jam keberangkatan',
            'passenger_count' => 'jumlah penumpang',
            'selected_seats' => 'pilihan seat',
            'pickup_location' => 'titik penjemputan',
            'pickup_full_address' => 'alamat lengkap penjemputan',
            'destination' => 'tujuan pengantaran',
            'destination_full_address' => 'alamat lengkap tujuan',
            'passenger_name', 'passenger_names' => 'nama penumpang',
            'contact_number' => 'nomor kontak',
            default => str_replace('_', ' ', $field),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $replyResult
     * @return array<string, mixed>
     */
    public function evaluateOperationalRules(
        array $context,
        array $intentResult = [],
        array $replyResult = [],
    ): array {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];

        $intent = trim((string) ($intentResult['intent'] ?? 'unknown'));
        $replyText = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));

        $ruleHits = [];
        $actions = [
            'allow_reply' => true,
            'force_handoff' => false,
            'force_safe_fallback' => false,
            'force_ask_missing_data' => false,
            'block_claims' => [],
        ];

        if (($flags['admin_takeover_active'] ?? false) === true || ($context['admin_takeover'] ?? false) === true) {
            $ruleHits[] = 'admin_takeover_active';
            $actions['force_handoff'] = true;
        }

        if (($conversation['needs_human'] ?? false) === true) {
            $ruleHits[] = 'conversation_needs_human';
            $actions['force_handoff'] = true;
        }

        if (($escalation['has_open_escalation'] ?? false) === true) {
            $ruleHits[] = 'open_escalation_exists';
            $actions['force_handoff'] = true;
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $ruleHits[] = 'booking_missing_fields';
            $actions['force_ask_missing_data'] = true;
            $actions['block_claims'][] = 'booking_confirmation';
        }

        if (in_array($intent, ['complaint', 'refund', 'legal_issue', 'threat', 'sensitive_case'], true)) {
            $ruleHits[] = 'sensitive_intent_detected';
            $actions['force_handoff'] = true;
            $actions['force_safe_fallback'] = true;
        }

        if ($replyText !== '') {
            $lower = mb_strtolower($replyText, 'UTF-8');

            if (
                str_contains($lower, 'dipastikan')
                || str_contains($lower, 'pasti tersedia')
                || str_contains($lower, 'sudah dijadwalkan')
                || str_contains($lower, 'sudah dikonfirmasi')
            ) {
                $ruleHits[] = 'overclaim_operational_certainty';
                $actions['block_claims'][] = 'operational_certainty';
            }

            if (
                ! empty($booking['missing_fields'])
                && (
                    str_contains($lower, 'booking anda sudah dikonfirmasi')
                    || str_contains($lower, 'siap berangkat')
                )
            ) {
                $ruleHits[] = 'premature_booking_confirmation';
                $actions['block_claims'][] = 'booking_confirmation';
            }
        }

        if ($actions['force_handoff'] === true) {
            $actions['allow_reply'] = true;
        }

        return [
            'rule_hits' => array_values(array_unique($ruleHits)),
            'actions' => [
                ...$actions,
                'block_claims' => array_values(array_unique($actions['block_claims'])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $ruleEvaluation
     * @return array<string, mixed>
     */
    public function buildSafeFallbackFromRules(array $context, array $ruleEvaluation): array
    {
        $actions = is_array($ruleEvaluation['actions'] ?? null) ? $ruleEvaluation['actions'] : [];
        $ruleHits = is_array($ruleEvaluation['rule_hits'] ?? null) ? $ruleEvaluation['rule_hits'] : [];

        if (($actions['force_handoff'] ?? false) === true) {
            return [
                'reply' => 'Baik, agar penanganannya lebih tepat, percakapan ini akan saya teruskan ke admin kami ya.',
                'tone' => 'empatik',
                'should_escalate' => true,
                'handoff_reason' => 'Rule engine forced handoff',
                'next_action' => 'handoff_admin',
                'data_requests' => [],
                'used_crm_facts' => [],
                'safety_notes' => $ruleHits,
                'meta' => [
                    'force_handoff' => true,
                    'source' => 'rule_engine_fallback',
                ],
            ];
        }

        if (($actions['force_ask_missing_data'] ?? false) === true) {
            $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
            $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
            $missing = is_array($booking['missing_fields'] ?? null) ? $booking['missing_fields'] : [];

            // Ask only the FIRST missing field — natural step-by-step flow.
            $firstMissing = $missing[0] ?? null;
            $firstMissingLabel = $firstMissing !== null ? self::humanizeFieldName($firstMissing) : 'data booking';

            return [
                'reply' => 'Baik, saya bantu lanjutkan. Izin Bapak/Ibu, mohon dibantu isi '.$firstMissingLabel.'-nya terlebih dahulu ya.',
                'tone' => 'ramah',
                'should_escalate' => false,
                'handoff_reason' => null,
                'next_action' => 'ask_missing_data',
                'data_requests' => $firstMissing !== null ? [$firstMissing] : [],
                'used_crm_facts' => ['booking.missing_fields'],
                'safety_notes' => $ruleHits,
                'meta' => [
                    'force_handoff' => false,
                    'source' => 'rule_engine_missing_data_fallback',
                    'first_missing_field' => $firstMissing,
                    'total_missing_count' => count($missing),
                ],
            ];
        }

        return [
            'reply' => 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.',
            'tone' => 'ramah',
            'should_escalate' => false,
            'handoff_reason' => null,
            'next_action' => 'safe_fallback',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => $ruleHits,
            'meta' => [
                'force_handoff' => false,
                'source' => 'rule_engine_safe_fallback',
            ],
        ];
    }
}