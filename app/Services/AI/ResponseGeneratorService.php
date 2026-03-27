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
     * Contextual low-confidence fallbacks: slightly more helpful than generic fallbacks.
     * Used when intent confidence is below threshold AND no strong knowledge is available.
     *
     * @var array<string, string>
     */
    private const CONTEXTUAL_FALLBACKS = [
        'unknown' => 'Baik, saya belum menangkap detailnya dengan jelas. Boleh jelaskan lagi kebutuhan perjalanannya? Misalnya rute, tanggal, jadwal, atau booking yang ingin dicek.',
        'default' => 'Baik, saya bantu ya. Coba kirim lagi detail perjalanan yang ingin dicek, nanti saya lanjut bantu.',
    ];

    private const DEFAULT_FALLBACK = 'Baik, pesan Anda sudah masuk ya. Silakan kirim detail perjalanan yang ingin dicek, nanti kami bantu.';

    /**
     * Booking-related intents that must not be intercepted by FAQ direct answer.
     * These intents go through the full booking flow.
     *
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
        $intent = $context['intent_result']['intent'] ?? 'unknown';
        $intentConfidence = (float) ($context['intent_result']['confidence'] ?? 1.0);
        $hasKnowledge = ! empty($context['knowledge_hits']);
        $faqResult = $context['faq_result'] ?? ['matched' => false, 'answer' => null];
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
                'text' => $faqResult['answer'],
                'is_fallback' => false,
                'used_knowledge' => true,
                'used_faq' => true,
            ];
        }

        // ── 2. Low-confidence contextual fallback ────────────────────────────
        // Applied only when: config opt-in, confidence is below threshold, AND
        // no knowledge context is available to ground the LLM's answer.
        $lowConfThreshold = (float) config('chatbot.ai_quality.low_confidence_threshold', 0.40);
        $fallbackOnLowConf = (bool) config('chatbot.ai_quality.reply_fallback_on_low_confidence', false);

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
                'user' => $prompts['user'],
                'model' => config('chatbot.llm.models.reply'),
            ]);

            $raw = $this->llmClient->generateReply($llmContext);
            $text = trim($raw['text'] ?? '');

            if ($text === '') {
                return $this->buildFallback($intent);
            }

            return [
                'text' => $text,
                'is_fallback' => (bool) ($raw['is_fallback'] ?? false),
                'used_knowledge' => $hasKnowledge && config('chatbot.knowledge.include_in_reply_tasks', true),
                'used_faq' => false,
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
            'text' => self::FALLBACK_REPLIES[$intent] ?? self::DEFAULT_FALLBACK,
            'is_fallback' => true,
            'used_knowledge' => false,
            'used_faq' => false,
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
            'text' => $text,
            'is_fallback' => true,
            'used_knowledge' => false,
            'used_faq' => false,
        ];
    }
}
