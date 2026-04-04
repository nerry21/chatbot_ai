<?php

namespace App\Services\Chatbot\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;
use App\Support\WaLog;

class HallucinationGuardService
{
    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array{
     *     risk_level: string,
     *     risk_flags: array<int, string>,
     *     is_safe: bool,
     *     grounding_source?: string,
     *     crm_grounding_present?: bool,
     *     crm_grounding_sections?: array<int, string>
     * }
     */
    public function inspectGroundingRisk(
        array $replyResult,
        array $context,
        array $orchestrationSnapshot = [],
    ): array {
        $reply = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));
        $grounding = $this->groundingTelemetry($context);
        $booking = $grounding['crm_booking'];
        $conversation = $grounding['crm_conversation'];

        WaLog::debug('[HallucinationGuard] Grounding evaluated', [
            'grounding_source' => $grounding['grounding_source'],
            'crm_grounding_present' => $grounding['has_substantial_crm_facts'],
            'crm_grounding_sections' => $grounding['crm_grounding_sections'],
        ]);

        if ($grounding['is_operational_transition_reply'] && $this->looksLikeSafeHandoffReply($reply)) {
            return [
                'risk_level' => 'low',
                'risk_flags' => [],
                'is_safe' => true,
                'grounding_source' => 'crm_operational_state',
                'crm_grounding_present' => true,
                'crm_grounding_sections' => $grounding['crm_grounding_sections'],
            ];
        }

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
            if (! $grounding['has_substantial_crm_facts']) {
                $riskFlags[] = 'unsupported_operational_certainty';
                $riskLevel = $this->elevateRiskLevel($riskLevel, 'high');
            }
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

        if (
            ! $grounding['has_grounded_knowledge']
            && ! $grounding['has_substantial_crm_facts']
            && $this->looksFactualReply($reply)
        ) {
            $riskFlags[] = 'ungrounded_factual_reply';
            $riskLevel = $this->elevateRiskLevel($riskLevel, 'high');
        }

        return [
            'risk_level' => $riskLevel,
            'risk_flags' => array_values(array_unique($riskFlags)),
            'is_safe' => $riskLevel !== 'high',
            'grounding_source' => $grounding['grounding_source'],
            'crm_grounding_present' => $grounding['has_substantial_crm_facts'],
            'crm_grounding_sections' => $grounding['crm_grounding_sections'],
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
        $groundingMeta = [
            'grounding_source' => $riskReport['grounding_source'] ?? 'unknown',
            'crm_grounding_present' => (bool) ($riskReport['crm_grounding_present'] ?? false),
            'crm_grounding_sections' => is_array($riskReport['crm_grounding_sections'] ?? null)
                ? array_values($riskReport['crm_grounding_sections'])
                : [],
        ];

        if (($riskReport['is_safe'] ?? true) === true) {
            $replyResult['meta'] = array_merge(
                is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [],
                $groundingMeta,
            );

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
                'meta' => array_merge([
                    'force_handoff' => false,
                    'source' => 'hallucination_guard_missing_data_fallback',
                ], $groundingMeta),
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
            'meta' => array_merge([
                'force_handoff' => true,
                'source' => 'hallucination_guard_handoff_fallback',
            ], $groundingMeta),
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
        $faqResult = is_array($context['faq_result'] ?? null) ? $context['faq_result'] : [];
        $knowledgeHits = is_array($context['knowledge_hits'] ?? null) ? $context['knowledge_hits'] : [];

        $crmContext = is_array($context['crm_context'] ?? null)
            ? $context['crm_context']
            : [];

        $crmBooking = is_array($crmContext['booking'] ?? null)
            ? $crmContext['booking']
            : [];

        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmHubspot = is_array($crmContext['hubspot'] ?? null)
            ? $crmContext['hubspot']
            : [];

        $crmEscalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];

        $hasOperationalFacts = ! empty($crmBooking)
            || ! empty($crmLeadPipeline)
            || ! empty($crmConversation)
            || ! empty($crmHubspot)
            || ! empty($crmEscalation);

        $hasGroundedKnowledge = ($faqResult['matched'] ?? false) === true
            || ! empty($knowledgeHits)
            || $hasOperationalFacts;

        $hasSubstantialCrmFacts =
            ! empty($crmBooking['decision'] ?? null)
            || ! empty($crmBooking['status'] ?? null)
            || ! empty($crmLeadPipeline['stage'] ?? null)
            || ! empty($crmConversation['status'] ?? null)
            || (($crmConversation['admin_takeover'] ?? false) === true)
            || (($crmConversation['needs_human'] ?? false) === true)
            || (($crmEscalation['has_open_escalation'] ?? false) === true)
            || ! empty($crmHubspot['contact_id'] ?? null)
            || ! empty($crmHubspot['lifecycle_stage'] ?? null);

        $crmGroundingSections = array_keys(array_filter([
            'booking' => $crmBooking,
            'lead_pipeline' => $crmLeadPipeline,
            'conversation' => $crmConversation,
            'hubspot' => $crmHubspot,
            'escalation' => $crmEscalation,
        ], static fn ($value) => ! empty($value)));

        $groundingSource = $hasSubstantialCrmFacts
            ? 'crm_snapshot'
            : (($faqResult['matched'] ?? false) === true ? 'faq' : (! empty($knowledgeHits) ? 'knowledge_hits' : 'unknown'));

        $groundingMeta = [
            'grounding_source' => $groundingSource,
            'crm_grounding_present' => $hasSubstantialCrmFacts,
            'crm_grounding_sections' => $crmGroundingSections,
        ];

        $replyText = (string) ($reply['text'] ?? $reply['reply'] ?? '');
        $isOperationalTransitionReply =
            (($crmEscalation['has_open_escalation'] ?? false) === true)
            || (($crmConversation['admin_takeover'] ?? false) === true)
            || (($crmConversation['needs_human'] ?? false) === true)
            || $conversation->isAdminTakeover()
            || (($context['admin_takeover'] ?? false) === true);

        WaLog::debug('[HallucinationGuard] guardReply grounding', [
            'crm_grounding_present' => $hasSubstantialCrmFacts,
            'crm_grounding_sections' => $crmGroundingSections,
            'faq_grounding' => ($faqResult['matched'] ?? false) === true,
            'knowledge_hits_count' => count($knowledgeHits),
        ]);

        $source = (string) ($reply['meta']['source'] ?? '');
        if ($source !== 'ai_reply') {
            return $this->allow($reply, $intentResult, $groundingMeta);
        }

        if ($isOperationalTransitionReply && $this->looksLikeSafeHandoffReply($replyText)) {
            return $this->allow($reply, $intentResult, [
                'action' => 'allow_operational_transition',
                'grounding_source' => 'crm_operational_state',
                'crm_grounding_present' => true,
                'crm_grounding_sections' => $crmGroundingSections,
            ]);
        }

        if (($conversation->isAdminTakeover() || (($context['admin_takeover'] ?? false) === true))) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Admin takeover aktif; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, percakapan ini sedang ditangani admin ya.',
                meta: [
                    'grounding_source' => 'crm_operational_state',
                    'crm_grounding_present' => true,
                    'crm_grounding_sections' => $crmGroundingSections,
                ],
            );
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding meminta handoff; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya.',
                meta: $groundingMeta,
            );
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding masih ambigu; AI reply bebas diblokir.',
                text: (string) ($intentResult['clarification_question'] ?? 'Izin Bapak/Ibu, boleh dijelaskan lagi kebutuhan perjalanannya ya?'),
                meta: $groundingMeta,
            );
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

        if ($intent !== null && $this->isSensitiveOperationalIntent($intent) && ! $hasGroundedKnowledge) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mencoba menjawab intent operasional tanpa grounding yang aman.',
                text: $this->clarificationTextForIntent($intent),
                meta: [
                    'grounding_source' => $hasSubstantialCrmFacts ? 'crm_snapshot' : 'none',
                    'crm_grounding_present' => $hasSubstantialCrmFacts,
                    'crm_grounding_sections' => $crmGroundingSections,
                ],
            );
        }

        if ($this->containsSensitiveBusinessClaim($replyText) && ! $hasGroundedKnowledge) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mengandung klaim bisnis sensitif tanpa grounding yang aman.',
                text: 'Izin Bapak/Ibu, untuk detail promo, kebijakan, atau ketersediaan spesifik ini kami bantu cek dulu ke admin ya.',
                meta: [
                    'grounding_source' => $hasSubstantialCrmFacts ? 'crm_snapshot' : 'none',
                    'crm_grounding_present' => $hasSubstantialCrmFacts,
                    'crm_grounding_sections' => $crmGroundingSections,
                ],
            );
        }

        if (! $hasGroundedKnowledge && ! $hasSubstantialCrmFacts && $this->looksFactualReply($replyText)) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI tampak faktual tetapi belum memiliki grounding yang aman.',
                text: 'Izin Bapak/Ibu, agar informasinya tetap akurat kami bantu cek dulu ke admin ya.',
                meta: [
                    'grounding_source' => 'none',
                    'crm_grounding_present' => false,
                    'crm_grounding_sections' => $crmGroundingSections,
                ],
            );
        }

        return $this->allow($reply, $intentResult, $groundingMeta);
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

    private function looksLikeSafeHandoffReply(?string $replyText): bool
    {
        $text = mb_strtolower(trim((string) $replyText), 'UTF-8');

        if ($text === '') {
            return false;
        }

        $patterns = [
            'admin',
            'petugas',
            'tim kami',
            'teruskan',
            'follow up',
            'mohon tunggu',
            'akan dibantu',
            'human',
            'eskalasi',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function looksFactualReply(?string $replyText): bool
    {
        $text = mb_strtolower(trim((string) $replyText), 'UTF-8');

        if ($text === '' || $this->looksLikeSafeHandoffReply($replyText)) {
            return false;
        }

        foreach ([
            'jadwal',
            'harga',
            'tarif',
            'kursi',
            'seat',
            'tersedia',
            'promo',
            'refund',
            'reschedule',
            'dikonfirmasi',
            'diproses',
            'berangkat',
            'slot',
        ] as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return preg_match('/\b(rp|rupiah|\d{1,2}[:.]\d{2}|\d+\s*(seat|kursi|orang))\b/iu', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $meta
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    private function clarify(array $reply, array $intentResult, string $reason, string $text, array $meta = []): array
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
            'meta' => array_merge([
                'guard_group' => 'hallucination',
                'action' => 'clarify',
                'blocked' => true,
                'reason' => $reason,
            ], $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $meta
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    private function handoff(array $reply, array $intentResult, string $reason, string $text, array $meta = []): array
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
            'meta' => array_merge([
                'guard_group' => 'hallucination',
                'action' => 'handoff',
                'blocked' => true,
                'reason' => $reason,
            ], $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $meta
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    private function allow(array $reply, array $intentResult, array $meta = []): array
    {
        return [
            'reply' => $reply,
            'intent_result' => $intentResult,
            'meta' => array_merge([
                'guard_group' => 'hallucination',
                'action' => 'allow',
                'blocked' => false,
                'reason' => null,
            ], $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     faq_result: array<string, mixed>,
     *     knowledge_hits: array<int, mixed>,
     *     crm_booking: array<string, mixed>,
     *     crm_lead_pipeline: array<string, mixed>,
     *     crm_conversation: array<string, mixed>,
     *     crm_hubspot: array<string, mixed>,
     *     crm_escalation: array<string, mixed>,
     *     has_grounded_knowledge: bool,
     *     has_substantial_crm_facts: bool,
     *     grounding_source: string,
     *     crm_grounding_sections: array<int, string>,
     *     is_operational_transition_reply: bool
     * }
     */
    private function groundingTelemetry(array $context): array
    {
        $faqResult = is_array($context['faq_result'] ?? null) ? $context['faq_result'] : [];
        $knowledgeHits = is_array($context['knowledge_hits'] ?? null) ? $context['knowledge_hits'] : [];

        $crmContext = is_array($context['crm_context'] ?? null)
            ? $context['crm_context']
            : [];

        $crmBooking = is_array($crmContext['booking'] ?? null)
            ? $crmContext['booking']
            : [];

        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmHubspot = is_array($crmContext['hubspot'] ?? null)
            ? $crmContext['hubspot']
            : [];

        $crmEscalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];

        $hasOperationalFacts = ! empty($crmBooking)
            || ! empty($crmLeadPipeline)
            || ! empty($crmConversation)
            || ! empty($crmHubspot)
            || ! empty($crmEscalation);

        $hasGroundedKnowledge = ($faqResult['matched'] ?? false) === true
            || ! empty($knowledgeHits)
            || $hasOperationalFacts;

        $hasSubstantialCrmFacts =
            ! empty($crmBooking['decision'] ?? null)
            || ! empty($crmBooking['status'] ?? null)
            || ! empty($crmLeadPipeline['stage'] ?? null)
            || ! empty($crmConversation['status'] ?? null)
            || (($crmConversation['admin_takeover'] ?? false) === true)
            || (($crmConversation['needs_human'] ?? false) === true)
            || (($crmEscalation['has_open_escalation'] ?? false) === true)
            || ! empty($crmHubspot['contact_id'] ?? null)
            || ! empty($crmHubspot['lifecycle_stage'] ?? null);

        $crmGroundingSections = array_keys(array_filter([
            'booking' => $crmBooking,
            'lead_pipeline' => $crmLeadPipeline,
            'conversation' => $crmConversation,
            'hubspot' => $crmHubspot,
            'escalation' => $crmEscalation,
        ], static fn ($value) => ! empty($value)));

        $groundingSource = $hasSubstantialCrmFacts
            ? 'crm_snapshot'
            : (($faqResult['matched'] ?? false) === true ? 'faq' : (! empty($knowledgeHits) ? 'knowledge_hits' : 'unknown'));

        return [
            'faq_result' => $faqResult,
            'knowledge_hits' => $knowledgeHits,
            'crm_booking' => $crmBooking,
            'crm_lead_pipeline' => $crmLeadPipeline,
            'crm_conversation' => $crmConversation,
            'crm_hubspot' => $crmHubspot,
            'crm_escalation' => $crmEscalation,
            'has_grounded_knowledge' => $hasGroundedKnowledge,
            'has_substantial_crm_facts' => $hasSubstantialCrmFacts,
            'grounding_source' => $groundingSource,
            'crm_grounding_sections' => $crmGroundingSections,
            'is_operational_transition_reply' =>
                (($crmEscalation['has_open_escalation'] ?? false) === true)
                || (($crmConversation['admin_takeover'] ?? false) === true)
                || (($crmConversation['needs_human'] ?? false) === true),
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
