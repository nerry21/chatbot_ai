<?php

namespace App\Services\Chatbot\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;
use App\Services\AI\RuleEngineService;
use App\Support\WaLog;

class HallucinationGuardService
{
    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array{
     *     verdict: string,
     *     risk_level: string,
     *     risk_flags: array<int, string>,
     *     is_safe: bool,
     *     needs_clarification: bool,
     *     handoff_required: bool,
     *     grounding_source?: string,
     *     crm_grounding_present?: bool,
     *     crm_grounding_sections?: array<int, string>,
     *     used_crm_facts?: array<int, string>,
     *     decision_trace_grounding?: array<string, mixed>
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
                'verdict' => 'grounded',
                'risk_level' => 'low',
                'risk_flags' => [],
                'is_safe' => true,
                'needs_clarification' => false,
                'handoff_required' => false,
                'grounding_source' => 'crm_operational_state',
                'crm_grounding_present' => true,
                'crm_grounding_sections' => $grounding['crm_grounding_sections'],
                'used_crm_facts' => array_values(array_unique(array_map(
                    static fn (string $section): string => 'crm.'.$section,
                    $grounding['crm_grounding_sections'],
                ))),
                'decision_trace_grounding' => $this->groundingDecisionTrace(
                    traceId: $this->resolveTraceId($replyResult, $context, $orchestrationSnapshot),
                    verdict: 'grounded',
                    riskLevel: 'low',
                    riskFlags: [],
                    isSafe: true,
                    needsClarification: false,
                    handoffRequired: false,
                    groundingSource: 'crm_operational_state',
                    crmGroundingPresent: true,
                    crmGroundingSections: $grounding['crm_grounding_sections'],
                    usedCrmFacts: array_values(array_unique(array_map(
                        static fn (string $section): string => 'crm.'.$section,
                        $grounding['crm_grounding_sections'],
                    ))),
                ),
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

        $normalizedRiskFlags = array_values(array_unique($riskFlags));
        $verdict = $this->groundingVerdictFromRisk($riskLevel, $normalizedRiskFlags);
        $needsClarification = in_array($verdict, ['partially_grounded', 'needs_clarification'], true);
        $handoffRequired = $verdict === 'not_grounded';
        $usedCrmFacts = array_values(array_unique(array_map(
            static fn (string $section): string => 'crm.'.$section,
            $grounding['crm_grounding_sections'],
        )));

        return [
            'verdict' => $verdict,
            'risk_level' => $riskLevel,
            'risk_flags' => $normalizedRiskFlags,
            'is_safe' => $verdict !== 'not_grounded',
            'needs_clarification' => $needsClarification,
            'handoff_required' => $handoffRequired,
            'grounding_source' => $grounding['grounding_source'],
            'crm_grounding_present' => $grounding['has_substantial_crm_facts'],
            'crm_grounding_sections' => $grounding['crm_grounding_sections'],
            'used_crm_facts' => $usedCrmFacts,
            'decision_trace_grounding' => $this->groundingDecisionTrace(
                traceId: $this->resolveTraceId($replyResult, $context, $orchestrationSnapshot),
                verdict: $verdict,
                riskLevel: $riskLevel,
                riskFlags: $normalizedRiskFlags,
                isSafe: $verdict !== 'not_grounded',
                needsClarification: $needsClarification,
                handoffRequired: $handoffRequired,
                groundingSource: $grounding['grounding_source'],
                crmGroundingPresent: $grounding['has_substantial_crm_facts'],
                crmGroundingSections: $grounding['crm_grounding_sections'],
                usedCrmFacts: $usedCrmFacts,
            ),
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
                'reply' => 'Baik, saya bantu lanjutkan. Sebelum itu, mohon lengkapi data berikut terlebih dahulu: '.implode(', ', array_map([RuleEngineService::class, 'humanizeFieldName'], $booking['missing_fields'])).'.',
                'text' => 'Baik, saya bantu lanjutkan. Sebelum itu, mohon lengkapi data berikut terlebih dahulu: '.implode(', ', array_map([RuleEngineService::class, 'humanizeFieldName'], $booking['missing_fields'])).'.',
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
     *         verdict: string,
     *         blocked: bool,
     *         reason: string|null,
     *         force_handoff?: bool,
     *         force_clarification?: bool,
     *         grounding_source?: string|null,
     *         crm_grounding_present?: bool,
     *         crm_grounding_sections?: array<int, string>,
     *         used_crm_facts?: array<int, string>,
     *         decision_trace_hallucination?: array<string, mixed>
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

        $llmRuntimeBundle = is_array($context['llm_runtime'] ?? null) ? $context['llm_runtime'] : [];
        $understandingRuntime = is_array($llmRuntimeBundle['understanding'] ?? null) ? $llmRuntimeBundle['understanding'] : [];
        $replyDraftRuntime = is_array($llmRuntimeBundle['reply_draft'] ?? null) ? $llmRuntimeBundle['reply_draft'] : [];
        $groundedRuntime = is_array($llmRuntimeBundle['grounded_response'] ?? null) ? $llmRuntimeBundle['grounded_response'] : [];

        $crmContext = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $crmBooking = is_array($crmContext['booking'] ?? null) ? $crmContext['booking'] : [];
        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null) ? $crmContext['lead_pipeline'] : [];
        $crmConversation = is_array($crmContext['conversation'] ?? null) ? $crmContext['conversation'] : [];
        $crmHubspot = is_array($crmContext['hubspot'] ?? null) ? $crmContext['hubspot'] : [];
        $crmEscalation = is_array($crmContext['escalation'] ?? null) ? $crmContext['escalation'] : [];

        $hasOperationalFacts = ! empty($crmBooking)
            || ! empty($crmLeadPipeline)
            || ! empty($crmConversation)
            || ! empty($crmHubspot)
            || ! empty($crmEscalation);

        $crmGroundingSections = array_keys(array_filter([
            'booking' => $crmBooking,
            'lead_pipeline' => $crmLeadPipeline,
            'conversation' => $crmConversation,
            'hubspot' => $crmHubspot,
            'escalation' => $crmEscalation,
        ], static fn ($value) => ! empty($value)));

        $hasSubstantialCrmFacts =
            ! empty($crmBooking['decision'] ?? null)
            || ! empty($crmBooking['status'] ?? null)
            || ! empty($crmLeadPipeline['stage'] ?? null)
            || ! empty($crmConversation['status'] ?? null)
            || (($crmConversation['admin_takeover'] ?? false) === true)
            || (($crmConversation['needs_human'] ?? false) === true)
            || (($crmEscalation['has_open_escalation'] ?? false) === true)
            || ! empty($crmHubspot['contact_id'] ?? null);

        $groundingSource = $hasSubstantialCrmFacts
            ? 'crm_snapshot'
            : (($faqResult['matched'] ?? false) === true
                ? 'faq'
                : (! empty($knowledgeHits) ? 'knowledge_hits' : 'unknown'));

        $traceId = $this->resolveTraceId($reply, $intentResult, $context);

        $runtimeRisk = $this->assessRuntimeRisk(
            understandingRuntime: $understandingRuntime,
            replyDraftRuntime: $replyDraftRuntime,
            groundedRuntime: $groundedRuntime,
        );

        $groundingMeta = [
            'trace_id' => $traceId,
            'grounding_source' => $groundingSource,
            'crm_grounding_present' => $hasSubstantialCrmFacts,
            'crm_grounding_sections' => $crmGroundingSections,
            'used_crm_facts' => array_values(array_unique(array_map(
                static fn (string $section): string => 'crm.'.$section,
                $crmGroundingSections,
            ))),
            'llm_runtime' => [
                'understanding' => $understandingRuntime,
                'reply_draft' => $replyDraftRuntime,
                'grounded_response' => $groundedRuntime,
            ],
            'runtime_health' => $runtimeRisk['runtime_health'],
            'runtime_flags' => $runtimeRisk['runtime_flags'],
        ];

        $groundingRiskReport = $this->inspectGroundingRisk(
            replyResult: $reply,
            context: $context,
            orchestrationSnapshot: is_array($context['reply_orchestration'] ?? null) ? $context['reply_orchestration'] : [],
        );

        $groundingMeta['grounding_verdict'] = $groundingRiskReport['verdict'] ?? null;
        $groundingMeta['risk_level'] = $groundingRiskReport['risk_level'] ?? 'unknown';
        $groundingMeta['risk_flags'] = is_array($groundingRiskReport['risk_flags'] ?? null)
            ? array_values($groundingRiskReport['risk_flags'])
            : [];
        $groundingMeta['decision_trace_grounding'] = is_array($groundingRiskReport['decision_trace_grounding'] ?? null)
            ? $groundingRiskReport['decision_trace_grounding']
            : [];

        $replyText = trim((string) ($reply['text'] ?? $reply['reply'] ?? ''));
        $source = (string) ($reply['meta']['source'] ?? '');
        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? '')) ?? IntentType::Unknown;

        if (
            $this->looksFactualReply($replyText)
            && in_array($runtimeRisk['runtime_health'], ['fallback', 'schema_invalid', 'degraded'], true)
        ) {
            if ($hasSubstantialCrmFacts) {
                return $this->clarify(
                    reply: $reply,
                    intentResult: $intentResult,
                    reason: 'Runtime LLM tidak cukup sehat untuk factual reply tanpa klarifikasi tambahan.',
                    text: $this->clarificationTextForIntent($intent),
                    meta: [
                        ...$groundingMeta,
                        'action' => 'clarify_unhealthy_llm_runtime',
                        'risk_level' => 'high',
                    ],
                );
            }

            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Runtime LLM tidak sehat untuk menjawab permintaan faktual secara aman.',
                text: 'Izin Bapak/Ibu, agar informasi tetap akurat, percakapan ini kami teruskan ke admin terlebih dahulu ya.',
                meta: [
                    ...$groundingMeta,
                    'action' => 'handoff_unhealthy_llm_runtime',
                    'risk_level' => 'high',
                ],
            );
        }

        if ($source !== 'ai_reply') {
            return $this->allow($reply, $intentResult, [
                ...$groundingMeta,
                'action' => 'allow_non_ai_reply',
            ]);
        }

        if (($conversation->isAdminTakeover() || (($context['admin_takeover'] ?? false) === true))) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Admin takeover aktif; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, percakapan ini sedang ditangani admin ya.',
                meta: [
                    ...$groundingMeta,
                    'action' => 'handoff_admin_takeover',
                    'risk_level' => 'high',
                ],
            );
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding meminta handoff; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya.',
                meta: [
                    ...$groundingMeta,
                    'action' => 'handoff_understanding_request',
                    'risk_level' => 'high',
                ],
            );
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding masih ambigu; AI reply diblokir.',
                text: (string) ($intentResult['clarification_question'] ?? 'Izin Bapak/Ibu, boleh dijelaskan lagi kebutuhan perjalanannya ya?'),
                meta: [
                    ...$groundingMeta,
                    'action' => 'clarify_ambiguous_understanding',
                    'risk_level' => 'medium',
                ],
            );
        }

        if ($replyText === '') {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI kosong setelah orchestration.',
                text: 'Baik, saya bantu dulu ya. Boleh dijelaskan lagi kebutuhan Bapak/Ibu secara singkat?',
                meta: [
                    ...$groundingMeta,
                    'action' => 'clarify_empty_reply',
                    'risk_level' => 'medium',
                ],
            );
        }

        if (
            ($groundingRiskReport['verdict'] ?? null) === 'not_grounded'
            && $this->looksFactualReply($replyText)
        ) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply faktual tidak punya grounding yang cukup.',
                text: 'Baik, agar saya tidak keliru, boleh dijelaskan lagi detail kebutuhan atau pertanyaannya ya?',
                meta: [
                    ...$groundingMeta,
                    'action' => 'clarify_ungrounded_factual_reply',
                    'risk_level' => 'high',
                ],
            );
        }

        if (($groundingRiskReport['verdict'] ?? null) === 'partially_grounded') {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Grounding belum cukup kuat untuk menjawab secara langsung tanpa klarifikasi tambahan.',
                text: $this->clarificationTextForIntent($intent),
                meta: [
                    ...$groundingMeta,
                    'action' => 'clarify_partially_grounded_reply',
                    'risk_level' => $groundingRiskReport['risk_level'] ?? 'medium',
                ],
            );
        }

        return $this->allow($reply, $intentResult, [
            ...$groundingMeta,
            'grounding_verdict' => $groundingRiskReport['verdict'] ?? 'grounded',
            'action' => 'allow_grounded_reply',
            'risk_level' => $hasSubstantialCrmFacts ? 'low' : 'medium',
        ]);
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
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    private function buildHallucinationMeta(
        string $traceId,
        string $action,
        ?string $reason,
        bool $blocked,
        array $meta = [],
        array $reply = [],
    ): array {
        $verdict = match ($action) {
            'allow' => 'grounded',
            'clarify' => 'needs_clarification',
            'handoff' => 'not_grounded',
            default => $action,
        };

        return array_merge([
            'guard_group' => 'hallucination',
            'action' => $action,
            'verdict' => $verdict,
            'blocked' => $blocked,
            'reason' => $reason,
            'force_handoff' => $action === 'handoff',
            'force_clarification' => $action === 'clarify',
        ], $meta, [
            'decision_trace_hallucination' => $this->decisionTraceHallucination(
                traceId: $traceId,
                action: $action,
                blocked: $blocked,
                reason: $reason,
                meta: $meta,
                reply: $reply,
            ),
        ]);
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
                    'trace_id' => $meta['trace_id'] ?? null,
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                    'grounding_source' => $meta['grounding_source'] ?? null,
                    'grounding_verdict' => $meta['grounding_verdict'] ?? null,
                    'crm_grounding_present' => (bool) ($meta['crm_grounding_present'] ?? false),
                    'crm_grounding_sections' => is_array($meta['crm_grounding_sections'] ?? null)
                        ? array_values($meta['crm_grounding_sections'])
                        : [],
                    'used_crm_facts' => is_array($meta['used_crm_facts'] ?? null)
                        ? array_values($meta['used_crm_facts'])
                        : [],
                    'decision_trace' => $this->decisionTraceHallucination(
                        traceId: (string) ($meta['trace_id'] ?? $this->resolveTraceId($reply, $intentResult)),
                        action: 'clarify',
                        blocked: true,
                        reason: $reason,
                        meta: $meta,
                        reply: $reply,
                    ),
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => $this->buildHallucinationMeta(
                traceId: (string) ($meta['trace_id'] ?? $this->resolveTraceId($reply, $intentResult)),
                action: 'clarify',
                reason: $reason,
                blocked: true,
                meta: $meta,
                reply: $reply,
            ),
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
                    'trace_id' => $meta['trace_id'] ?? null,
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                    'force_handoff' => true,
                    'grounding_source' => $meta['grounding_source'] ?? null,
                    'grounding_verdict' => $meta['grounding_verdict'] ?? null,
                    'crm_grounding_present' => (bool) ($meta['crm_grounding_present'] ?? false),
                    'crm_grounding_sections' => is_array($meta['crm_grounding_sections'] ?? null)
                        ? array_values($meta['crm_grounding_sections'])
                        : [],
                    'used_crm_facts' => is_array($meta['used_crm_facts'] ?? null)
                        ? array_values($meta['used_crm_facts'])
                        : [],
                    'decision_trace' => $this->decisionTraceHallucination(
                        traceId: (string) ($meta['trace_id'] ?? $this->resolveTraceId($reply, $intentResult)),
                        action: 'handoff',
                        blocked: true,
                        reason: $reason,
                        meta: $meta,
                        reply: $reply,
                    ),
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => $this->buildHallucinationMeta(
                traceId: (string) ($meta['trace_id'] ?? $this->resolveTraceId($reply, $intentResult)),
                action: 'handoff',
                reason: $reason,
                blocked: true,
                meta: $meta,
                reply: $reply,
            ),
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
            'meta' => $this->buildHallucinationMeta(
                traceId: (string) ($meta['trace_id'] ?? $this->resolveTraceId($reply, $intentResult)),
                action: 'allow',
                reason: null,
                blocked: false,
                meta: $meta,
                reply: $reply,
            ),
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

    private function groundingVerdictFromRisk(string $riskLevel, array $riskFlags = []): string
    {
        if ($riskLevel === 'high') {
            if (in_array('ungrounded_factual_reply', $riskFlags, true)
                || in_array('unsupported_operational_certainty', $riskFlags, true)
                || in_array('booking_claim_while_data_incomplete', $riskFlags, true)
                || in_array('reply_conflicts_with_handoff', $riskFlags, true)) {
                return 'not_grounded';
            }

            return 'needs_clarification';
        }

        if ($riskLevel === 'medium') {
            return 'partially_grounded';
        }

        return 'grounded';
    }

    /**
     * @param  array<int, string>  $riskFlags
     * @param  array<int, string>  $crmGroundingSections
     * @param  array<int, string>  $usedCrmFacts
     * @return array<string, mixed>
     */
    private function groundingDecisionTrace(
        string $traceId,
        string $verdict,
        string $riskLevel,
        array $riskFlags = [],
        bool $isSafe = true,
        bool $needsClarification = false,
        bool $handoffRequired = false,
        ?string $groundingSource = null,
        bool $crmGroundingPresent = false,
        array $crmGroundingSections = [],
        array $usedCrmFacts = [],
    ): array {
        return [
            'trace_id' => $traceId,
            'grounding' => [
                'stage' => 'hallucination_guard',
                'verdict' => $verdict,
                'risk_level' => $riskLevel,
                'risk_flags' => array_values(array_unique($riskFlags)),
                'is_safe' => $isSafe,
                'needs_clarification' => $needsClarification,
                'handoff_required' => $handoffRequired,
                'grounding_source' => $groundingSource,
                'crm_grounding_present' => $crmGroundingPresent,
                'crm_grounding_sections' => array_values(array_unique($crmGroundingSections)),
                'used_crm_facts' => array_values(array_unique($usedCrmFacts)),
                'evaluated_at' => now()->toIso8601String(),
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

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $reply
     * @return array<string, mixed>
     */
    private function decisionTraceHallucination(
        string $traceId,
        string $action,
        bool $blocked,
        ?string $reason,
        array $meta = [],
        array $reply = [],
    ): array {
        $usedCrmFacts = [];

        foreach ([$meta['used_crm_facts'] ?? [], $reply['used_crm_facts'] ?? []] as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $fact) {
                if (! is_scalar($fact)) {
                    continue;
                }

                $text = trim((string) $fact);

                if ($text !== '') {
                    $usedCrmFacts[] = $text;
                }
            }
        }

        return [
            'trace_id' => $traceId,
            'grounding' => [
                'stage' => 'hallucination_guard',
                'action' => $action,
                'verdict' => match ($action) {
                    'allow' => 'grounded',
                    'clarify' => 'needs_clarification',
                    'handoff' => 'not_grounded',
                    default => $action,
                },
                'blocked' => $blocked,
                'reason' => $reason,
                'risk_level' => (string) ($meta['risk_level'] ?? 'unknown'),
                'grounding_source' => $meta['grounding_source'] ?? null,
                'crm_grounding_present' => (bool) ($meta['crm_grounding_present'] ?? false),
                'crm_grounding_sections' => is_array($meta['crm_grounding_sections'] ?? null)
                    ? array_values($meta['crm_grounding_sections'])
                    : [],
                'used_crm_facts' => array_values(array_unique($usedCrmFacts)),
                'evaluated_at' => now()->toIso8601String(),
            ],
            'outcome' => [
                'handoff' => $action === 'handoff',
                'clarify' => $action === 'clarify',
                'reply_action' => $action === 'clarify' ? 'ask_clarification' : ($action === 'handoff' ? 'handoff_admin' : 'allow'),
                'is_fallback' => (bool) ($reply['is_fallback'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $understandingRuntime
     * @param  array<string, mixed>  $replyDraftRuntime
     * @param  array<string, mixed>  $groundedRuntime
     * @return array{runtime_health: string, runtime_flags: array<int, string>}
     */
    private function assessRuntimeRisk(
        array $understandingRuntime = [],
        array $replyDraftRuntime = [],
        array $groundedRuntime = [],
    ): array {
        $flags = [];

        foreach ([
            'understanding' => $understandingRuntime,
            'reply_draft' => $replyDraftRuntime,
            'grounded_response' => $groundedRuntime,
        ] as $stage => $runtime) {
            if (! is_array($runtime) || $runtime === []) {
                continue;
            }

            if (($runtime['status'] ?? null) === 'fallback') {
                $flags[] = $stage.'_runtime_fallback';
            }

            if (($runtime['degraded_mode'] ?? false) === true) {
                $flags[] = $stage.'_runtime_degraded';
            }

            if (array_key_exists('schema_valid', $runtime) && ($runtime['schema_valid'] ?? true) === false) {
                $flags[] = $stage.'_schema_invalid';
            }

            if (($runtime['used_fallback_model'] ?? false) === true) {
                $flags[] = $stage.'_fallback_model';
            }
        }

        $health = 'healthy';

        if (
            in_array('understanding_runtime_fallback', $flags, true)
            || in_array('reply_draft_runtime_fallback', $flags, true)
            || in_array('grounded_response_runtime_fallback', $flags, true)
        ) {
            $health = 'fallback';
        } elseif (
            in_array('understanding_schema_invalid', $flags, true)
            || in_array('reply_draft_schema_invalid', $flags, true)
            || in_array('grounded_response_schema_invalid', $flags, true)
        ) {
            $health = 'schema_invalid';
        } elseif (
            in_array('understanding_runtime_degraded', $flags, true)
            || in_array('reply_draft_runtime_degraded', $flags, true)
            || in_array('grounded_response_runtime_degraded', $flags, true)
        ) {
            $health = 'degraded';
        } elseif (
            in_array('understanding_fallback_model', $flags, true)
            || in_array('reply_draft_fallback_model', $flags, true)
            || in_array('grounded_response_fallback_model', $flags, true)
        ) {
            $health = 'fallback_model';
        }

        if (count($flags) >= 4 && $health === 'healthy') {
            $health = 'unhealthy';
        }

        return [
            'runtime_health' => $health,
            'runtime_flags' => array_values(array_unique($flags)),
        ];
    }

    private function resolveTraceId(array ...$sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                $source['trace_id'] ?? null,
                $source['meta']['trace_id'] ?? null,
                $source['decision_trace']['trace_id'] ?? null,
                $source['job_trace_id'] ?? null,
            ] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return trim((string) $candidate);
                }
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }
}