<?php

namespace App\Services\Chatbot\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;

class HallucinationGuardService
{
    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array{risk_level: string, risk_flags: array<int, string>, is_safe: bool}
     */
    public function inspectGroundingRisk(
        array $replyResult,
        array $context,
        array $orchestrationSnapshot = [],
    ): array {
        $reply = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];

        $riskFlags = [];
        $riskLevel = 'low';

        if ($reply === '') {
            $riskFlags[] = 'empty_reply';
            $riskLevel = $this->elevateRiskLevel($riskLevel, 'medium');
        }

        $lower = mb_strtolower($reply, 'UTF-8');

        if (
            str_contains($lower, 'dipastikan')
            || str_contains($lower, 'pasti tersedia')
            || str_contains($lower, 'sudah dikonfirmasi')
            || str_contains($lower, 'telah diproses')
        ) {
            $riskFlags[] = 'unsupported_operational_certainty';
            $riskLevel = $this->elevateRiskLevel($riskLevel, 'high');
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            if (
                str_contains($lower, 'booking anda sudah dikonfirmasi')
                || str_contains($lower, 'siap berangkat')
                || str_contains($lower, 'jadwal anda sudah aman')
            ) {
                $riskFlags[] = 'booking_claim_while_data_incomplete';
                $riskLevel = $this->elevateRiskLevel($riskLevel, 'high');
            }
        }

        if (($conversation['summary'] ?? null) === null && str_contains($lower, 'sesuai pembicaraan sebelumnya')) {
            $riskFlags[] = 'claims_previous_context_without_summary';
            $riskLevel = $this->elevateRiskLevel($riskLevel, 'medium');
        }

        if (($orchestrationSnapshot['reply_force_handoff'] ?? false) === true && str_contains($lower, 'saya akan selesaikan langsung')) {
            $riskFlags[] = 'reply_conflicts_with_handoff';
            $riskLevel = $this->elevateRiskLevel($riskLevel, 'high');
        }

        return [
            'risk_level' => $riskLevel,
            'risk_flags' => array_values(array_unique($riskFlags)),
            'is_safe' => $riskLevel !== 'high',
        ];
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array{risk_level?: string, risk_flags?: array<int, string>, is_safe?: bool}  $riskReport
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function enforceHallucinationFallback(
        array $replyResult,
        array $riskReport,
        array $context = [],
    ): array {
        if (($riskReport['is_safe'] ?? true) === true) {
            return $replyResult;
        }

        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            return [
                'reply' => 'Baik, saya bantu lanjutkan. Sebelum itu, mohon lengkapi data berikut terlebih dahulu: '.implode(', ', $booking['missing_fields']).'.',
                'text' => 'Baik, saya bantu lanjutkan. Sebelum itu, mohon lengkapi data berikut terlebih dahulu: '.implode(', ', $booking['missing_fields']).'.',
                'tone' => 'ramah',
                'should_escalate' => false,
                'handoff_reason' => null,
                'next_action' => 'ask_missing_data',
                'data_requests' => array_values($booking['missing_fields']),
                'used_crm_facts' => ['booking.missing_fields'],
                'safety_notes' => array_values($riskReport['risk_flags'] ?? []),
                'message_type' => 'text',
                'outbound_payload' => [],
                'is_fallback' => true,
                'meta' => [
                    'force_handoff' => false,
                    'source' => 'hallucination_guard_missing_data_fallback',
                ],
            ];
        }

        return [
            'reply' => 'Baik, agar informasi yang saya sampaikan tetap akurat, percakapan ini akan saya teruskan ke admin kami ya.',
            'text' => 'Baik, agar informasi yang saya sampaikan tetap akurat, percakapan ini akan saya teruskan ke admin kami ya.',
            'tone' => 'empatik',
            'should_escalate' => true,
            'handoff_reason' => 'Hallucination risk too high',
            'next_action' => 'handoff_admin',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => array_values($riskReport['risk_flags'] ?? []),
            'message_type' => 'text',
            'outbound_payload' => [],
            'is_fallback' => true,
            'meta' => [
                'force_handoff' => true,
                'source' => 'hallucination_guard_handoff_fallback',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $context
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{
     *         guard_group: string,
     *         action: string,
     *         blocked: bool,
     *         reason: string|null
     *     }
     * }
     */
    public function guardReply(
        Conversation $conversation,
        array $intentResult,
        array $reply,
        array $context = [],
    ): array {
        $source = (string) ($reply['meta']['source'] ?? '');
        if ($source !== 'ai_reply') {
            return $this->allow($reply, $intentResult);
        }

        if (($conversation->isAdminTakeover() || (($context['admin_takeover'] ?? false) === true))) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Admin takeover aktif; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, percakapan ini sedang ditangani admin ya.',
            );
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding meminta handoff; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya.',
            );
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding masih ambigu; AI reply bebas diblokir.',
                text: (string) ($intentResult['clarification_question'] ?? 'Izin Bapak/Ibu, boleh dijelaskan lagi kebutuhan perjalanannya ya?'),
            );
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));
        $text = (string) ($reply['text'] ?? '');
        $crmContext = is_array($context['crm_context'] ?? null)
            ? $context['crm_context']
            : [];

        $hasOperationalFacts = ! empty($crmContext['booking'])
            || ! empty($crmContext['lead_pipeline'])
            || ! empty($crmContext['conversation'])
            || ! empty($crmContext['hubspot']);

        $hasGroundedKnowledge = ($context['faq_result']['matched'] ?? false) === true
            || ! empty($context['knowledge_hits'])
            || $hasOperationalFacts;

        if ($intent !== null && $this->isSensitiveOperationalIntent($intent) && ! $hasGroundedKnowledge) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mencoba menjawab intent operasional tanpa grounding yang aman.',
                text: $this->clarificationTextForIntent($intent),
            );
        }

        if ($this->containsSensitiveBusinessClaim($text) && ! $hasGroundedKnowledge) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mengandung klaim bisnis sensitif tanpa grounding yang aman.',
                text: 'Izin Bapak/Ibu, untuk detail promo, kebijakan, atau ketersediaan spesifik ini kami bantu cek dulu ke admin ya.',
            );
        }

        return $this->allow($reply, $intentResult);
    }

    private function isSensitiveOperationalIntent(IntentType $intent): bool
    {
        return in_array($intent, [
            IntentType::Booking,
            IntentType::BookingConfirm,
            IntentType::BookingCancel,
            IntentType::ScheduleInquiry,
            IntentType::PriceInquiry,
            IntentType::LocationInquiry,
            IntentType::TanyaKeberangkatanHariIni,
            IntentType::TanyaHarga,
            IntentType::TanyaRute,
            IntentType::TanyaJam,
            IntentType::KonfirmasiBooking,
            IntentType::UbahDataBooking,
        ], true);
    }

    private function containsSensitiveBusinessClaim(string $text): bool
    {
        $patterns = [
            '/\b(rp|rupiah|harga|ongkos|tarif)\b/iu',
            '/\b(jadwal|jam keberangkatan|slot|berangkat jam)\b/iu',
            '/\b(seat tersedia|kursi tersedia|masih kosong|seat kosong|seat [a-z0-9])/iu',
            '/\b(promo|diskon|cashback|potongan harga)\b/iu',
            '/\b(kebijakan|refund|reschedule|pembatalan|pelunasan|dp)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function clarificationTextForIntent(IntentType $intent): string
    {
        return match ($intent) {
            IntentType::TanyaHarga,
            IntentType::PriceInquiry
                => 'Izin Bapak/Ibu, untuk cek ongkos kami perlu titik jemput dan tujuan perjalanannya ya.',
            IntentType::TanyaJam,
            IntentType::ScheduleInquiry,
            IntentType::TanyaKeberangkatanHariIni
                => 'Izin Bapak/Ibu, untuk cek jadwal yang tepat mohon kirim rute atau tujuan perjalanannya ya.',
            IntentType::TanyaRute,
            IntentType::LocationInquiry
                => 'Izin Bapak/Ibu, titik jemput atau tujuan mana yang ingin dicek rutenya ya?',
            default
                => 'Izin Bapak/Ibu, mohon kirim detail perjalanan yang ingin dibantu supaya jawabannya tetap akurat ya.',
        };
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function clarify(array $reply, array $intentResult, string $reason, string $text): array
    {
        $intentResult['needs_clarification'] = true;
        $intentResult['clarification_question'] = $text;
        $intentResult['reasoning_short'] = $reason;

        return [
            'reply' => [
                'text' => $text,
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'guard.hallucination',
                    'action' => 'clarify_sensitive_request',
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'clarify',
                'blocked' => true,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function handoff(array $reply, array $intentResult, string $reason, string $text): array
    {
        $intentResult['intent'] = IntentType::HumanHandoff->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0.0), 0.95);
        $intentResult['handoff_recommended'] = true;
        $intentResult['needs_clarification'] = false;
        $intentResult['clarification_question'] = null;
        $intentResult['reasoning_short'] = $reason;

        return [
            'reply' => [
                'text' => $text,
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'guard.hallucination',
                    'action' => 'handoff_sensitive_request',
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'handoff',
                'blocked' => true,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function allow(array $reply, array $intentResult): array
    {
        return [
            'reply' => $reply,
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'allow',
                'blocked' => false,
                'reason' => null,
            ],
        ];
    }

    private function elevateRiskLevel(string $currentLevel, string $candidateLevel): string
    {
        $priority = [
            'low' => 1,
            'medium' => 2,
            'high' => 3,
        ];

        return ($priority[$candidateLevel] ?? 0) > ($priority[$currentLevel] ?? 0)
            ? $candidateLevel
            : $currentLevel;
    }
}
