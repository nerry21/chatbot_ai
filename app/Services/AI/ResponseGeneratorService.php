<?php

namespace App\Services\AI;

use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class ResponseGeneratorService
{
    /**
     * Fallback replies keyed by intent.
     * Used when LLM is disabled or the API call fails.
     *
     * @var array<string, string>
     */
    private const FALLBACK_REPLIES = [
        'greeting'        => 'Halo! Selamat datang di layanan transportasi kami. Ada yang bisa kami bantu untuk perjalanan Anda?',
        'booking'         => 'Baik, kami siap membantu pemesanan Anda. Bisa informasikan titik penjemputan, tujuan, tanggal, dan jam keberangkatan?',
        'booking_confirm' => 'Terima kasih atas konfirmasinya. Tim kami akan segera memproses.',
        'booking_cancel'  => 'Baik, kami mencatat permintaan pembatalan Anda. Tim kami akan segera menindaklanjuti.',
        'schedule_inquiry'=> 'Terima kasih atas pertanyaannya. Untuk informasi jadwal yang akurat, boleh kami tahu rute dan tanggal yang Anda inginkan?',
        'price_inquiry'   => 'Terima kasih atas pertanyaan harga. Kami akan sampaikan informasi tarif setelah mengetahui rute perjalanan Anda.',
        'location_inquiry'=> 'Kami memiliki banyak titik penjemputan. Bisa informasikan kota atau area asal Anda?',
        'support'         => 'Kami mohon maaf atas ketidaknyamanannya. Tim kami mencatat keluhan Anda dan akan segera menghubungi Anda.',
        'human_handoff'   => 'Baik, kami akan segera menghubungkan Anda dengan tim kami. Mohon tunggu sebentar.',
        'farewell'        => 'Terima kasih telah menghubungi kami. Semoga perjalanan Anda menyenangkan. Sampai jumpa!',
        'confirmation'    => 'Terima kasih atas konfirmasinya. Kami akan lanjutkan prosesnya.',
        'rejection'       => 'Baik, tidak masalah. Ada hal lain yang bisa kami bantu?',
        'out_of_scope'    => 'Maaf, kami hanya melayani pemesanan transportasi antar kota. Ada yang bisa kami bantu terkait perjalanan Anda?',
        'unknown'         => 'Maaf, kami belum memahami permintaan Anda. Bisa dijelaskan lebih detail agar kami bisa membantu dengan tepat?',
    ];

    /**
     * Contextual low-confidence fallbacks: slightly more helpful than generic fallbacks.
     * Used when intent confidence is below threshold AND no strong knowledge is available.
     *
     * @var array<string, string>
     */
    private const CONTEXTUAL_FALLBACKS = [
        'unknown' => 'Maaf, kami belum memahami dengan jelas maksud pesan Anda. Bisa Anda jelaskan lebih detail? Kami siap membantu dengan informasi perjalanan, harga, jadwal, atau pemesanan tiket.',
        'default' => 'Maaf, kami belum bisa memproses permintaan ini. Bisa Anda ulangi atau ceritakan lebih detail kebutuhan perjalanan Anda?',
    ];

    private const DEFAULT_FALLBACK = 'Terima kasih atas pesan Anda. Tim kami akan segera merespons.';

    /**
     * Booking-related intents that must not be intercepted by FAQ direct answer.
     * These intents go through the full booking flow.
     *
     * @var array<int, string>
     */
    private const BOOKING_INTENTS = ['booking', 'booking_confirm', 'booking_cancel'];

    public function __construct(
        private readonly LlmClientService          $llmClient,
        private readonly PromptBuilderService      $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * Generate a natural-language reply based on intent, entities, and context.
     *
     * Required context keys: message_text, conversation_id, message_id, intent_result.
     * Optional context keys: entity_result, customer_memory, active_states,
     *                        knowledge_hits, knowledge_block, faq_result.
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, is_fallback: bool, used_knowledge: bool, used_faq: bool}
     */
    public function generate(array $context): array
    {
        $intent           = $context['intent_result']['intent']     ?? 'unknown';
        $intentConfidence = (float) ($context['intent_result']['confidence'] ?? 1.0);
        $hasKnowledge     = ! empty($context['knowledge_hits']);
        $faqResult        = $context['faq_result'] ?? ['matched' => false, 'answer' => null];
        $isBookingRelated = in_array($intent, self::BOOKING_INTENTS, true);

        // ── 1. FAQ direct answer (skip for booking intents) ─────────────────
        // Only use local FAQ answer when the score is very high (FaqResolverService
        // enforces FAQ_DIRECT_MIN_SCORE = 8.0, so this is already conservative).
        if (
            ! $isBookingRelated
            && ($faqResult['matched'] ?? false)
            && ! empty($faqResult['answer'])
        ) {
            return [
                'text'         => $faqResult['answer'],
                'is_fallback'  => false,
                'used_knowledge' => true,
                'used_faq'     => true,
            ];
        }

        // ── 2. Low-confidence contextual fallback ────────────────────────────
        // Applied only when: config opt-in, confidence is below threshold, AND
        // no knowledge context is available to ground the LLM's answer.
        $lowConfThreshold     = (float) config('chatbot.ai_quality.low_confidence_threshold', 0.40);
        $fallbackOnLowConf    = (bool)  config('chatbot.ai_quality.reply_fallback_on_low_confidence', false);

        if (
            $fallbackOnLowConf
            && $intentConfidence <= $lowConfThreshold
            && ! $hasKnowledge
        ) {
            return $this->buildContextualFallback($intent);
        }

        // ── 3. Normal LLM path ───────────────────────────────────────────────
        try {
            $prompts = $this->promptBuilder->buildReplyPrompt($context);

            $llmContext = array_merge($context, [
                'system' => $prompts['system'],
                'user'   => $prompts['user'],
                'model'  => config('chatbot.llm.models.reply'),
            ]);

            $raw  = $this->llmClient->generateReply($llmContext);
            $text = trim($raw['text'] ?? '');

            if ($text === '') {
                return $this->buildFallback($intent);
            }

            return [
                'text'         => $text,
                'is_fallback'  => (bool) ($raw['is_fallback'] ?? false),
                'used_knowledge' => $hasKnowledge && config('chatbot.knowledge.include_in_reply_tasks', true),
                'used_faq'     => false,
            ];
        } catch (\Throwable $e) {
            Log::error('ResponseGeneratorService: unexpected error', ['error' => $e->getMessage()]);
            return $this->buildFallback($intent);
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Standard hardcoded fallback when LLM fails or returns empty.
     *
     * @return array{text: string, is_fallback: bool, used_knowledge: bool, used_faq: bool}
     */
    private function buildFallback(string $intent): array
    {
        return [
            'text'          => self::FALLBACK_REPLIES[$intent] ?? self::DEFAULT_FALLBACK,
            'is_fallback'   => true,
            'used_knowledge' => false,
            'used_faq'      => false,
        ];
    }

    /**
     * Contextual fallback for low-confidence situations.
     * More helpful than generic fallback — guides the customer without inventing data.
     *
     * @return array{text: string, is_fallback: bool, used_knowledge: bool, used_faq: bool}
     */
    private function buildContextualFallback(string $intent): array
    {
        $text = self::CONTEXTUAL_FALLBACKS[$intent]
            ?? self::CONTEXTUAL_FALLBACKS['default'];

        return [
            'text'          => $text,
            'is_fallback'   => true,
            'used_knowledge' => false,
            'used_faq'      => false,
        ];
    }
}
