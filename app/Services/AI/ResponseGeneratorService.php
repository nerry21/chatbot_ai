<?php

namespace App\Services\AI;

use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class ResponseGeneratorService
{
    /**
     * Fallback replies keyed by intent.
     *
     * @var array<string, string>
     */
    private const FALLBACK_REPLIES = [
        'greeting' => 'Halo, saya bantu ya. Mau cek jadwal, harga, atau lanjut booking travel?',
        'salam_islam' => 'Waalaikumsalam warahmatullahi wabarakatuh. Ada yang bisa kami bantu, Bapak/Ibu?',
        'booking' => 'Baik, saya bantu bookingnya ya. Mohon kirim titik jemput, tujuan, tanggal, dan jam keberangkatannya.',
        'booking_confirm' => 'Baik, konfirmasinya sudah masuk ya. Kami lanjut proses bookingnya.',
        'booking_cancel' => 'Baik, permintaan pembatalannya sudah kami catat ya. Nanti kami bantu tindak lanjuti.',
        'schedule_inquiry' => 'Siap, saya bantu cek jadwal ya. Boleh kirim rute dan tanggal keberangkatannya dulu?',
        'price_inquiry' => 'Baik, saya bantu cek harganya ya. Mohon kirim titik jemput dan tujuan dulu.',
        'location_inquiry' => 'Untuk lokasi yang ingin dicek, boleh kirim titik jemput atau tujuan perjalanannya ya?',
        'tanya_keberangkatan_hari_ini' => 'Baik, saya bantu info keberangkatan hari ini ya. Mohon tunggu sebentar.',
        'tanya_harga' => 'Baik, saya bantu cek ongkosnya ya. Mohon kirim lokasi jemput dan tujuan dulu.',
        'tanya_rute' => 'Baik, saya bantu cek rutenya ya. Boleh kirim titik jemput atau tujuan yang ingin dicek?',
        'tanya_jam' => 'Baik, saya bantu info jam keberangkatannya ya.',
        'konfirmasi_booking' => 'Baik, konfirmasi bookingnya sudah kami terima ya.',
        'ubah_data_booking' => 'Baik, data bookingnya bisa disesuaikan. Silakan kirim bagian yang ingin diubah ya.',
        'pertanyaan_tidak_terjawab' => 'Izin Bapak/Ibu, pertanyaannya akan kami konsultasikan dulu ke admin ya.',
        'close_intent' => 'Baik, terima kasih ya. Kalau ingin cek jadwal atau lanjut booking, silakan chat lagi.',
        'support' => 'Mohon maaf ya atas kendalanya. Boleh ceritakan singkat masalahnya, nanti kami bantu lanjutkan.',
        'human_handoff' => 'Baik, saya teruskan ke admin ya. Mohon tunggu sebentar.',
        'farewell' => 'Baik, terima kasih ya. Kalau nanti mau cek jadwal atau lanjut booking, tinggal chat lagi.',
        'confirmation' => 'Siap, konfirmasinya sudah saya catat ya. Saya lanjutkan prosesnya.',
        'rejection' => 'Baik, tidak masalah ya. Kalau ada yang mau diubah atau dicek lagi, tinggal kirim saja.',
        'out_of_scope' => 'Mohon maaf, untuk saat ini kami fokus di layanan travel antar kota. Kalau mau cek jadwal, harga, atau booking, saya bantu.',
        'unknown' => 'Baik, saya bantu ya. Boleh jelaskan lagi kebutuhan perjalanannya, misalnya rute, tanggal, atau jadwal yang ingin dicek?',
    ];

    /**
     * @var array<string, string>
     */
    private const CONTEXTUAL_FALLBACKS = [
        'unknown' => 'Baik, saya belum menangkap detailnya dengan jelas. Boleh jelaskan lagi kebutuhan perjalanannya? Misalnya rute, tanggal, jadwal, atau booking yang ingin dicek.',
        'default' => 'Baik, saya bantu ya. Coba kirim lagi detail perjalanan yang ingin dicek, nanti saya lanjut bantu.',
    ];

    /**
     * @var array<int, string>
     */
    private const BOOKING_INTENTS = [
        'booking',
        'booking_confirm',
        'booking_cancel',
        'konfirmasi_booking',
        'ubah_data_booking',
    ];

    public function __construct(
        private readonly LlmClientService $llmClient,
        private readonly PromptBuilderService $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    public function generate(array $context, array $intentResult = []): array
    {
        $intentResult = $intentResult !== []
            ? $intentResult
            : (is_array($context['intent_result'] ?? null) ? $context['intent_result'] : []);

        $replyInput = $this->buildReplyInput($context, $intentResult);
        $intent = (string) ($intentResult['intent'] ?? 'unknown');
        $intentConfidence = (float) ($intentResult['confidence'] ?? 1.0);
        $hasKnowledge = ! empty($context['knowledge_hits']);
        $faqResult = is_array($context['faq_result'] ?? null) ? $context['faq_result'] : ['matched' => false, 'answer' => null];
        $isBookingRelated = in_array($intent, self::BOOKING_INTENTS, true);
        $replyUsesKnowledge = $hasKnowledge && config('chatbot.knowledge.include_in_reply_tasks', true);

        if (
            ! $isBookingRelated
            && ($faqResult['matched'] ?? false)
            && ! empty($faqResult['answer'])
            && ! $this->shouldUseSensitiveFallback($replyInput, $intentResult)
        ) {
            $normalized = $this->normalizeReplyResult([
                'reply' => (string) $faqResult['answer'],
                'tone' => 'ramah',
                'should_escalate' => false,
                'handoff_reason' => null,
                'next_action' => 'answer_question',
                'data_requests' => [],
                'used_crm_facts' => [],
                'safety_notes' => ['FAQ direct answer used'],
            ], $replyInput, $intentResult);

            return $this->finalizeReplyResult($normalized, false, true, true);
        }

        $lowConfThreshold = (float) config('chatbot.ai_quality.low_confidence_threshold', 0.40);
        $fallbackOnLowConf = (bool) config('chatbot.ai_quality.reply_fallback_on_low_confidence', false);

        if (
            $fallbackOnLowConf
            && $intentConfidence <= $lowConfThreshold
            && ! $hasKnowledge
        ) {
            $payload = $this->shouldUseSensitiveFallback($replyInput, $intentResult)
                ? $this->buildSensitiveFallbackReply($replyInput)
                : $this->buildContextualFallbackPayload($intent);

            $normalized = $this->normalizeReplyResult($payload, $replyInput, $intentResult);
            $normalized = $this->applyAdminTakeoverGuard($normalized, $replyInput);

            return $this->finalizeReplyResult($normalized, true, false, false);
        }

        try {
            $prompts = $this->promptBuilder->buildReplyPrompt($replyInput);

            $llmContext = array_merge($context, $replyInput, [
                'system' => $prompts['system'],
                'user' => $prompts['user'],
                'model' => config('chatbot.llm.models.reply'),
            ]);

            $raw = $this->llmClient->generateReply($llmContext);

            if (! is_array($raw) || $raw === []) {
                return $this->fallbackFromInvalidModelResult($replyInput, $intentResult);
            }

            $structured = $this->extractStructuredReplyPayload($raw);

            if ($structured === []) {
                return $this->fallbackFromInvalidModelResult($replyInput, $intentResult);
            }

            $normalized = $this->normalizeReplyResult($structured, $replyInput, $intentResult);
            $normalized = $this->applyAdminTakeoverGuard($normalized, $replyInput);

            return $this->finalizeReplyResult(
                $normalized,
                (bool) ($raw['is_fallback'] ?? false),
                $replyUsesKnowledge,
                false,
            );
        } catch (\Throwable $e) {
            Log::error('ResponseGeneratorService: unexpected error', ['error' => $e->getMessage()]);

            $payload = $this->shouldUseSensitiveFallback($replyInput, $intentResult)
                ? $this->buildSensitiveFallbackReply($replyInput)
                : $this->buildFallbackPayload($intent);

            $normalized = $this->normalizeReplyResult($payload, $replyInput, $intentResult);
            $normalized = $this->applyAdminTakeoverGuard($normalized, $replyInput);

            return $this->finalizeReplyResult($normalized, true, false, false);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function normalizeReplyResult(array $result, array $context = [], array $intentResult = []): array
    {
        $reply = trim((string) ($result['reply'] ?? ''));
        $tone = trim((string) ($result['tone'] ?? 'ramah'));
        $shouldEscalate = (bool) ($result['should_escalate'] ?? false);
        $handoffReason = $result['handoff_reason'] ?? null;
        $nextAction = trim((string) ($result['next_action'] ?? 'answer_question'));
        $dataRequests = is_array($result['data_requests'] ?? null) ? $result['data_requests'] : [];
        $usedCrmFacts = is_array($result['used_crm_facts'] ?? null) ? $result['used_crm_facts'] : [];
        $safetyNotes = is_array($result['safety_notes'] ?? null) ? $result['safety_notes'] : [];
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];

        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];

        if ($reply === '') {
            $reply = 'Baik, saya bantu dulu ya. Mohon beri sedikit detail tambahan agar saya bisa menindaklanjuti dengan tepat.';
            $safetyNotes[] = 'Fallback empty reply used';
        }

        if (
            ($conversation['needs_human'] ?? false) === true
            || ($flags['needs_human_followup'] ?? false) === true
            || ($escalation['has_open_escalation'] ?? false) === true
            || (($intentResult['should_escalate'] ?? false) === true)
        ) {
            $shouldEscalate = true;
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            if ($nextAction === '' || $nextAction === 'answer_question') {
                $nextAction = 'ask_missing_data';
            }

            if ($dataRequests === []) {
                $dataRequests = array_values($booking['missing_fields']);
            }
        }

        if (($flags['admin_takeover_active'] ?? false) === true && $handoffReason === null) {
            $handoffReason = 'Admin takeover active';
        }

        return [
            'reply' => $reply,
            'tone' => $tone !== '' ? $tone : 'ramah',
            'should_escalate' => $shouldEscalate,
            'handoff_reason' => $handoffReason,
            'next_action' => $nextAction !== '' ? $nextAction : 'answer_question',
            'data_requests' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $dataRequests,
            )))),
            'used_crm_facts' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $usedCrmFacts,
            )))),
            'safety_notes' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $safetyNotes,
            )))),
            'meta' => array_merge($meta, [
                'force_handoff' => $shouldEscalate,
                'source' => $meta['source'] ?? 'llm_reply_with_crm_context',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildSensitiveFallbackReply(array $context = []): array
    {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];

        $reply = 'Baik, agar penanganannya lebih tepat, percakapan ini akan saya teruskan ke admin kami ya.';

        if (($conversation['needs_human'] ?? false) === true) {
            $reply = 'Baik, untuk membantu Anda dengan lebih tepat, percakapan ini akan saya teruskan ke admin kami ya.';
        }

        return [
            'reply' => $reply,
            'tone' => 'empatik',
            'should_escalate' => true,
            'handoff_reason' => 'Sensitive or human-required case',
            'next_action' => 'handoff_admin',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => ['Sensitive fallback reply used'],
            'meta' => [
                'force_handoff' => true,
                'source' => 'sensitive_fallback',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function buildReplyInput(array $context, array $intentResult): array
    {
        $messageText = trim((string) ($context['message_text'] ?? $context['latest_message'] ?? ''));
        $recentMessages = $this->normalizePromptMessages(
            is_array($context['recent_messages'] ?? null)
                ? $context['recent_messages']
                : (is_array($context['context_messages'] ?? null) ? $context['context_messages'] : [])
        );
        $activeStates = is_array($context['active_states'] ?? null)
            ? $context['active_states']
            : (is_array($context['conversation_state'] ?? null) ? $context['conversation_state'] : []);

        return [
            'message_text' => $messageText,
            'latest_message' => $messageText,
            'recent_messages' => $recentMessages,
            'context_messages' => $recentMessages,
            'conversation_summary' => $context['conversation_summary'] ?? null,
            'customer_memory' => is_array($context['customer_memory'] ?? null) ? $context['customer_memory'] : [],
            'crm_context' => is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [],
            'active_states' => $activeStates,
            'conversation_state' => $activeStates,
            'known_entities' => is_array($context['known_entities'] ?? null) ? $context['known_entities'] : [],
            'resolved_context' => is_array($context['resolved_context'] ?? null) ? $context['resolved_context'] : [],
            'intent_result' => $intentResult,
            'understanding_result' => is_array($context['understanding_result'] ?? null) ? $context['understanding_result'] : [],
            'entity_result' => is_array($context['entity_result'] ?? null) ? $context['entity_result'] : [],
            'knowledge_hits' => is_array($context['knowledge_hits'] ?? null) ? $context['knowledge_hits'] : [],
            'knowledge_block' => $context['knowledge_block'] ?? null,
            'faq_result' => is_array($context['faq_result'] ?? null) ? $context['faq_result'] : [],
            'admin_takeover' => (bool) ($context['admin_takeover'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function extractStructuredReplyPayload(array $raw): array
    {
        if (
            array_key_exists('reply', $raw)
            || array_key_exists('should_escalate', $raw)
            || array_key_exists('next_action', $raw)
        ) {
            return $raw;
        }

        $text = trim((string) ($raw['text'] ?? ''));

        if ($text === '') {
            return [];
        }

        $decoded = $this->validator->decodeAndValidate($text);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'reply' => $text,
            'tone' => 'ramah',
            'should_escalate' => false,
            'handoff_reason' => null,
            'next_action' => 'answer_question',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => ['Model returned unstructured text'],
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function finalizeReplyResult(
        array $normalized,
        bool $isFallback,
        bool $usedKnowledge,
        bool $usedFaq,
    ): array {
        return array_merge($normalized, [
            'text' => (string) ($normalized['reply'] ?? ''),
            'is_fallback' => $isFallback,
            'used_knowledge' => $usedKnowledge,
            'used_faq' => $usedFaq,
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $replyInput
     * @return array<string, mixed>
     */
    private function applyAdminTakeoverGuard(array $normalized, array $replyInput): array
    {
        if (($replyInput['admin_takeover'] ?? false) !== true) {
            return $normalized;
        }

        $normalized['should_escalate'] = true;
        $normalized['meta']['force_handoff'] = true;

        if (empty($normalized['handoff_reason'])) {
            $normalized['handoff_reason'] = 'Admin takeover active';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $replyInput
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function fallbackFromInvalidModelResult(array $replyInput, array $intentResult): array
    {
        $payload = $this->shouldUseSensitiveFallback($replyInput, $intentResult)
            ? $this->buildSensitiveFallbackReply($replyInput)
            : [
                'reply' => 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.',
                'tone' => 'ramah',
                'should_escalate' => false,
                'handoff_reason' => null,
                'next_action' => 'safe_fallback',
                'data_requests' => [],
                'used_crm_facts' => [],
                'safety_notes' => ['Model result invalid, fallback applied'],
            ];

        $normalized = $this->normalizeReplyResult($payload, $replyInput, $intentResult);
        $normalized = $this->applyAdminTakeoverGuard($normalized, $replyInput);

        return $this->finalizeReplyResult($normalized, true, false, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFallbackPayload(string $intent): array
    {
        return [
            'reply' => self::FALLBACK_REPLIES[$intent] ?? self::FALLBACK_REPLIES['unknown'],
            'tone' => 'ramah',
            'should_escalate' => false,
            'handoff_reason' => null,
            'next_action' => 'safe_fallback',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => ['Template fallback used'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContextualFallbackPayload(string $intent): array
    {
        $reply = self::CONTEXTUAL_FALLBACKS[$intent]
            ?? self::CONTEXTUAL_FALLBACKS['default'];

        return [
            'reply' => $reply,
            'tone' => 'ramah',
            'should_escalate' => false,
            'handoff_reason' => null,
            'next_action' => 'safe_fallback',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => ['Low confidence contextual fallback used'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     */
    private function shouldUseSensitiveFallback(array $context, array $intentResult): bool
    {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];

        return ($context['admin_takeover'] ?? false) === true
            || ($conversation['needs_human'] ?? false) === true
            || ($flags['needs_human_followup'] ?? false) === true
            || ($escalation['has_open_escalation'] ?? false) === true
            || (($intentResult['should_escalate'] ?? false) === true);
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizePromptMessages(array $messages): array
    {
        return array_values(array_filter(array_map(function ($message): ?array {
            if (! is_array($message)) {
                return null;
            }

            $text = trim((string) ($message['text'] ?? ''));
            $direction = trim((string) ($message['direction'] ?? ''));
            $role = trim((string) ($message['role'] ?? ''));

            if ($direction === '' && $role !== '') {
                $direction = $role === 'customer' || $role === 'user'
                    ? 'inbound'
                    : 'outbound';
            }

            return [
                'direction' => $direction !== '' ? $direction : 'inbound',
                'text' => $text,
                'sent_at' => isset($message['sent_at']) ? (string) $message['sent_at'] : null,
            ];
        }, $messages)));
    }
}
