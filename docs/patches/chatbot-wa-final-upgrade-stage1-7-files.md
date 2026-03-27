# Final Files

## app/Enums/BookingFlowState.php

```php
<?php

namespace App\Enums;

enum BookingFlowState: string
{
    case Idle = 'idle';
    case CollectingRoute = 'collecting_route';
    case CollectingPassenger = 'collecting_passenger';
    case CollectingSchedule = 'collecting_schedule';
    case RouteUnavailable = 'route_unavailable';
    case ReadyToConfirm = 'ready_to_confirm';
    case Confirmed = 'confirmed';
    case Closed = 'closed';

    public function isCollecting(): bool
    {
        return in_array($this, [
            self::CollectingRoute,
            self::CollectingPassenger,
            self::CollectingSchedule,
            self::RouteUnavailable,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Closed,
        ], true);
    }
}
```

## app/Services/AI/PromptBuilderService.php

```php
<?php

namespace App\Services\AI;

class PromptBuilderService
{
    // -------------------------------------------------------------------------
    // Public builders â€” each returns ['system' => '...', 'user' => '...']
    // -------------------------------------------------------------------------

    /**
     * Build prompts for intent classification.
     *
     * @param  array<string, mixed>  $context
     * @return array{system: string, user: string}
     */
    public function buildIntentPrompt(array $context): array
    {
        $style = config('chatbot.prompts.style', 'sopan_ringkas');

        $system = <<<'SYSTEM'
        Kamu adalah classifier intent untuk layanan pemesanan transportasi antar kota di Indonesia.
        Tugasmu: mengklasifikasikan intent pesan pelanggan dengan TEPAT dan KONSISTEN.

        DAFTAR INTENT YANG TERSEDIA:
        - greeting         : salam pembuka, sapaan awal
        - booking          : ingin memesan atau menanyakan pemesanan perjalanan
        - booking_confirm  : mengkonfirmasi pemesanan yang sudah dibuat
        - booking_cancel   : membatalkan pemesanan
        - schedule_inquiry : bertanya jadwal keberangkatan
        - price_inquiry    : bertanya harga atau tarif
        - location_inquiry : bertanya lokasi penjemputan atau tujuan
        - support          : keluhan, komplain, butuh bantuan teknis
        - human_handoff    : ingin berbicara langsung dengan petugas/admin
        - farewell         : pamit, ucapan terima kasih sebagai penutup
        - confirmation     : konfirmasi/setuju/iya/benar/oke terhadap pertanyaan sebelumnya
        - rejection        : menolak/tidak jadi/batal/tidak terhadap tawaran/pertanyaan sebelumnya
        - out_of_scope     : topik di luar konteks layanan transportasi ini
        - unknown          : tidak dapat ditentukan dengan keyakinan yang cukup

        ATURAN WAJIB:
        1. Kembalikan JSON valid SAJA, tanpa kalimat lain, tanpa markdown.
        2. Jika ragu, gunakan "unknown" dengan confidence di bawah 0.5.
        3. confidence adalah float antara 0.0 sampai 1.0.
        4. reasoning_short maksimal 1 kalimat dalam bahasa Indonesia.
        5. JANGAN mengarang data, JANGAN mengasumsikan informasi yang tidak ada.

        FORMAT OUTPUT:
        {
          "intent": "nama_intent",
          "confidence": 0.90,
          "reasoning_short": "alasan singkat"
        }
        SYSTEM;

        $user = $this->formatIntentUserPrompt($context);

        return ['system' => trim($system), 'user' => $user];
    }

    /**
     * Build prompts for entity extraction.
     *
     * @param  array<string, mixed>  $context
     * @return array{system: string, user: string}
     */
    public function buildExtractionPrompt(array $context): array
    {
        $system = <<<'SYSTEM'
        Kamu adalah extractor informasi perjalanan untuk layanan transportasi antar kota Indonesia.
        Tugasmu: mengekstrak data perjalanan dari pesan pelanggan secara akurat.

        ATURAN WAJIB:
        1. JANGAN mengarang, menebak, atau mengasumsikan data yang tidak disebutkan secara eksplisit.
        2. Jika sebuah field tidak disebutkan: isi null.
        3. Tanggal dalam format YYYY-MM-DD, waktu dalam format HH:MM (24 jam).
        4. passenger_count harus integer atau null.
        5. missing_fields berisi nama field yang wajib untuk booking tapi belum ada.
           Field wajib booking: pickup_location, destination, departure_date, departure_time.
        6. Kembalikan JSON valid SAJA, tanpa kalimat lain.

        FORMAT OUTPUT:
        {
          "customer_name": null,
          "pickup_location": null,
          "destination": null,
          "departure_date": null,
          "departure_time": null,
          "passenger_count": null,
          "notes": null,
          "missing_fields": []
        }
        SYSTEM;

        $user = $this->formatExtractionUserPrompt($context);

        return ['system' => trim($system), 'user' => $user];
    }

    /**
     * Build prompts for natural reply generation.
     *
     * @param  array<string, mixed>  $context
     * @return array{system: string, user: string}
     */
    public function buildReplyPrompt(array $context): array
    {
        $style = config('chatbot.prompts.style', 'sopan_ringkas');
        $styleGuide = $this->styleInstruction($style);
        $intentLabel = $context['intent_result']['intent'] ?? 'unknown';

        $system = <<<SYSTEM
        Kamu adalah asisten layanan pelanggan untuk jasa transportasi antar kota Indonesia.

        GAYA KOMUNIKASI: {$styleGuide}

        ATURAN KETAT â€” WAJIB DIIKUTI:
        1. JANGAN mengarang atau menyebutkan harga, jadwal, ketersediaan, atau nomor booking yang tidak ada dalam data.
        2. JANGAN membuat keputusan bisnis final (konfirmasi booking, pembatalan resmi, dll.).
        3. Jika informasi tidak tersedia, katakan dengan jujur bahwa kamu akan cek atau minta info lebih.
        4. Sapa pelanggan dengan nama jika tersedia.
        5. Respons maksimal 3-4 kalimat â€” ringkas dan tepat sasaran.
        6. Bahasa Indonesia yang sopan dan natural.
        7. JANGAN tambahkan emoji kecuali memang diperlukan.
        8. Jika ada knowledge base di bawah, PRIORITASKAN informasinya. Jangan mengarang aturan, harga, atau jadwal di luar knowledge/data sistem.
        9. Jika knowledge tidak memiliki jawaban yang tepat, jawab secara konservatif dan tawarkan untuk mengecek lebih lanjut.
        10. Terdengar seperti admin travel WhatsApp di Indonesia: hangat, sopan, ringkas, dan profesional.
        11. Hindari bahasa birokratis, kaku, terlalu formal, atau terasa seperti template sistem.
        12. Hindari bullet/list kecuali memang perlu untuk merangkum data.

        INTENT SAAT INI: {$intentLabel}

        PANDUAN PER INTENT:
        - greeting        : sambut dengan hangat, tanya kebutuhan perjalanan
        - booking         : kumpulkan info yang kurang (titik jemput, tujuan, tanggal, jam, jumlah penumpang)
        - schedule_inquiry: informasikan bahwa jadwal akan dicek, minta data perjalanan jika belum ada
        - price_inquiry   : informasikan bahwa harga akan dikonfirmasi, jangan menyebut angka tanpa data
        - human_handoff   : sampaikan akan menghubungkan dengan tim, minta tunggu sebentar
        - support         : tunjukkan empati, catat keluhan, informasikan tindak lanjut
        - farewell        : tutup dengan sopan, ucapkan terima kasih
        - unknown         : minta klarifikasi dengan sopan, tawarkan pilihan bantuan
        - out_of_scope    : sampaikan dengan sopan bahwa hanya melayani pemesanan transportasi
        - confirmation    : terima konfirmasi, lanjutkan alur sesuai konteks
        - rejection       : terima dengan sopan, tanya kebutuhan lain
        SYSTEM;

        $user = $this->formatReplyUserPrompt($context);

        return ['system' => trim($system), 'user' => $user];
    }

    /**
     * Build prompts for conversation summarization.
     *
     * @param  array<string, mixed>  $context
     * @return array{system: string, user: string}
     */
    public function buildSummaryPrompt(array $context): array
    {
        $system = <<<'SYSTEM'
        Kamu adalah pencatat ringkasan percakapan layanan transportasi.
        Buat ringkasan singkat percakapan berikut dalam 2-3 kalimat bahasa Indonesia.
        Fokus pada: intent utama pelanggan, informasi perjalanan yang sudah dikumpulkan, dan status percakapan saat ini.

        ATURAN:
        1. Kembalikan JSON valid SAJA.
        2. Ringkasan harus faktual â€” hanya dari apa yang ada dalam percakapan.
        3. JANGAN menambahkan informasi yang tidak ada.

        FORMAT OUTPUT:
        {"summary": "ringkasan percakapan di sini"}
        SYSTEM;

        $user = $this->formatSummaryUserPrompt($context);

        return ['system' => trim($system), 'user' => $user];
    }

    // -------------------------------------------------------------------------
    // Private formatters
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $context */
    private function formatIntentUserPrompt(array $context): string
    {
        $lines = [];

        $lines[] = '=== PESAN TERBARU PELANGGAN ===';
        $lines[] = $context['message_text'] ?? '(kosong)';
        $lines[] = '';

        if (! empty($context['recent_messages'])) {
            $lines[] = '=== RIWAYAT PERCAKAPAN (terbaru terakhir) ===';
            foreach ($context['recent_messages'] as $msg) {
                $dir = ($msg['direction'] ?? 'inbound') === 'inbound' ? 'Pelanggan' : 'Bot';
                $text = $msg['text'] ?? '';
                $lines[] = "[{$dir}]: {$text}";
            }
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        if (! empty($memory)) {
            $lines[] = '=== INFO PELANGGAN ===';
            if (! empty($memory['primary_name'])) {
                $lines[] = "Nama: {$memory['primary_name']}";
            }
            if (! empty($memory['preferred_pickup'])) {
                $lines[] = "Titik jemput biasa: {$memory['preferred_pickup']}";
            }
            if (! empty($memory['preferred_destination'])) {
                $lines[] = "Tujuan biasa: {$memory['preferred_destination']}";
            }
            $lines[] = '';
        }

        // Tahap 10: compact knowledge hint (only when config allows â€” keeps intent prompt lean)
        if (
            config('chatbot.knowledge.include_in_intent_tasks', false)
            && ! empty($context['knowledge_hint'])
        ) {
            $lines[] = $context['knowledge_hint'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $context */
    private function formatExtractionUserPrompt(array $context): string
    {
        $lines = [];

        $lines[] = '=== PESAN TERBARU PELANGGAN ===';
        $lines[] = $context['message_text'] ?? '(kosong)';
        $lines[] = '';

        if (! empty($context['recent_messages'])) {
            $lines[] = '=== RIWAYAT PERCAKAPAN ===';
            foreach ($context['recent_messages'] as $msg) {
                $dir = ($msg['direction'] ?? 'inbound') === 'inbound' ? 'Pelanggan' : 'Bot';
                $lines[] = "[{$dir}]: ".($msg['text'] ?? '');
            }
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        if (! empty($memory)) {
            $lines[] = '=== PREFERENSI PELANGGAN SEBELUMNYA ===';
            if (! empty($memory['preferred_pickup'])) {
                $lines[] = "Titik jemput biasa: {$memory['preferred_pickup']}";
            }
            if (! empty($memory['preferred_destination'])) {
                $lines[] = "Tujuan biasa: {$memory['preferred_destination']}";
            }
            $lines[] = '(Gunakan preferensi di atas HANYA jika pelanggan mengkonfirmasinya secara eksplisit dalam percakapan ini)';
            $lines[] = '';
        }

        $intent = $context['intent_result']['intent'] ?? null;
        if ($intent !== null) {
            $lines[] = "Intent terdeteksi: {$intent}";
        }

        // Tahap 10: compact knowledge hint (only when config allows)
        if (
            config('chatbot.knowledge.include_in_extraction_tasks', false)
            && ! empty($context['knowledge_hint'])
        ) {
            $lines[] = '';
            $lines[] = $context['knowledge_hint'];
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $context */
    private function formatReplyUserPrompt(array $context): string
    {
        $lines = [];

        // Tahap 10: knowledge context block injected first so AI sees it before query
        if (
            config('chatbot.knowledge.include_in_reply_tasks', true)
            && ! empty($context['knowledge_block'])
        ) {
            $lines[] = $context['knowledge_block'];
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        $name = $memory['primary_name'] ?? null;

        if ($name !== null) {
            $lines[] = "Nama pelanggan: {$name}";
        }

        $intent = $context['intent_result']['intent'] ?? 'unknown';
        $lines[] = "Intent: {$intent}";
        $lines[] = '';

        $entities = $context['entity_result'] ?? [];
        $hasEntities = array_filter($entities, fn ($v) => $v !== null && $v !== [] && $key !== 'missing_fields');
        if (! empty($entities)) {
            $lines[] = '=== DATA PERJALANAN YANG SUDAH TERKUMPUL ===';
            foreach (['pickup_location', 'destination', 'departure_date', 'departure_time', 'passenger_count'] as $field) {
                if (! empty($entities[$field])) {
                    $lines[] = "  - {$field}: {$entities[$field]}";
                }
            }
            if (! empty($entities['missing_fields'])) {
                $missing = implode(', ', $entities['missing_fields']);
                $lines[] = "  - Data yang masih kurang: {$missing}";
            }
            $lines[] = '';
        }

        if (! empty($context['active_states'])) {
            $lines[] = '=== STATUS PERCAKAPAN ===';
            foreach ($context['active_states'] as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : (string) $value;
                $lines[] = "  {$key}: {$valueStr}";
            }
            $lines[] = '';
        }

        $lines[] = '=== PESAN PELANGGAN ===';
        $lines[] = $context['message_text'] ?? '(kosong)';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $context */
    private function formatSummaryUserPrompt(array $context): string
    {
        $lines = [];

        $memory = $context['customer_memory'] ?? [];
        if (! empty($memory['primary_name'])) {
            $lines[] = "Pelanggan: {$memory['primary_name']} ({$memory['phone_e164']})";
            $lines[] = '';
        }

        $lines[] = '=== PERCAKAPAN ===';
        foreach ($context['recent_messages'] as $msg) {
            $dir = ($msg['direction'] ?? 'inbound') === 'inbound' ? 'Pelanggan' : 'Bot';
            $text = $msg['text'] ?? '(kosong)';
            $time = $msg['sent_at'] ?? '';
            $lines[] = "[{$dir}] {$time}: {$text}";
        }

        return implode("\n", $lines);
    }

    private function styleInstruction(string $style): string
    {
        return match ($style) {
            'formal' => 'Formal dan profesional. Gunakan "Anda" dan bahasa resmi.',
            'casual' => 'Santai dan akrab. Gunakan "kamu" dan bahasa sehari-hari.',
            'sopan_ringkas' => 'Sopan, hangat, ringkas, dan natural seperti admin customer service travel di WhatsApp Indonesia. Hindari bahasa birokratis, jangan terdengar seperti template atau mesin.',
            default => 'Sopan, hangat, ringkas, dan natural dalam bahasa Indonesia.',
        };
    }
}
```

## app/Services/AI/ResponseGeneratorService.php

```php
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
        'booking' => 'Baik, saya bantu bookingnya ya. Mohon kirim titik jemput, tujuan, tanggal, dan jam keberangkatannya.',
        'booking_confirm' => 'Baik, konfirmasinya sudah masuk ya. Kami lanjut proses bookingnya.',
        'booking_cancel' => 'Baik, permintaan pembatalannya sudah kami catat ya. Nanti kami bantu tindak lanjuti.',
        'schedule_inquiry' => 'Siap, saya bantu cek jadwal ya. Boleh kirim rute dan tanggal keberangkatannya dulu?',
        'price_inquiry' => 'Baik, saya bantu cek harganya ya. Mohon kirim titik jemput dan tujuan dulu.',
        'location_inquiry' => 'Untuk lokasi yang ingin dicek, boleh kirim titik jemput atau tujuan perjalanannya ya?',
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
    private const BOOKING_INTENTS = ['booking', 'booking_confirm', 'booking_cancel'];

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

        // â”€â”€ 1. FAQ direct answer (skip for booking intents) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        // â”€â”€ 2. Low-confidence contextual fallback â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        // â”€â”€ 3. Normal LLM path â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
     * More helpful than generic fallback â€” guides the customer without inventing data.
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
```

## app/Services/Booking/BookingConversationStateService.php

```php
<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Services\Chatbot\ConversationStateService;
use App\Support\WaLog;

class BookingConversationStateService
{
    public const EXPECTED_INPUT_KEY = 'booking_expected_input';

    /**
     * Minimal booking slots that must survive across messages.
     *
     * @var array<int, string>
     */
    private const TRACKED_SLOT_KEYS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
        'travel_date',
        'travel_time',
        'payment_method',
    ];

    /**
     * Compact state snapshot kept in logs for easier debugging.
     *
     * @var array<int, string>
     */
    private const SNAPSHOT_KEYS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
        'travel_date',
        'travel_time',
        'payment_method',
        'booking_intent_status',
        'route_status',
        'route_issue',
        'fare_amount',
        'review_sent',
        'booking_confirmed',
        'needs_human_escalation',
        'admin_takeover',
    ];

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'greeting_detected' => false,
            'salam_type' => null,
            'time_greeting' => null,
            'booking_intent_status' => BookingFlowState::Idle->value,
            'pickup_location' => null,
            'destination' => null,
            'passenger_name' => null,
            'passenger_count' => null,
            'travel_date' => null,
            'travel_time' => null,
            'payment_method' => null,
            'route_status' => null,
            'route_issue' => null,
            'fare_amount' => null,
            'admin_takeover' => false,
            'needs_human_escalation' => false,
            'review_sent' => false,
            'booking_confirmed' => false,

            // Legacy mirrors retained for backward compatibility with older states.
            'pickup_point' => null,
            'destination_point' => null,
            'pickup_full_address' => null,
            'passenger_names' => [],
            'selected_seats' => [],
            'seat_choices_available' => [],
            'contact_number' => null,
            'contact_same_as_sender' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function load(Conversation $conversation): array
    {
        $slots = $this->defaults();

        foreach (array_keys($slots) as $key) {
            $value = $this->stateService->get($conversation, $key, $slots[$key]);
            $slots[$key] = $value ?? $slots[$key];
        }

        $slots['pickup_location'] ??= $slots['pickup_point'];
        $slots['destination'] ??= $slots['destination_point'];

        if ($slots['passenger_name'] === null && is_array($slots['passenger_names']) && $slots['passenger_names'] !== []) {
            $slots['passenger_name'] = $this->primaryPassengerName($slots['passenger_names']);
        }

        $slots['booking_intent_status'] = $this->normalizeFlowState(
            $slots['booking_intent_status'] ?? null,
            $slots,
        );

        $slots['admin_takeover'] = $conversation->isAdminTakeover();
        $slots['needs_human_escalation'] = (bool) ($slots['needs_human_escalation'] || $conversation->needs_human);

        return $slots;
    }

    /**
     * Hydrate missing slot memory from an existing booking draft.
     *
     * @return array<string, mixed>
     */
    public function hydrateFromBooking(Conversation $conversation, ?BookingRequest $booking): array
    {
        $slots = $this->load($conversation);

        if ($booking === null) {
            return $slots;
        }

        $updates = [];
        $bookingSlots = $this->slotsFromBooking($booking);

        foreach ($bookingSlots as $key => $value) {
            if ($this->sameValue($slots[$key] ?? null, $value)) {
                continue;
            }

            if ($this->isBlank($slots[$key] ?? null) && ! $this->isBlank($value)) {
                $updates[$key] = $value;
            }
        }

        foreach ($this->hydrationStateUpdates($booking, $slots, $bookingSlots) as $key => $value) {
            if (! $this->sameValue($slots[$key] ?? null, $value)) {
                $updates[$key] = $value;
            }
        }

        if ($updates === []) {
            return $slots;
        }

        $changes = $this->putMany($conversation, $updates, 'booking_draft_hydration');
        $hydrated = array_replace(
            $slots,
            array_map(fn (array $change) => $change['new'], $changes),
        );

        WaLog::info('[BookingState] hydrated from booking draft', [
            'conversation_id' => $conversation->id,
            'booking_id' => $booking->id,
            'changes' => $changes,
            'snapshot' => $this->snapshotFromSlots($hydrated, $this->expectedInput($conversation)),
        ]);

        return $hydrated;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function putMany(Conversation $conversation, array $updates, string $source = 'runtime'): array
    {
        $current = $this->load($conversation);
        $changes = [];
        $normalizedUpdates = $this->normalizeUpdates($current, $updates);

        foreach ($normalizedUpdates as $key => $value) {
            if (! array_key_exists($key, $this->defaults())) {
                continue;
            }

            if ($this->sameValue($current[$key] ?? null, $value)) {
                continue;
            }

            $this->stateService->put($conversation, $key, $value);
            $changes[$key] = [
                'old' => $current[$key] ?? null,
                'new' => $value,
            ];
        }

        if ($changes !== []) {
            $this->logChanges($conversation, $changes, $current, $source);
        }

        return $changes;
    }

    public function normalizeFlowState(?string $state, array $slots = []): string
    {
        return match (trim((string) $state)) {
            BookingFlowState::Idle->value => BookingFlowState::Idle->value,
            BookingFlowState::CollectingRoute->value => BookingFlowState::CollectingRoute->value,
            BookingFlowState::CollectingPassenger->value => BookingFlowState::CollectingPassenger->value,
            BookingFlowState::CollectingSchedule->value => BookingFlowState::CollectingSchedule->value,
            BookingFlowState::RouteUnavailable->value => BookingFlowState::RouteUnavailable->value,
            BookingFlowState::ReadyToConfirm->value,
            BookingStatus::AwaitingConfirmation->value => BookingFlowState::ReadyToConfirm->value,
            BookingFlowState::Confirmed->value => BookingFlowState::Confirmed->value,
            BookingFlowState::Closed->value,
            'needs_human' => BookingFlowState::Closed->value,
            'collecting' => $this->inferOpenStateFromSlots($slots),
            default => BookingFlowState::Idle->value,
        };
    }

    public function isCollectingState(?string $state): bool
    {
        return BookingFlowState::from($this->normalizeFlowState($state))->isCollecting();
    }

    public function expectedInput(Conversation $conversation): ?string
    {
        $value = $this->stateService->get($conversation, self::EXPECTED_INPUT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setExpectedInput(
        Conversation $conversation,
        ?string $expectedInput,
        string $source = 'runtime',
    ): void {
        $current = $this->expectedInput($conversation);
        $normalized = is_string($expectedInput) && trim($expectedInput) !== ''
            ? trim($expectedInput)
            : null;

        if ($current === $normalized) {
            return;
        }

        if ($normalized === null) {
            $this->stateService->forget($conversation, self::EXPECTED_INPUT_KEY);
        } else {
            $this->stateService->put($conversation, self::EXPECTED_INPUT_KEY, $normalized);
        }

        WaLog::debug('[BookingState] expected input updated', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'old' => $current,
            'new' => $normalized,
            'snapshot' => $this->snapshotFromSlots($this->load($conversation), $normalized),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function transitionFlowState(
        Conversation $conversation,
        BookingFlowState|string $state,
        ?string $expectedInput,
        string $source = 'runtime',
        array $context = [],
    ): void {
        $currentSlots = $this->load($conversation);
        $previousState = $this->normalizeFlowState((string) ($currentSlots['booking_intent_status'] ?? null), $currentSlots);
        $previousExpectedInput = $this->expectedInput($conversation);
        $normalizedExpectedInput = is_string($expectedInput) && trim($expectedInput) !== ''
            ? trim($expectedInput)
            : null;
        $nextState = $state instanceof BookingFlowState
            ? $state->value
            : $this->normalizeFlowState($state, $currentSlots);

        $this->putMany($conversation, ['booking_intent_status' => $nextState], $source);
        $this->setExpectedInput($conversation, $normalizedExpectedInput, $source);

        if ($previousState === $nextState && $previousExpectedInput === $normalizedExpectedInput) {
            return;
        }

        WaLog::info('[BookingState] flow state transitioned', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'from_state' => $previousState,
            'to_state' => $nextState,
            'from_expected_input' => $previousExpectedInput,
            'to_expected_input' => $normalizedExpectedInput,
            'context' => $context,
            'snapshot' => $this->snapshot($conversation),
        ]);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    public function syncBooking(BookingRequest $booking, array $slots, string $senderPhone): BookingRequest
    {
        $booking->pickup_location = $slots['pickup_location'];
        $booking->pickup_full_address = $slots['pickup_full_address'] ?? $booking->pickup_full_address;
        $booking->destination = $slots['destination'];
        $booking->departure_date = $slots['travel_date'];
        $booking->departure_time = $slots['travel_time'];
        $booking->passenger_count = $slots['passenger_count'];
        $booking->passenger_name = $slots['passenger_name'];
        $booking->passenger_names = $slots['passenger_name'] ? [$slots['passenger_name']] : null;
        $booking->payment_method = $slots['payment_method'];
        $booking->price_estimate = $slots['fare_amount'];
        $booking->contact_number = $slots['contact_number'] ?: ($booking->contact_number ?: ($senderPhone !== '' ? $senderPhone : null));
        $booking->contact_same_as_sender = $senderPhone !== '' && $booking->contact_number === $senderPhone;

        $dirty = $booking->getDirty();

        if ($dirty === []) {
            return $booking;
        }

        $booking->save();

        WaLog::debug('[BookingState] booking draft synced', [
            'conversation_id' => $booking->conversation_id,
            'booking_id' => $booking->id,
            'dirty_fields' => array_keys($dirty),
            'snapshot' => $this->snapshotFromSlots($slots),
        ]);

        return $booking->fresh();
    }

    /**
     * Public snapshot helper for support/debug tooling.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Conversation $conversation): array
    {
        return $this->snapshotFromSlots($this->load($conversation), $this->expectedInput($conversation));
    }

    /**
     * Split tracked slot changes into fresh captures vs overwrites.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array{
     *     created: array<string, array{old: mixed, new: mixed}>,
     *     overwritten: array<string, array{old: mixed, new: mixed}>
     * }
     */
    public function trackedSlotChanges(array $changes): array
    {
        $result = [
            'created' => [],
            'overwritten' => [],
        ];

        foreach (self::TRACKED_SLOT_KEYS as $key) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }

            $bucket = $this->isBlank($changes[$key]['old'] ?? null)
                ? 'created'
                : 'overwritten';

            $result[$bucket][$key] = $changes[$key];
        }

        return $result;
    }

    /**
     * @param  array<int, string>|null  $names
     */
    public function primaryPassengerName(?array $names): ?string
    {
        if (! is_array($names) || $names === []) {
            return null;
        }

        $first = trim((string) $names[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function normalizeUpdates(array $current, array $updates): array
    {
        if (array_key_exists('pickup_location', $updates) && ! array_key_exists('pickup_point', $updates)) {
            $updates['pickup_point'] = $updates['pickup_location'];
        }

        if (array_key_exists('destination', $updates) && ! array_key_exists('destination_point', $updates)) {
            $updates['destination_point'] = $updates['destination'];
        }

        if (array_key_exists('passenger_name', $updates) && ! array_key_exists('passenger_names', $updates)) {
            $updates['passenger_names'] = filled($updates['passenger_name'])
                ? [$updates['passenger_name']]
                : [];
        }

        if (array_key_exists('route_status', $updates) && $updates['route_status'] !== 'supported') {
            $updates['fare_amount'] = null;
        }

        if (array_key_exists('pickup_location', $updates) || array_key_exists('destination', $updates)) {
            $updates['route_issue'] = $updates['route_issue'] ?? null;
        }

        if ($this->hasTrackedSlotChange($updates)) {
            $updates['review_sent'] = $updates['review_sent'] ?? false;
            $updates['booking_confirmed'] = $updates['booking_confirmed'] ?? false;
        }

        if (array_key_exists('contact_number', $updates) && blank($updates['contact_number'])) {
            $updates['contact_number'] = $current['contact_number'] ?? null;
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function hasTrackedSlotChange(array $updates): bool
    {
        return array_intersect(array_keys($updates), self::TRACKED_SLOT_KEYS) !== [];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $current
     */
    private function logChanges(Conversation $conversation, array $changes, array $current, string $source): void
    {
        $loggableChanges = array_intersect_key($changes, array_flip(self::SNAPSHOT_KEYS));

        if ($loggableChanges === []) {
            return;
        }

        $trackedSlotChanges = $this->trackedSlotChanges($changes);

        $merged = array_replace(
            $current,
            array_map(fn (array $change) => $change['new'], $changes),
        );

        WaLog::debug('[BookingState] conversation state updated', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'changes' => $loggableChanges,
            'tracked_slot_changes' => [
                'created' => array_keys($trackedSlotChanges['created']),
                'overwritten' => $trackedSlotChanges['overwritten'],
            ],
            'snapshot' => $this->snapshotFromSlots($merged, $this->expectedInput($conversation)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function snapshotFromSlots(array $slots, ?string $expectedInput = null): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT_KEYS as $key) {
            $snapshot[$key] = $slots[$key] ?? null;
        }

        $snapshot['expected_input'] = $expectedInput;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotsFromBooking(BookingRequest $booking): array
    {
        return [
            'pickup_location' => $booking->pickup_location,
            'destination' => $booking->destination,
            'passenger_name' => $booking->passenger_name ?? $this->primaryPassengerName($booking->passenger_names),
            'passenger_count' => $booking->passenger_count,
            'travel_date' => $booking->departure_date?->format('Y-m-d'),
            'travel_time' => $booking->departure_time,
            'payment_method' => $booking->payment_method,
            'fare_amount' => $booking->price_estimate !== null
                ? (int) round((float) $booking->price_estimate)
                : null,
            'pickup_full_address' => $booking->pickup_full_address,
            'contact_number' => $booking->contact_number,
            'contact_same_as_sender' => $booking->contact_same_as_sender,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $bookingSlots
     * @return array<string, mixed>
     */
    private function hydrationStateUpdates(BookingRequest $booking, array $current, array $bookingSlots): array
    {
        $updates = [];
        $currentStatus = $this->normalizeFlowState((string) ($current['booking_intent_status'] ?? null), $current);

        if ($currentStatus === BookingFlowState::Closed->value) {
            return $updates;
        }

        if ($booking->booking_status === BookingStatus::AwaitingConfirmation) {
            if (! in_array($currentStatus, [BookingFlowState::ReadyToConfirm->value, BookingFlowState::Confirmed->value], true)) {
                $updates['booking_intent_status'] = BookingFlowState::ReadyToConfirm->value;
            }

            if (($current['review_sent'] ?? false) !== true) {
                $updates['review_sent'] = true;
            }

            if (($current['booking_confirmed'] ?? false) !== false) {
                $updates['booking_confirmed'] = false;
            }

            return $updates;
        }

        if (in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::Completed], true)) {
            if ($currentStatus !== BookingFlowState::Confirmed->value) {
                $updates['booking_intent_status'] = BookingFlowState::Confirmed->value;
            }

            if (($current['review_sent'] ?? false) !== true) {
                $updates['review_sent'] = true;
            }

            if (($current['booking_confirmed'] ?? false) !== true) {
                $updates['booking_confirmed'] = true;
            }

            return $updates;
        }

        if ($this->hasAnyFilledTrackedSlot($bookingSlots)) {
            $inferredState = $this->inferOpenStateFromSlots($bookingSlots);

            if ($currentStatus !== $inferredState) {
                $updates['booking_intent_status'] = $inferredState;
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function inferOpenStateFromSlots(array $slots): string
    {
        if (($slots['route_status'] ?? null) === 'unsupported') {
            return BookingFlowState::RouteUnavailable->value;
        }

        if ($this->isBlank($slots['pickup_location'] ?? null) || $this->isBlank($slots['destination'] ?? null)) {
            return BookingFlowState::CollectingRoute->value;
        }

        if ($this->isBlank($slots['passenger_name'] ?? null) || $this->isBlank($slots['passenger_count'] ?? null)) {
            return BookingFlowState::CollectingPassenger->value;
        }

        if (
            $this->isBlank($slots['travel_date'] ?? null)
            || $this->isBlank($slots['travel_time'] ?? null)
            || $this->isBlank($slots['payment_method'] ?? null)
        ) {
            return BookingFlowState::CollectingSchedule->value;
        }

        return BookingFlowState::ReadyToConfirm->value;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasAnyFilledTrackedSlot(array $slots): bool
    {
        foreach (self::TRACKED_SLOT_KEYS as $key) {
            if (! $this->isBlank($slots[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
```

## app/Services/Booking/BookingFlowStateMachine.php

```php
<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\GreetingService;
use App\Services\Chatbot\HumanEscalationService;
use App\Support\WaLog;

class BookingFlowStateMachine
{
    /**
     * @var array<int, string>
     */
    private const CORE_REQUIRED_SLOTS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
    ];

    public function __construct(
        private readonly BookingAssistantService $bookingAssistant,
        private readonly BookingConversationStateService $stateService,
        private readonly BookingSlotExtractorService $slotExtractor,
        private readonly RouteValidationService $routeValidator,
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly BookingConfirmationService $confirmationService,
        private readonly TimeGreetingService $timeGreetingService,
        private readonly GreetingService $greetingService,
        private readonly HumanEscalationService $humanEscalationService,
        private readonly ?BookingReplyNaturalizerService $replyNaturalizer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult
     * @return array{
     *     handled: bool,
     *     booking: BookingRequest|null,
     *     booking_decision: array<string, mixed>|null,
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>
     * }
     */
    public function handle(
        Conversation $conversation,
        Customer $customer,
        ConversationMessage $message,
        array $intentResult,
        array $entityResult,
        array $replyResult,
    ): array {
        $booking = $this->bookingAssistant->findExistingDraft($conversation);
        $slots = $this->stateService->hydrateFromBooking($conversation, $booking);
        $expectedInput = $this->stateService->expectedInput($conversation);
        $messageText = trim((string) ($message->message_text ?? ''));
        $timeGreeting = $this->timeGreetingService->resolve();

        $this->stateService->putMany($conversation, [
            'time_greeting' => $timeGreeting['label'],
            'admin_takeover' => $conversation->isAdminTakeover(),
        ], 'conversation_context');

        $extracted = $this->slotExtractor->extract(
            messageText: $messageText,
            currentSlots: $slots,
            entityResult: $entityResult,
            expectedInput: $expectedInput,
            senderPhone: $customer->phone_e164 ?? '',
        );

        $updates = $extracted['updates'];
        $signals = $extracted['signals'];

        $this->logExtraction($conversation, $messageText, $expectedInput, $updates, $signals);

        if ($signals['greeting_detected']) {
            $this->stateService->putMany($conversation, [
                'greeting_detected' => true,
                'salam_type' => $signals['salam_type'],
            ], 'greeting_signal');
        }

        if ($signals['human_keyword']) {
            return $this->escalateToHuman($conversation, $customer, $intentResult, $signals, $messageText, $booking);
        }

        $hasBookingContext = $this->hasBookingContext(
            conversation: $conversation,
            booking: $booking,
            intentResult: $intentResult,
            slots: $slots,
            updates: $updates,
            signals: $signals,
        );

        if ($signals['greeting_only'] && ! $hasBookingContext) {
            $opening = $this->greetingService->buildOpeningGreeting($conversation, $messageText, $slots)
                ?? $timeGreeting['opening'];

            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::Idle,
                null,
                'greeting_only',
            );

            return $this->decision(
                booking: $booking,
                action: 'greeting',
                reply: $this->reply(
                    text: $opening,
                    meta: ['source' => 'booking_engine', 'action' => 'greeting'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Greeting),
            );
        }

        if ($booking === null && $updates === []) {
            if ($this->isRouteListInquiry($signals, $messageText)) {
                return $this->decision(
                    booking: null,
                    action: 'route_list',
                    reply: $this->reply(
                        text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->routeListReply()),
                        meta: ['source' => 'booking_engine', 'action' => 'route_list'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::LocationInquiry),
                );
            }

            if ($signals['schedule_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'schedule_inquiry',
                    reply: $this->reply(
                        text: $this->withGreetingContext(
                            $signals,
                            $messageText,
                            $this->naturalizer()->scheduleLine()."\n\nKalau ingin saya cek lebih lanjut, silakan kirim titik jemput dan tujuan perjalanannya ya.",
                        ),
                        meta: ['source' => 'booking_engine', 'action' => 'schedule_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::ScheduleInquiry),
                );
            }

            if ($signals['price_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'price_inquiry',
                    reply: $this->reply(
                        text: $this->withGreetingContext(
                            $signals,
                            $messageText,
                            'Baik, untuk cek harga saya perlu titik jemput dan tujuan dulu ya.',
                        ),
                        meta: ['source' => 'booking_engine', 'action' => 'price_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::PriceInquiry),
                );
            }
        }

        if (! $hasBookingContext) {
            if ($signals['close_intent']) {
                return $this->closeConversation($conversation, $booking, $intentResult, $signals, $messageText);
            }

            return $this->decision(
                booking: null,
                action: 'pass_through',
                reply: $this->passThroughReply($replyResult, $signals, $messageText, $slots),
                intentResult: $intentResult,
            );
        }

        $booking = $booking ?? $this->bookingAssistant->findOrCreateDraft($conversation);

        return $this->advanceBookingFlow(
            conversation: $conversation,
            customer: $customer,
            booking: $booking,
            intentResult: $intentResult,
            signals: $signals,
            slots: $slots,
            updates: $updates,
            messageText: $messageText,
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function advanceBookingFlow(
        Conversation $conversation,
        Customer $customer,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        array $slots,
        array $updates,
        string $messageText,
    ): array {
        $correctionLines = [];
        $capturedSlotUpdates = [];

        if ($updates !== []) {
            $changes = $this->stateService->putMany($conversation, array_merge($updates, [
                'needs_human_escalation' => false,
                'admin_takeover' => false,
            ]), 'slot_extraction');

            $trackedSlotChanges = $this->stateService->trackedSlotChanges($changes);
            $correctionLines = $this->naturalizer()->correctionLinesFromChanges($trackedSlotChanges['overwritten']);
            $capturedSlotUpdates = array_map(
                fn (array $change): mixed => $change['new'],
                $trackedSlotChanges['created'],
            );

            $slots = $this->stateService->load($conversation);
        }

        $routeEvaluation = $this->evaluateRoute($slots);

        $this->stateService->putMany($conversation, [
            'route_status' => $routeEvaluation['status'],
            'route_issue' => $routeEvaluation['focus_slot'],
            'fare_amount' => $routeEvaluation['fare_amount'],
        ], 'route_evaluation');
        $slots = $this->stateService->load($conversation);
        $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');
        $currentState = $this->determineFlowState($slots);

        if (($slots['review_sent'] ?? false) === true && ($signals['affirmation'] ?? false) === true && $updates === []) {
            $this->confirmationService->confirm($booking);
            $this->stateService->putMany($conversation, [
                'booking_confirmed' => true,
                'review_sent' => true,
            ], 'booking_confirmation');
            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::Confirmed,
                null,
                'booking_confirmation',
                ['reason' => 'customer_affirmed_review'],
            );
            $this->humanEscalationService->forwardBooking($conversation, $customer, $booking->fresh());

            WaLog::info('[BookingFlow] booking confirmed', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return $this->decision(
                booking: $booking->fresh(),
                action: 'confirmed',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->confirmed()),
                    meta: ['source' => 'booking_engine', 'action' => 'confirmed'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::BookingConfirm),
            );
        }

        if (($slots['review_sent'] ?? false) === true && ($signals['rejection'] ?? false) === true && $updates === []) {
            $this->stateService->putMany($conversation, [
                'review_sent' => false,
                'booking_confirmed' => false,
            ], 'booking_rejection');
            $slots = $this->stateService->load($conversation);
            $currentState = $this->determineFlowState($slots);
            $this->stateService->transitionFlowState(
                $conversation,
                $currentState,
                $currentState === BookingFlowState::RouteUnavailable
                    ? ($slots['route_issue'] ?? 'pickup_location')
                    : null,
                'booking_rejection',
                ['reason' => 'customer_rejected_review'],
            );

            return $this->decision(
                booking: $booking,
                action: 'ask_correction',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->askCorrection()),
                    meta: ['source' => 'booking_engine', 'action' => 'ask_correction'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($this->shouldCloseConversation($signals, $slots, $updates)) {
            return $this->closeConversation($conversation, $booking, $intentResult, $signals, $messageText);
        }

        if (($signals['acknowledgement'] ?? false) === true && $updates === [] && $currentState->isCollecting()) {
            $pendingPrompt = $this->pendingPrompt($slots, $currentState);

            if ($pendingPrompt !== null) {
                return $this->decision(
                    booking: $booking,
                    action: 'acknowledge_pending',
                    reply: $this->reply(
                        text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->inProgressAcknowledgement($pendingPrompt)),
                        meta: ['source' => 'booking_engine', 'action' => 'acknowledge_pending'],
                    ),
                    intentResult: $intentResult,
                );
            }
        }

        if ($updates === [] && $this->shouldUseStateFallback($signals, $currentState, $slots)) {
            $pendingPrompt = $currentState->isCollecting()
                ? $this->pendingPrompt($slots, $currentState)
                : null;

            return $this->decision(
                booking: $booking,
                action: 'state_fallback',
                reply: $this->reply(
                    text: $this->withGreetingContext(
                        $signals,
                        $messageText,
                        $this->naturalizer()->fallbackForState(
                            state: $currentState->value,
                            slots: $slots,
                            pendingPrompt: $pendingPrompt,
                            signals: $signals,
                            routeIssue: $routeEvaluation['focus_slot'] ?? $slots['route_issue'] ?? null,
                            routeSuggestions: $routeEvaluation['suggestions'],
                        ),
                    ),
                    meta: ['source' => 'booking_engine', 'action' => 'state_fallback'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($currentState === BookingFlowState::RouteUnavailable) {
            $focusSlot = $routeEvaluation['focus_slot'] ?? $slots['route_issue'] ?? 'pickup_location';
            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::RouteUnavailable,
                $focusSlot,
                'unsupported_route',
                ['route_status' => $routeEvaluation['status']],
            );

            $replyText = $this->naturalizer()->naturalizeUnsupportedRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                unsupportedReply: $this->naturalizer()->unsupportedRouteReply(
                    pickup: $slots['pickup_location'] ?? null,
                    destination: $slots['destination'] ?? null,
                    suggestions: $routeEvaluation['suggestions'],
                    focusSlot: $focusSlot,
                ),
            );

            return $this->decision(
                booking: $booking,
                action: 'unsupported_route',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => 'unsupported_route',
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::LocationInquiry),
            );
        }

        $coreMissing = $this->missingSlots($slots, self::CORE_REQUIRED_SLOTS);

        if ($currentState === BookingFlowState::CollectingRoute || $currentState === BookingFlowState::CollectingPassenger) {
            $source = $currentState === BookingFlowState::CollectingRoute
                ? 'collecting_route'
                : 'collecting_passenger';
            $this->stateService->transitionFlowState(
                $conversation,
                $currentState,
                $coreMissing[0] ?? 'pickup_location',
                $source,
                ['missing_slots' => $coreMissing],
            );

            $facts = $this->contextFacts($signals, $slots);
            $replyText = $this->naturalizer()->naturalizeRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                prompt: $this->naturalizer()->askBasicDetails($coreMissing, $slots),
                routeLine: $facts['route_line'],
                priceLine: $facts['price_line'],
                scheduleLine: $facts['schedule_line'],
            );

            return $this->decision(
                booking: $booking,
                action: $currentState === BookingFlowState::CollectingRoute
                    ? 'collect_route'
                    : 'collect_passenger',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => $currentState === BookingFlowState::CollectingRoute
                            ? 'collect_route'
                            : 'collect_passenger',
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ($currentState === BookingFlowState::CollectingSchedule) {
            $schedulePrompt = $this->schedulePrompt($slots, $signals);

            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::CollectingSchedule,
                $schedulePrompt['expected_input'],
                'collecting_schedule',
                ['missing_slot' => $schedulePrompt['expected_input']],
            );

            $facts = $this->contextFacts($signals, $slots);
            $replyText = $this->naturalizer()->naturalizeRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                prompt: $schedulePrompt['prompt'],
                routeLine: $facts['route_line'],
                priceLine: $facts['price_line'],
                scheduleLine: $facts['schedule_line'],
            );

            return $this->decision(
                booking: $booking,
                action: $schedulePrompt['action'],
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => $schedulePrompt['action'],
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        $this->confirmationService->requestConfirmation($booking);
        $this->stateService->putMany($conversation, [
            'review_sent' => true,
            'booking_confirmed' => false,
        ], 'ready_to_confirm');
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::ReadyToConfirm,
            null,
            'ready_to_confirm',
            ['reason' => 'all_required_slots_collected'],
        );

        return $this->decision(
            booking: $booking->fresh(),
            action: 'ask_confirmation',
            reply: $this->reply(
                text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->reviewSummary($booking->fresh())),
                meta: ['source' => 'booking_engine', 'action' => 'ask_confirmation'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
        );
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function passThroughReply(array $replyResult, array $signals, string $messageText, array $slots): array
    {
        $text = trim((string) ($replyResult['text'] ?? ''));

        if ($text === '' || ($replyResult['is_fallback'] ?? false) === true) {
            $text = $this->naturalizer()->fallbackForState(
                state: (string) ($slots['booking_intent_status'] ?? BookingFlowState::Idle->value),
                slots: $slots,
                signals: $signals,
            );
        }

        return $this->reply(
            text: $this->withGreetingContext($signals, $messageText, $text),
            meta: ['source' => 'ai_reply', 'action' => 'pass_through'],
            isFallback: (bool) ($replyResult['is_fallback'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function escalateToHuman(
        Conversation $conversation,
        Customer $customer,
        array $intentResult,
        array $signals,
        string $messageText,
        ?BookingRequest $booking,
    ): array {
        $this->stateService->putMany($conversation, [
            'admin_takeover' => true,
            'needs_human_escalation' => true,
        ], 'human_handoff');
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::Closed,
            null,
            'human_handoff',
            ['reason' => 'customer_requested_admin'],
        );

        $this->humanEscalationService->escalateQuestion(
            conversation: $conversation,
            customer: $customer,
            reason: 'Permintaan admin manusia dari customer.',
        );

        return $this->decision(
            booking: $booking,
            action: 'human_handoff',
            reply: $this->reply(
                text: $this->withGreetingContext(
                    $signals,
                    $messageText,
                    'Baik, saya bantu teruskan ke admin ya. Mohon tunggu sebentar.',
                ),
                meta: ['source' => 'booking_engine', 'action' => 'human_handoff'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::HumanHandoff),
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function closeConversation(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $signals,
        string $messageText,
    ): array {
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::Closed,
            null,
            'conversation_closed',
            ['reason' => 'customer_close_intent'],
        );

        return $this->decision(
            booking: $booking,
            action: 'close_conversation',
            reply: $this->reply(
                text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->closing()),
                meta: ['source' => 'booking_engine', 'action' => 'close_conversation', 'close_conversation' => true],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Farewell),
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function withGreetingContext(array $signals, string $messageText, string $text): string
    {
        if (($signals['salam_type'] ?? null) !== 'islamic') {
            return $text;
        }

        return $this->greetingService->prependIslamicGreeting($messageText, $text);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @return array{route_line: string|null, price_line: string|null, schedule_line: string|null}
     */
    private function contextFacts(array $signals, array $slots): array
    {
        return [
            'route_line' => ($slots['route_status'] ?? null) === 'supported'
                ? $this->naturalizer()->routeAvailableLine(
                    $slots['pickup_location'] ?? null,
                    $slots['destination'] ?? null,
                )
                : null,
            'price_line' => ($signals['price_keyword'] ?? false) === true
                ? $this->naturalizer()->priceLine(
                    pickup: $slots['pickup_location'] ?? null,
                    destination: $slots['destination'] ?? null,
                    passengerCount: $slots['passenger_count'] ?? null,
                )
                : null,
            'schedule_line' => ($signals['schedule_keyword'] ?? false) === true
                ? $this->naturalizer()->scheduleLine()
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<int, string>
     */
    private function missingSlots(array $slots, array $requiredSlots): array
    {
        return array_values(array_filter(
            $requiredSlots,
            fn (string $slot) => blank($slots[$slot] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     */
    private function shouldCloseConversation(array $signals, array $slots, array $updates): bool
    {
        if (($signals['close_intent'] ?? false) !== true || $updates !== []) {
            return false;
        }

        if (($signals['affirmation'] ?? false) === true && ($slots['review_sent'] ?? false) === true) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     */
    private function shouldUseStateFallback(array $signals, BookingFlowState $state, array $slots): bool
    {
        if (($signals['close_intent'] ?? false) === true) {
            return false;
        }

        if (($signals['affirmation'] ?? false) === true || ($signals['rejection'] ?? false) === true) {
            return false;
        }

        if (($signals['human_keyword'] ?? false) === true) {
            return false;
        }

        if ($state === BookingFlowState::ReadyToConfirm) {
            return ($slots['review_sent'] ?? false) === true;
        }

        return in_array($state, [
            BookingFlowState::CollectingRoute,
            BookingFlowState::CollectingPassenger,
            BookingFlowState::CollectingSchedule,
            BookingFlowState::RouteUnavailable,
            BookingFlowState::Confirmed,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function pendingPrompt(array $slots, BookingFlowState $state): ?string
    {
        if ($state === BookingFlowState::RouteUnavailable) {
            $focusSlot = $slots['route_issue'] ?? 'pickup_location';

            return $this->naturalizer()->askBasicDetails([$focusSlot], $slots);
        }

        $coreMissing = $this->missingSlots($slots, self::CORE_REQUIRED_SLOTS);

        if ($coreMissing !== []) {
            return $this->naturalizer()->askBasicDetails($coreMissing, $slots);
        }

        if (($slots['travel_date'] ?? null) === null) {
            return $this->naturalizer()->askTravelDate();
        }

        if (($slots['travel_time'] ?? null) === null) {
            return $this->naturalizer()->askTravelTime();
        }

        if (($slots['payment_method'] ?? null) === null) {
            return $this->naturalizer()->askPaymentMethod();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $signals
     * @return array{action: string, expected_input: string, prompt: string}
     */
    private function schedulePrompt(array $slots, array $signals): array
    {
        if (($slots['travel_date'] ?? null) === null) {
            return [
                'action' => 'ask_travel_date',
                'expected_input' => 'travel_date',
                'prompt' => $this->naturalizer()->askTravelDate(),
            ];
        }

        if (($slots['travel_time'] ?? null) === null) {
            return [
                'action' => 'ask_travel_time',
                'expected_input' => 'travel_time',
                'prompt' => $this->naturalizer()->askTravelTime((bool) ($signals['time_ambiguous'] ?? false)),
            ];
        }

        return [
            'action' => 'ask_payment_method',
            'expected_input' => 'payment_method',
            'prompt' => $this->naturalizer()->askPaymentMethod(),
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function determineFlowState(array $slots): BookingFlowState
    {
        if (($slots['booking_confirmed'] ?? false) === true) {
            return BookingFlowState::Confirmed;
        }

        if (($slots['route_status'] ?? null) === 'unsupported') {
            return BookingFlowState::RouteUnavailable;
        }

        if (blank($slots['pickup_location'] ?? null) || blank($slots['destination'] ?? null)) {
            return BookingFlowState::CollectingRoute;
        }

        if (blank($slots['passenger_name'] ?? null) || blank($slots['passenger_count'] ?? null)) {
            return BookingFlowState::CollectingPassenger;
        }

        if (
            blank($slots['travel_date'] ?? null)
            || blank($slots['travel_time'] ?? null)
            || blank($slots['payment_method'] ?? null)
        ) {
            return BookingFlowState::CollectingSchedule;
        }

        return BookingFlowState::ReadyToConfirm;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{
     *     status: string|null,
     *     focus_slot: string|null,
     *     suggestions: array<int, string>,
     *     fare_amount: int|null
     * }
     */
    private function evaluateRoute(array $slots): array
    {
        $pickup = $slots['pickup_location'] ?? null;
        $destination = $slots['destination'] ?? null;

        if (blank($pickup) || blank($destination)) {
            return [
                'status' => null,
                'focus_slot' => null,
                'suggestions' => [],
                'fare_amount' => null,
            ];
        }

        $fareAmount = $this->fareCalculator->calculate(
            $pickup,
            $destination,
            (int) ($slots['passenger_count'] ?? 1),
        );

        if ($fareAmount !== null) {
            return [
                'status' => 'supported',
                'focus_slot' => null,
                'suggestions' => [],
                'fare_amount' => $fareAmount,
            ];
        }

        $pickupKnown = $this->routeValidator->isKnownLocation($pickup);
        $destinationKnown = $this->routeValidator->isKnownLocation($destination);

        if ($destinationKnown && ! $pickupKnown) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'pickup_location',
                'suggestions' => array_slice($this->routeValidator->supportedPickupsForDestination($destination), 0, 6),
                'fare_amount' => null,
            ];
        }

        if ($pickupKnown && ! $destinationKnown) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'destination',
                'suggestions' => array_slice($this->routeValidator->supportedDestinations($pickup), 0, 6),
                'fare_amount' => null,
            ];
        }

        $destinationSuggestions = array_slice($this->routeValidator->supportedDestinations($pickup), 0, 6);

        if ($destinationSuggestions !== []) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'destination',
                'suggestions' => $destinationSuggestions,
                'fare_amount' => null,
            ];
        }

        return [
            'status' => 'unsupported',
            'focus_slot' => 'pickup_location',
            'suggestions' => array_slice($this->routeValidator->supportedPickupsForDestination($destination), 0, 6),
            'fare_amount' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $signals
     */
    private function hasBookingContext(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $slots,
        array $updates,
        array $signals,
    ): bool {
        $currentState = BookingFlowState::from(
            $this->stateService->normalizeFlowState((string) ($slots['booking_intent_status'] ?? null), $slots),
        );

        if ($currentState === BookingFlowState::Closed) {
            $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

            return $updates !== []
                || $intent?->isBookingRelated() === true
                || in_array($intent, [IntentType::LocationInquiry, IntentType::PriceInquiry, IntentType::ScheduleInquiry], true)
                || ($signals['booking_keyword'] ?? false)
                || ($signals['schedule_keyword'] ?? false)
                || ($signals['price_keyword'] ?? false)
                || ($signals['route_keyword'] ?? false)
                || ($signals['affirmation'] ?? false)
                || ($signals['rejection'] ?? false);
        }

        if ($booking !== null) {
            return true;
        }

        if ($currentState !== BookingFlowState::Idle) {
            return true;
        }

        foreach ([
            'pickup_location',
            'destination',
            'passenger_name',
            'passenger_count',
            'travel_date',
            'travel_time',
            'payment_method',
        ] as $slot) {
            if (filled($slots[$slot] ?? null)) {
                return true;
            }
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

        if ($intent !== null && $intent->isBookingRelated()) {
            return true;
        }

        if (in_array($intent, [IntentType::LocationInquiry, IntentType::PriceInquiry, IntentType::ScheduleInquiry], true)) {
            return true;
        }

        if (
            ($signals['booking_keyword'] ?? false)
            || ($signals['schedule_keyword'] ?? false)
            || ($signals['price_keyword'] ?? false)
            || ($signals['route_keyword'] ?? false)
        ) {
            return true;
        }

        if ($updates !== []) {
            return true;
        }

        if (filled($conversation->summary)) {
            return true;
        }

        return filled($conversation->current_intent)
            && ! in_array($conversation->current_intent, ['greeting', 'farewell', 'unknown'], true);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isRouteListInquiry(array $signals, string $messageText): bool
    {
        if (($signals['route_keyword'] ?? false) !== true) {
            return false;
        }

        return (bool) preg_match('/\b(lokasi jemput|titik jemput|rute|trayek|tujuan tersedia|antar ke mana)\b/u', mb_strtolower($messageText, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function overrideIntent(array $intentResult, IntentType $intent): array
    {
        $intentResult['intent'] = $intent->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0), 0.95);

        return $intentResult;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function reply(
        string $text,
        array $meta,
        bool $isFallback = false,
        string $messageType = 'text',
        array $outboundPayload = [],
    ): array {
        return [
            'text' => $text,
            'is_fallback' => $isFallback,
            'message_type' => $messageType,
            'outbound_payload' => $outboundPayload,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function decision(?BookingRequest $booking, string $action, array $reply, array $intentResult): array
    {
        return [
            'handled' => true,
            'booking' => $booking,
            'booking_decision' => [
                'action' => $action,
                'booking_status' => $booking?->booking_status?->value ?? BookingStatus::Draft->value,
            ],
            'reply' => $reply,
            'intent_result' => $intentResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $signals
     */
    private function logExtraction(
        Conversation $conversation,
        string $messageText,
        ?string $expectedInput,
        array $updates,
        array $signals,
    ): void {
        WaLog::debug('[BookingFlow] extractor result', [
            'conversation_id' => $conversation->id,
            'expected_input' => $expectedInput,
            'message_preview' => mb_substr($messageText, 0, 120),
            'updates' => $updates,
            'signals' => [
                'booking_keyword' => $signals['booking_keyword'] ?? false,
                'schedule_keyword' => $signals['schedule_keyword'] ?? false,
                'price_keyword' => $signals['price_keyword'] ?? false,
                'route_keyword' => $signals['route_keyword'] ?? false,
                'affirmation' => $signals['affirmation'] ?? false,
                'rejection' => $signals['rejection'] ?? false,
                'close_intent' => $signals['close_intent'] ?? false,
                'time_ambiguous' => $signals['time_ambiguous'] ?? false,
            ],
        ]);
    }

    private function naturalizer(): BookingReplyNaturalizerService
    {
        return $this->replyNaturalizer
            ?? new BookingReplyNaturalizerService($this->fareCalculator, $this->routeValidator);
    }
}
```

## app/Services/Booking/BookingReplyNaturalizerService.php

```php
<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Models\BookingRequest;
use Illuminate\Support\Carbon;

class BookingReplyNaturalizerService
{
    /**
     * @var array<string, string>
     */
    private const SLOT_LABELS = [
        'pickup_location' => 'titik jemput',
        'destination' => 'tujuan',
        'passenger_name' => 'nama penumpang',
        'passenger_count' => 'jumlah penumpang',
        'travel_date' => 'tanggal keberangkatan',
        'travel_time' => 'jam berangkat',
        'payment_method' => 'metode pembayaran',
    ];

    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly RouteValidationService $routeValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  array<string, mixed>  $updates
     * @return array<int, string>
     */
    public function correctionLines(array $currentSlots, array $updates): array
    {
        $changes = [];

        foreach ($updates as $slot => $newValue) {
            $changes[$slot] = [
                'old' => $currentSlots[$slot] ?? null,
                'new' => $newValue,
            ];
        }

        return $this->correctionLinesFromChanges($changes);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<int, string>
     */
    public function correctionLinesFromChanges(array $changes): array
    {
        $lines = [];

        foreach (self::SLOT_LABELS as $slot => $label) {
            if (! array_key_exists($slot, $changes)) {
                continue;
            }

            $oldValue = $changes[$slot]['old'] ?? null;
            $newValue = $changes[$slot]['new'] ?? null;

            if ($this->sameValue($oldValue, $newValue) || $this->isBlank($oldValue)) {
                continue;
            }

            $value = $this->displayValue($slot, $newValue);
            $lines[] = $this->chooseVariant(
                'correction_line.'.$slot,
                [$oldValue, $newValue],
                [
                    'Baik, saya update '.$label.' jadi '.$value.'.',
                    'Siap, saya ubah '.$label.' jadi '.$value.'.',
                    'Oke, saya sesuaikan '.$label.' jadi '.$value.'.',
                ],
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function captureBlock(array $updates): ?string
    {
        $lines = [];
        foreach (self::SLOT_LABELS as $slot => $label) {
            if (! array_key_exists($slot, $updates)) {
                continue;
            }
            $lines[] = '- '.$label.': '.$this->displayValue($slot, $updates[$slot]);
        }
        if ($lines === []) {
            return null;
        }
        $intro = $this->chooseVariant(
            'capture_block_intro',
            array_keys($updates),
            [
                'Baik, saya catat dulu ya:',
                'Siap, saya catat ya:',
                'Oke, saya rangkum dulu datanya ya:',
            ],
        );

        return implode("\n", array_merge([$intro], $lines));
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function captureSummary(array $updates): ?string
    {
        $trackedUpdates = array_intersect_key($updates, self::SLOT_LABELS);

        if ($trackedUpdates === []) {
            return null;
        }

        if (count($trackedUpdates) > 3) {
            return $this->captureBlock($trackedUpdates);
        }

        $fragments = [];

        foreach ($trackedUpdates as $slot => $value) {
            $fragments[] = $this->slotSummaryFragment($slot, $value);
        }

        return $this->chooseVariant(
            'capture_summary',
            array_keys($trackedUpdates),
            [
                'Baik, saya catat '.$this->joinLabels($fragments).' ya.',
                'Siap, saya catat '.$this->joinLabels($fragments).' ya.',
                'Oke, saya catat '.$this->joinLabels($fragments).' ya.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $capturedUpdates
     * @param  array<int, string>  $correctionLines
     */
    public function naturalizeRuleReply(
        array $capturedUpdates,
        array $correctionLines,
        string $prompt,
        ?string $routeLine = null,
        ?string $priceLine = null,
        ?string $scheduleLine = null,
    ): string {
        $parts = $correctionLines;
        $captureSummary = $this->captureSummary($capturedUpdates);

        if ($captureSummary !== null) {
            $parts[] = $captureSummary;
        }

        $facts = array_values(array_filter([
            $routeLine,
            $priceLine,
            $this->shouldIncludeScheduleLine($scheduleLine, $prompt) ? $scheduleLine : null,
        ]));

        $parts[] = $this->mergeFactsWithPrompt($facts, $prompt);

        return $this->composeReplyParts($parts);
    }

    /**
     * @param  array<string, mixed>  $capturedUpdates
     * @param  array<int, string>  $correctionLines
     */
    public function naturalizeUnsupportedRuleReply(
        array $capturedUpdates,
        array $correctionLines,
        string $unsupportedReply,
    ): string {
        $parts = $correctionLines;
        $captureSummary = $this->captureSummary($capturedUpdates);

        if ($captureSummary !== null) {
            $parts[] = $captureSummary;
        }

        $parts[] = $unsupportedReply;

        return $this->composeReplyParts($parts);
    }

    public function routeAvailableLine(?string $pickup, ?string $destination): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }

        return $this->chooseVariant(
            'route_available',
            [$pickup, $destination],
            [
                'Rute '.$pickup.' ke '.$destination.' tersedia ya.',
                'Untuk rute '.$pickup.' ke '.$destination.' tersedia ya.',
                'Rute '.$pickup.' ke '.$destination.' saat ini tersedia ya.',
            ],
        );
    }

    /**
     * @param  array<int, string>  $suggestions
     */
    public function unsupportedRouteReply(
        ?string $pickup,
        ?string $destination,
        array $suggestions,
        string $focusSlot,
    ): string {
        if ($focusSlot === 'pickup_location' && filled($pickup) && filled($destination)) {
            $text = $this->chooseVariant(
                'unsupported.pickup',
                [$pickup, $destination],
                [
                    'Mohon maaf ya, untuk penjemputan dari '.$pickup.' ke '.$destination.' saat ini belum tersedia.',
                    'Mohon maaf, untuk penjemputan dari '.$pickup.' ke '.$destination.' saat ini belum tersedia ya.',
                ],
            );
        } elseif ($focusSlot === 'destination' && filled($pickup) && filled($destination)) {
            $text = $this->chooseVariant(
                'unsupported.destination',
                [$pickup, $destination],
                [
                    'Mohon maaf ya, untuk tujuan '.$destination.' dari '.$pickup.' saat ini belum tersedia.',
                    'Mohon maaf, untuk tujuan '.$destination.' dari '.$pickup.' saat ini belum tersedia ya.',
                ],
            );
        } else {
            $parts = array_filter([$pickup, $destination]);
            $text = $this->chooseVariant(
                'unsupported.route',
                $parts,
                [
                    'Mohon maaf ya, untuk rute '.implode(' ke ', $parts).' saat ini belum tersedia.',
                    'Mohon maaf, untuk rute '.implode(' ke ', $parts).' saat ini belum tersedia ya.',
                ],
            );
        }
        if ($suggestions !== []) {
            $label = $focusSlot === 'destination'
                ? 'Tujuan yang tersedia saat ini'
                : 'Titik jemput yang tersedia saat ini';
            $text .= "\n\n".$label.' antara lain '.$this->joinLabels($suggestions).'.';
        }
        $followUp = $focusSlot === 'destination'
            ? $this->chooseVariant(
                'unsupported.followup.destination',
                $suggestions,
                [
                    'Kalau mau lanjut, silakan kirim tujuan lain yang tersedia ya. Nanti saya bantu lanjutkan.',
                    'Kalau ingin saya cek lagi, kirim tujuan lain yang tersedia ya. Nanti saya bantu proses.',
                ],
            )
            : $this->chooseVariant(
                'unsupported.followup.pickup',
                $suggestions,
                [
                    'Kalau mau lanjut, silakan kirim titik jemput lain yang tersedia ya. Nanti saya bantu lanjutkan.',
                    'Kalau ingin saya cek lagi, kirim titik jemput lain yang tersedia ya. Nanti saya bantu proses.',
                ],
            );

        return $text."\n\n".$followUp;
    }

    /**
     * @param  array<int, string>  $missing
     * @param  array<string, mixed>  $slots
     */
    public function askBasicDetails(array $missing, array $slots): string
    {
        $hasPickup = filled($slots['pickup_location'] ?? null);
        $hasDestination = filled($slots['destination'] ?? null);
        if ($hasDestination && in_array('pickup_location', $missing, true)) {
            $labels = ['titik jemput'];
            if (in_array('passenger_name', $missing, true)) {
                $labels[] = 'nama penumpang';
            }
            if (in_array('passenger_count', $missing, true)) {
                $labels[] = 'jumlah penumpang';
            }

            return $this->chooseVariant(
                'ask_basic.pickup',
                [$missing, $slots['destination'] ?? null],
                [
                    'Baik, saya bantu cek ya. '.$this->detailRequest($labels, 'Supaya saya cek lebih lanjut'),
                    'Siap, saya bantu ya. '.$this->detailRequest($labels, 'Biar saya lanjut cek'),
                    'Oke, saya bantu lanjut ya. '.$this->detailRequest($labels, 'Supaya saya proses'),
                ],
            );
        }
        if ($hasPickup && in_array('destination', $missing, true)) {
            $labels = ['tujuan'];
            if (in_array('passenger_name', $missing, true)) {
                $labels[] = 'nama penumpang';
            }
            if (in_array('passenger_count', $missing, true)) {
                $labels[] = 'jumlah penumpang';
            }

            return $this->chooseVariant(
                'ask_basic.destination',
                [$missing, $slots['pickup_location'] ?? null],
                [
                    'Baik, saya bantu lanjut ya. '.$this->detailRequest($labels, 'Supaya saya cek lebih lanjut'),
                    'Siap, saya bantu ya. '.$this->detailRequest($labels, 'Biar saya lanjut proses'),
                    'Oke, saya bantu cek ya. '.$this->detailRequest($labels, 'Supaya saya lanjutkan'),
                ],
            );
        }
        if ($missing === ['pickup_location', 'destination']) {
            return $this->chooseVariant(
                'ask_basic.route_only',
                $missing,
                [
                    'Baik, saya bantu ya. Mohon kirim titik jemput dan tujuan perjalanannya.',
                    'Siap, saya bantu cek ya. Boleh kirim titik jemput dan tujuan perjalanannya dulu.',
                    'Oke, lanjut ya. Mohon kirim titik jemput dan tujuan perjalanannya.',
                ],
            );
        }
        if ($missing === ['passenger_name']) {
            return $this->chooseVariant(
                'ask_basic.passenger_name',
                $missing,
                [
                    'Baik, tinggal kirim nama penumpangnya ya.',
                    'Siap, tinggal kirim nama penumpangnya ya.',
                    'Oke, boleh kirim nama penumpangnya dulu ya.',
                ],
            );
        }
        if ($missing === ['passenger_count']) {
            return $this->chooseVariant(
                'ask_basic.passenger_count',
                $missing,
                [
                    'Baik, untuk keberangkatan ini ada berapa penumpang ya?',
                    'Siap, jumlah penumpangnya ada berapa orang ya?',
                    'Oke, boleh kirim jumlah penumpangnya ya.',
                ],
            );
        }
        if ($missing === ['passenger_name', 'passenger_count']) {
            return $this->chooseVariant(
                'ask_basic.passenger_name_count',
                $missing,
                [
                    'Baik, tinggal kirim nama penumpang dan jumlah penumpangnya ya.',
                    'Siap, boleh kirim nama penumpang dan jumlah penumpangnya dulu ya.',
                    'Oke, saya lanjut ya. Mohon kirim nama penumpang dan jumlah penumpangnya.',
                ],
            );
        }
        $labels = array_map(
            fn (string $slot) => self::SLOT_LABELS[$slot] ?? $slot,
            $missing,
        );

        return $this->chooseVariant(
            'ask_basic.default',
            $labels,
            [
                'Baik, supaya bisa saya proses, mohon kirim '.$this->joinLabels($labels).'.',
                'Siap, saya bantu ya. Boleh kirim '.$this->joinLabels($labels).' dulu.',
                'Oke, saya lanjutkan ya. Mohon kirim '.$this->joinLabels($labels).'.',
            ],
        );
    }

    public function askTravelDate(): string
    {
        return $this->chooseVariant(
            'ask_travel_date',
            [],
            [
                'Boleh kirim tanggal keberangkatannya ya?',
                'Tanggal keberangkatannya kapan ya?',
                'Untuk lanjut bookingnya, mohon kirim tanggal keberangkatannya ya.',
            ],
        );
    }

    public function askTravelTime(bool $ambiguous = false): string
    {
        $slots = '05.00, 08.00, 10.00, 14.00, 16.00, atau 19.00 WIB';
        if ($ambiguous) {
            return $this->chooseVariant(
                'ask_travel_time.ambiguous',
                [$ambiguous],
                [
                    'Baik, tanggalnya sudah saya catat. Biar tidak keliru, mohon kirim jam pastinya ya. Slot yang tersedia '.$slots.'.',
                    'Siap, tanggalnya sudah masuk ya. Supaya pas, mohon kirim jam yang dipilih. Slot yang tersedia '.$slots.'.',
                    'Oke, tanggalnya sudah saya catat. Sekarang mohon kirim jam keberangkatannya ya. Slot yang tersedia '.$slots.'.',
                ],
            );
        }

        return $this->chooseVariant(
            'ask_travel_time',
            [],
            [
                'Untuk jam berangkatnya, ingin yang jam berapa ya? Contoh: 08.00 atau 10.00.',
                'Jam keberangkatannya mau pilih yang jam berapa ya? Contoh: 08.00 atau 10.00.',
                'Boleh kirim jam keberangkatannya ya? Contoh: 08.00 atau 10.00.',
            ],
        );
    }

    public function askPaymentMethod(): string
    {
        return $this->chooseVariant(
            'ask_payment_method',
            [],
            [
                'Untuk pembayarannya, mau transfer bank, QRIS, atau cash ya?',
                'Metode pembayarannya ingin transfer bank, QRIS, atau cash ya?',
                'Baik, untuk pembayarannya pilih transfer bank, QRIS, atau cash ya?',
            ],
        );
    }

    public function askCorrection(): string
    {
        return $this->chooseVariant(
            'ask_correction',
            [],
            [
                'Baik, silakan kirim bagian data yang mau diubah ya. Nanti saya bantu update tanpa mulai dari awal.',
                'Siap, kirim saja data yang ingin diubah ya. Saya bantu perbarui tanpa mengulang dari awal.',
                'Oke, tinggal kirim bagian yang mau dikoreksi ya. Saya bantu update.',
            ],
        );
    }

    public function closing(): string
    {
        return $this->chooseVariant(
            'closing',
            [],
            [
                'Baik, terima kasih ya. Kalau nanti mau cek jadwal atau lanjut booking, tinggal chat lagi.',
                'Siap, terima kasih ya. Kalau ada yang mau dicek lagi, langsung kirim pesan saja.',
                'Oke, terima kasih ya. Kalau nanti ingin lanjut booking atau cek jadwal lain, tinggal hubungi lagi.',
            ],
        );
    }

    public function inProgressAcknowledgement(string $pendingPrompt): string
    {
        $nextStep = $this->normalizePendingPrompt($pendingPrompt);
        if ($nextStep === '') {
            $nextStep = 'tinggal kirim data lanjutnya ya.';
        }

        return $this->chooseVariant(
            'in_progress_acknowledgement',
            [$pendingPrompt],
            [
                'Baik, kalau sudah siap, '.$nextStep,
                'Siap, kalau sudah siap, '.$nextStep,
                'Oke, kalau sudah siap, '.$nextStep,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $signals
     * @param  array<int, string>  $routeSuggestions
     */
    public function fallbackForState(
        string $state,
        array $slots = [],
        ?string $pendingPrompt = null,
        array $signals = [],
        ?string $routeIssue = null,
        array $routeSuggestions = [],
    ): string {
        return match ($state) {
            BookingFlowState::CollectingRoute->value,
            BookingFlowState::CollectingPassenger->value,
            BookingFlowState::CollectingSchedule->value => $this->bookingFallbackFromPrompt(
                $pendingPrompt ?: $this->defaultFallbackPrompt($state, $slots),
            ),
            BookingFlowState::RouteUnavailable->value => $this->routeUnavailableFallback(
                $routeIssue ?? 'pickup_location',
                $routeSuggestions,
            ),
            BookingFlowState::ReadyToConfirm->value => $this->confirmationFallback(),
            BookingFlowState::Confirmed->value => $this->confirmedConversationFallback(),
            BookingFlowState::Closed->value => $this->closedConversationFallback($signals),
            default => $this->generalFallback($signals),
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public function generalFallback(array $signals = []): string
    {
        if (($signals['schedule_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.schedule',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu cek jadwal ya. Boleh kirim titik jemput, tujuan, dan tanggal keberangkatannya?',
                    'Siap, saya bantu cek jadwal. Mohon kirim titik jemput, tujuan, dan tanggal keberangkatannya ya.',
                    'Oke, saya bantu cek. Supaya pas, kirim titik jemput, tujuan, dan tanggal keberangkatannya ya.',
                ],
            );
        }

        if (($signals['price_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.price',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu cek harga ya. Mohon kirim titik jemput, tujuan, dan jumlah penumpangnya.',
                    'Siap, saya bantu cek harga. Boleh kirim titik jemput, tujuan, dan jumlah penumpangnya ya?',
                    'Oke, saya bantu cek tarifnya. Supaya tidak salah, kirim titik jemput, tujuan, dan jumlah penumpangnya ya.',
                ],
            );
        }

        if (($signals['booking_keyword'] ?? false) === true || ($signals['route_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.booking',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu. Supaya tidak salah, mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya.',
                    'Siap, saya bantu ya. Boleh kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya dulu?',
                    'Oke, saya bantu lanjut. Mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                ],
            );
        }

        return $this->chooseVariant(
            'general_fallback',
            array_keys(array_filter($signals)),
            [
                'Baik, saya bantu ya. Kalau terkait travel, boleh kirim rute, jadwal, atau detail yang ingin dicek?',
                'Siap, saya bantu cek ya. Silakan kirim detail perjalanan yang ingin ditanyakan.',
                'Oke, saya bantu. Boleh jelaskan lagi kebutuhan perjalanannya ya?',
            ],
        );
    }

    public function confirmed(): string
    {
        return $this->chooseVariant(
            'confirmed',
            [],
            [
                'Baik, data perjalanannya sudah saya catat ya. Admin kami lanjut hubungi Anda di WhatsApp ini untuk proses berikutnya.',
                'Siap, data bookingnya sudah masuk ya. Admin kami akan lanjut hubungi Anda lewat WhatsApp ini.',
                'Oke, data perjalanan sudah tercatat ya. Admin kami lanjut proses dan akan hubungi Anda di WhatsApp ini.',
            ],
        );
    }

    public function reviewSummary(BookingRequest $booking): string
    {
        $fare = $booking->price_estimate !== null
            ? (int) round((float) $booking->price_estimate)
            : $this->fareCalculator->calculate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );
        $lines = [
            $this->chooseVariant(
                'review_summary_intro',
                [
                    $booking->pickup_location,
                    $booking->destination,
                    $booking->passenger_count,
                ],
                [
                    'Baik, saya rangkum dulu data perjalanannya ya:',
                    'Siap, saya rangkum dulu detail perjalanannya ya:',
                    'Oke, saya cek lagi data perjalanannya ya:',
                ],
            ),
            '- titik jemput: '.($booking->pickup_location ?? '-'),
            '- tujuan: '.($booking->destination ?? '-'),
            '- nama penumpang: '.($booking->passenger_name ?? '-'),
            '- jumlah penumpang: '.(($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count.' orang' : '-'),
            '- tanggal keberangkatan: '.($booking->departure_date?->translatedFormat('d F Y') ?? '-'),
            '- jam berangkat: '.($booking->departure_time ? $booking->departure_time.' WIB' : '-'),
            '- metode pembayaran: '.$this->paymentMethodLabel($booking->payment_method),
        ];
        if ($fare !== null) {
            $lines[] = '- estimasi total: '.$this->fareCalculator->formatRupiah($fare);
        }
        $lines[] = '';
        $lines[] = $this->chooseVariant(
            'review_summary_confirm',
            [$booking->pickup_location, $booking->destination],
            [
                'Kalau datanya sudah sesuai, balas YA atau BENAR ya.',
                'Kalau sudah cocok, cukup balas YA atau BENAR ya.',
                'Kalau semuanya sudah benar, balas YA atau BENAR ya.',
            ],
        );
        $lines[] = $this->chooseVariant(
            'review_summary_edit',
            [$booking->pickup_location, $booking->destination],
            [
                'Kalau ada yang mau diubah, tinggal kirim bagian yang benar saja.',
                'Kalau ada yang perlu diubah, kirim koreksinya saja ya.',
                'Kalau masih ada yang salah, tinggal kirim perbaikannya ya.',
            ],
        );

        return implode("\n", $lines);
    }

    public function scheduleLine(): string
    {
        return $this->chooseVariant(
            'schedule_line',
            [],
            [
                'Jadwal keberangkatan tersedia setiap hari di jam 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
                'Untuk jadwal, kami tersedia setiap hari di jam 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
                'Jadwal travel tersedia setiap hari pada pukul 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
            ],
        );
    }

    public function priceLine(?string $pickup, ?string $destination, ?int $passengerCount = null): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }
        $unitFare = $this->fareCalculator->unitFare($pickup, $destination);
        if ($unitFare === null) {
            return null;
        }
        $text = $this->chooseVariant(
            'price_line',
            [$pickup, $destination, $passengerCount],
            [
                'Untuk rute '.$pickup.' ke '.$destination.', tarifnya saat ini '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
                'Tarif rute '.$pickup.' ke '.$destination.' saat ini '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
                'Kalau untuk rute '.$pickup.' ke '.$destination.', tarifnya '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
            ],
        );
        if (($passengerCount ?? 0) > 1) {
            $totalFare = $this->fareCalculator->calculate($pickup, $destination, $passengerCount);
            if ($totalFare !== null) {
                $text .= $this->chooseVariant(
                    'price_line_total',
                    [$passengerCount, $totalFare],
                    [
                        ' Estimasi total untuk '.$passengerCount.' penumpang sekitar '.$this->fareCalculator->formatRupiah($totalFare).'.',
                        ' Kalau '.$passengerCount.' penumpang, estimasi totalnya '.$this->fareCalculator->formatRupiah($totalFare).'.',
                        ' Total perkiraannya untuk '.$passengerCount.' penumpang sekitar '.$this->fareCalculator->formatRupiah($totalFare).'.',
                    ],
                );
            }
        }

        return $text;
    }

    public function routeListReply(): string
    {
        $locations = $this->routeValidator->menuLocations();

        return $this->chooseVariant(
            'route_list_reply',
            $locations,
            [
                'Titik jemput dan tujuan yang tersedia saat ini antara lain '.$this->joinLabels($locations).'. Kalau mau lanjut booking, silakan kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
                'Untuk titik jemput dan tujuan yang tersedia saat ini ada '.$this->joinLabels($locations).'. Kalau mau lanjut, kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
                'Lokasi yang tersedia saat ini antara lain '.$this->joinLabels($locations).'. Kalau ingin lanjut booking, tinggal kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
            ],
        );
    }

    public function paymentMethodLabel(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }
        foreach ((array) config('chatbot.jet.payment_methods', []) as $method) {
            if (($method['id'] ?? null) === $value) {
                return (string) ($method['label'] ?? $value);
            }
        }

        return mb_convert_case((string) $value, MB_CASE_TITLE, 'UTF-8');
    }

    private function detailRequest(array $labels, string $context): string
    {
        return $context.', mohon kirim '.$this->joinLabels($labels).'.';
    }

    private function normalizePendingPrompt(string $pendingPrompt): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $pendingPrompt) ?? $pendingPrompt);
        $text = $this->trimKnownLeads($text);

        return $this->lowercaseFirst($text);
    }

    private function bookingFallbackFromPrompt(string $prompt): string
    {
        $request = $this->fallbackRequestText($prompt);

        return $this->chooseVariant(
            'booking_state_fallback',
            [$prompt],
            [
                'Maaf, saya belum menangkap detailnya dengan jelas. '.$this->uppercaseFirst($request),
                'Baik, saya bantu. Supaya tidak salah, '.$this->lowercaseFirst($request),
                'Maaf ya, biar tidak keliru, '.$this->lowercaseFirst($request),
            ],
        );
    }

    /**
     * @param  array<int, string>  $suggestions
     */
    private function routeUnavailableFallback(string $routeIssue, array $suggestions = []): string
    {
        $target = $routeIssue === 'destination'
            ? 'tujuan lain yang tersedia'
            : 'titik jemput lain yang tersedia';

        $text = $this->chooseVariant(
            'route_unavailable_fallback.'.$routeIssue,
            [$routeIssue, $suggestions],
            [
                'Maaf, untuk lanjut saya perlu '.$target.' ya.',
                'Baik, supaya saya bisa cek lagi, mohon kirim '.$target.'.',
                'Maaf ya, biar saya bantu lanjut, kirim '.$target.' ya.',
            ],
        );

        if ($suggestions !== []) {
            $text .= ' Contohnya '.$this->joinLabels(array_slice($suggestions, 0, 4)).'.';
        }

        return $text;
    }

    private function confirmationFallback(): string
    {
        return $this->chooseVariant(
            'confirmation_fallback',
            [],
            [
                'Maaf, saya belum menangkap jawabannya dengan jelas. Kalau datanya sudah sesuai, balas YA atau BENAR ya. Kalau ada yang mau diubah, kirim bagian yang benar saja.',
                'Baik, supaya tidak salah, kalau datanya sudah sesuai balas YA atau BENAR ya. Kalau ada yang perlu diubah, kirim koreksinya saja.',
                'Maaf ya, biar jelas, kalau datanya sudah cocok balas YA atau BENAR ya. Kalau ada yang ingin diubah, kirim bagian yang benar saja.',
            ],
        );
    }

    private function confirmedConversationFallback(): string
    {
        return $this->chooseVariant(
            'confirmed_conversation_fallback',
            [],
            [
                'Baik, booking sebelumnya sudah kami catat ya. Kalau mau cek perjalanan lain, tinggal kirim rute dan tanggal keberangkatannya.',
                'Siap, data booking sebelumnya sudah masuk ya. Kalau ingin cek jadwal atau perjalanan lain, kirim detailnya saja.',
                'Oke, booking sebelumnya sudah tercatat ya. Kalau mau lanjut cek perjalanan lain, tinggal kirim rutenya.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function closedConversationFallback(array $signals = []): string
    {
        if (($signals['booking_keyword'] ?? false) === true || ($signals['route_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'closed_fallback.booking',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu mulai lagi ya. Mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya.',
                    'Siap, kalau mau lanjut lagi, boleh kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                    'Oke, saya bantu lanjut lagi. Kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                ],
            );
        }

        return $this->chooseVariant(
            'closed_fallback',
            array_keys(array_filter($signals)),
            [
                'Baik, kalau mau lanjut lagi, tinggal kirim detail perjalanan yang ingin dicek ya.',
                'Siap, kalau ada yang mau dicek lagi, langsung kirim rute atau jadwalnya ya.',
                'Oke, kalau ingin lanjut lagi, tinggal kirim detail perjalanannya ya.',
            ],
        );
    }

    private function defaultFallbackPrompt(string $state, array $slots): string
    {
        return match ($state) {
            BookingFlowState::CollectingRoute->value => $this->askBasicDetails(
                ['pickup_location', 'destination'],
                $slots,
            ),
            BookingFlowState::CollectingPassenger->value => $this->askBasicDetails(
                array_values(array_filter([
                    empty($slots['passenger_name']) ? 'passenger_name' : null,
                    empty($slots['passenger_count']) ? 'passenger_count' : null,
                ])),
                $slots,
            ),
            BookingFlowState::CollectingSchedule->value => $this->askTravelDate(),
            default => $this->askBasicDetails(['pickup_location', 'destination'], $slots),
        };
    }

    private function fallbackRequestText(string $prompt): string
    {
        $text = preg_replace('/^Tinggal\s+/ui', '', $this->followUpPrompt($prompt)) ?? $this->followUpPrompt($prompt);

        return rtrim(trim($text), '.').'.';
    }

    private function mergeFactsWithPrompt(array $facts, string $prompt): string
    {
        $facts = array_values(array_filter(array_map(
            fn (mixed $fact) => is_string($fact) ? trim($fact) : '',
            $facts,
        )));

        if ($facts === []) {
            return $prompt;
        }

        $lead = implode(' ', array_map(
            fn (string $fact) => rtrim($fact, ".!?\t\n\r\0\x0B").'.',
            $facts,
        ));

        return trim($lead.' '.$this->followUpPrompt($prompt));
    }

    private function followUpPrompt(string $prompt): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        $text = $this->trimKnownLeads($text);
        $text = preg_replace('/^(?:Supaya|Biar)\s+saya\s+(?:cek|lanjut\s+cek|lanjut\s+proses|proses|lanjutkan),\s*/ui', '', $text) ?? $text;
        $text = preg_replace('/^Untuk\s+lanjut\s+bookingnya,\s*/ui', '', $text) ?? $text;

        if (preg_match('/^Tanggal keberangkatannya kapan ya\?$/ui', $text)) {
            return 'Tinggal mohon kirim tanggal keberangkatannya ya.';
        }

        if (preg_match('/^(?:Untuk jam berangkatnya|Jam keberangkatannya).+?(Contoh:\s*.+)$/ui', $text, $matches)) {
            return 'Tinggal kirim jam keberangkatannya ya. '.$matches[1];
        }

        if (preg_match('/^Boleh kirim jam keberangkatannya ya\?\s*(Contoh:\s*.+)$/ui', $text, $matches)) {
            return 'Tinggal kirim jam keberangkatannya ya. '.$matches[1];
        }

        if (preg_match('/^(?:Untuk pembayarannya|Metode pembayarannya|Baik, untuk pembayarannya).+$/ui', $text)) {
            return 'Tinggal pilih metode pembayarannya ya: transfer bank, QRIS, atau cash.';
        }

        if (preg_match('/^(?:Mohon|Boleh)\s+kirim\b/ui', $text)) {
            $tail = preg_replace('/^(?:Mohon|Boleh)\s+kirim\s*/ui', '', $text) ?? $text;
            $tail = rtrim($tail, " \t\n\r\0\x0B?.").'.';

            return 'Tinggal mohon kirim '.$tail;
        }

        if (preg_match('/^tinggal\b/ui', $text)) {
            return 'Tinggal '.$this->lowercaseFirst(preg_replace('/^tinggal\s*/ui', '', $text) ?? $text);
        }

        return 'Tinggal '.$this->lowercaseFirst(rtrim($text, '.')).'.';
    }

    private function shouldIncludeScheduleLine(?string $scheduleLine, string $prompt): bool
    {
        if ($scheduleLine === null) {
            return false;
        }

        return ! (bool) preg_match('/\b(tanggal keberangkatan|jam berangkat|jam keberangkatan)\b/ui', $prompt);
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function composeReplyParts(array $parts): string
    {
        return implode("\n\n", array_values(array_filter(array_map(
            fn (mixed $part) => is_string($part) ? trim($part) : '',
            $parts,
        ))));
    }

    private function trimKnownLeads(string $text): string
    {
        $knownLeads = [
            'Baik, saya bantu ya. ',
            'Baik, saya bantu cek ya. ',
            'Baik, saya bantu lanjut ya. ',
            'Baik, saya bantu lanjutkan ya. ',
            'Siap, saya bantu ya. ',
            'Siap, saya bantu cek ya. ',
            'Siap, saya bantu lanjut ya. ',
            'Siap, saya bantu lanjutkan ya. ',
            'Oke, saya bantu ya. ',
            'Oke, saya bantu cek ya. ',
            'Oke, saya bantu lanjut ya. ',
            'Oke, saya bantu lanjutkan ya. ',
            'Baik, tinggal ',
            'Siap, tinggal ',
            'Oke, tinggal ',
        ];

        foreach ($knownLeads as $lead) {
            if (str_starts_with($text, $lead)) {
                return substr($text, strlen($lead));
            }
        }

        return $text;
    }

    private function displayValue(string $slot, mixed $value): string
    {
        return match ($slot) {
            'passenger_count' => (int) $value.' orang',
            'travel_date' => $this->formatDateValue($value),
            'travel_time' => (string) $value.' WIB',
            'payment_method' => $this->paymentMethodLabel(is_string($value) ? $value : null),
            default => (string) $value,
        };
    }

    private function slotSummaryFragment(string $slot, mixed $value): string
    {
        $display = $this->displayValue($slot, $value);

        return match ($slot) {
            'pickup_location' => 'titik jemput '.$display,
            'destination' => 'tujuan '.$display,
            'passenger_name' => 'nama penumpang '.$display,
            'passenger_count' => 'jumlah '.$display,
            'travel_date' => 'tanggal '.$display,
            'travel_time' => 'jam '.$display,
            'payment_method' => 'metode pembayaran '.$display,
            default => (self::SLOT_LABELS[$slot] ?? $slot).' '.$display,
        };
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(array_map(
            fn (mixed $label) => is_string($label) ? trim($label) : '',
            $labels,
        )));
        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        if (count($labels) === 2) {
            return $labels[0].' dan '.$labels[1];
        }
        $last = array_pop($labels);

        return implode(', ', $labels).', dan '.$last;
    }

    private function lowercaseFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $first = mb_substr($text, 0, 1, 'UTF-8');
        $rest = mb_substr($text, 1, null, 'UTF-8');

        return mb_strtolower($first, 'UTF-8').$rest;
    }

    private function uppercaseFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $first = mb_substr($text, 0, 1, 'UTF-8');
        $rest = mb_substr($text, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8').$rest;
    }

    private function formatDateValue(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return (string) $value;
        }

        try {
            return Carbon::parse($value)->translatedFormat('d F Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<int|string, mixed>  $context
     * @param  array<int, string>  $variants
     */
    private function chooseVariant(string $group, array $context, array $variants): string
    {
        if ($variants === []) {
            return '';
        }
        if (count($variants) === 1) {
            return $variants[0];
        }
        $payload = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = sha1($group.'|'.$payload);
        $index = hexdec(substr($hash, 0, 6)) % count($variants);

        return $variants[$index];
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
```

## app/Services/Booking/BookingSlotExtractorService.php

```php
<?php

namespace App\Services\Booking;

use App\Services\Support\PhoneNumberService;
use Illuminate\Support\Carbon;

class BookingSlotExtractorService
{
    /**
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'satu' => 1,
        'dua' => 2,
        'tiga' => 3,
        'empat' => 4,
        'lima' => 5,
        'enam' => 6,
        'tujuh' => 7,
        'delapan' => 8,
        'sembilan' => 9,
        'sepuluh' => 10,
    ];

    private const AFFIRMATIONS = [
        'ya', 'iya', 'benar', 'sudah', 'oke', 'ok', 'siap', 'sesuai', 'mantap', 'lanjut',
    ];

    private const REJECTIONS = [
        'tidak', 'nggak', 'enggak', 'bukan', 'salah', 'ubah', 'ganti', 'koreksi', 'batal',
    ];

    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly PhoneNumberService $phoneService,
    ) {}

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  array<string, mixed>  $entityResult
     * @return array{updates: array<string, mixed>, signals: array<string, mixed>}
     */
    public function extract(
        string $messageText,
        array $currentSlots,
        array $entityResult,
        ?string $expectedInput,
        string $senderPhone,
    ): array {
        $text = trim($messageText);
        $normalized = $this->normalizeText($text);

        $signals = [
            'greeting_detected' => $this->isGreeting($normalized),
            'salam_type' => $this->hasIslamicGreeting($normalized) ? 'islamic' : null,
            'greeting_only' => $this->isGreetingOnly($normalized),
            'booking_keyword' => (bool) preg_match('/\b(book|booking|pesan|travel|berangkat|keberangkatan|jemput|antar|tujuan|rute)\b/u', $normalized),
            'schedule_keyword' => (bool) preg_match('/\b(jadwal|jam|slot|berangkat|keberangkatan|pagi|siang|sore|malam)\b/u', $normalized),
            'price_keyword' => (bool) preg_match('/\b(harga|ongkos|tarif|biaya)\b/u', $normalized),
            'route_keyword' => (bool) preg_match('/\b(rute|trayek|tujuan|lokasi|jemput|antar)\b/u', $normalized),
            'human_keyword' => (bool) preg_match('/\b(admin|manusia|operator|cs|customer service)\b/u', $normalized),
            'affirmation' => $this->matchesVocabulary($normalized, self::AFFIRMATIONS),
            'rejection' => $this->matchesVocabulary($normalized, self::REJECTIONS),
            'close_intent' => $this->isCloseIntent($normalized),
            'gratitude' => (bool) preg_match('/\b(makasih|terima kasih|thanks|thank you)\b/u', $normalized),
            'acknowledgement' => (bool) preg_match('/\b(ok|oke|baik|siap|sip|noted)\b/u', $normalized),
            'time_ambiguous' => false,
        ];

        $updates = [];

        $routeUpdates = $this->extractRouteSlots($text, $normalized, $expectedInput, $entityResult);

        if ($routeUpdates['pickup_location'] !== null) {
            $updates['pickup_location'] = $routeUpdates['pickup_location'];
        }

        if ($routeUpdates['destination'] !== null) {
            $updates['destination'] = $routeUpdates['destination'];
        }

        $passengerName = $this->extractPassengerName($text, $expectedInput, $entityResult);

        if ($passengerName !== null) {
            $updates['passenger_name'] = $passengerName;
        }

        $passengerCount = $this->extractPassengerCount($normalized, $expectedInput, $entityResult);

        if ($passengerCount !== null) {
            $updates['passenger_count'] = $passengerCount;
        }

        $date = $this->extractDate($text, $entityResult);

        if ($date !== null) {
            $updates['travel_date'] = $date;
        }

        $timeResult = $this->extractTime($normalized, $expectedInput, $entityResult);

        if ($timeResult['time'] !== null) {
            $updates['travel_time'] = $timeResult['time'];
        }

        $signals['time_ambiguous'] = $timeResult['ambiguous'];

        $paymentMethod = $this->extractPaymentMethod($normalized, $expectedInput, $entityResult);

        if ($paymentMethod !== null) {
            $updates['payment_method'] = $paymentMethod;
        }

        if (($currentSlots['contact_number'] ?? null) === null) {
            $contactNumber = $this->extractPhoneNumber($text);

            if ($contactNumber !== null && $contactNumber !== $senderPhone) {
                $updates['contact_number'] = $contactNumber;
                $updates['contact_same_as_sender'] = false;
            }
        }

        return [
            'updates' => $updates,
            'signals' => $signals,
        ];
    }

    public function isCloseIntent(string $normalizedText): bool
    {
        $closeWords = array_map(
            fn (mixed $phrase) => $this->normalizeText((string) $phrase),
            config('chatbot.guards.close_intents', []),
        );

        return in_array($normalizedText, $closeWords, true);
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @return array{pickup_location: string|null, destination: string|null}
     */
    public function extractRouteSlots(
        string $messageText,
        string $normalizedText,
        ?string $expectedInput,
        array $entityResult = [],
    ): array {
        $pickup = $this->normalizeLocationValue($entityResult['pickup_location'] ?? null);
        $destination = $this->normalizeLocationValue($entityResult['destination'] ?? null);

        $pickup ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:titik\s+jemput(?:nya)?|lokasi\s+jemput(?:nya)?|pickup(?:\s+location)?|penjemputan)\s*(?:di|=|:)?\s*(.+)$/ui',
            '/\b(?:asal(?:nya)?|dari)\s+(.+?)\s+(?:ke|menuju)\b/ui',
            '/\b(?:jemput(?:nya)?\s+di)\s+(.+)$/ui',
        ]);

        $destination ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:tujuan(?:nya)?|destinasi|antar(?:nya)?)\s*(?:ke|=|:)?\s*(.+)$/ui',
            '/\b(?:ke|menuju)\s+(.+)$/ui',
        ]);

        if ($pickup === null || $destination === null) {
            $routePair = $this->extractCompactRoutePair($messageText);
            $pickup ??= $routePair['pickup_location'];
            $destination ??= $routePair['destination'];
        }

        if ($expectedInput === 'pickup_location' && $pickup === null) {
            $pickup = $this->extractMenuLocation($normalizedText) ?? $this->extractLooseLocation($messageText);
        }

        if ($expectedInput === 'destination' && $destination === null) {
            $destination = $this->extractMenuLocation($normalizedText) ?? $this->extractLooseLocation($messageText);
        }

        return [
            'pickup_location' => $pickup,
            'destination' => $destination,
        ];
    }

    private function extractPassengerName(string $messageText, ?string $expectedInput, array $entityResult): ?string
    {
        $fromEntity = $entityResult['customer_name'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            return $this->normalizePassengerName($fromEntity);
        }

        if (
            preg_match(
                '/\b(?:nama(?:\s+penumpang(?:nya)?)?|atas\s+nama|a\/n)\s*(?:adalah|=|:)?\s*([a-z][\p{L}\s\'.-]{1,60}?)(?=(?:\s*,|\s+jumlah\b|\s+\d+\s*(?:orang|penumpang)\b|\s+tanggal\b|\s+jam\b|\s+besok\b|\s+lusa\b|\s+hari\s+ini\b|\s+(?:metode|bayar)\b|$))/ui',
                $messageText,
                $matches,
            )
        ) {
            return $this->normalizePassengerName($matches[1] ?? null);
        }

        if ($expectedInput === 'passenger_name') {
            return $this->normalizePassengerName($messageText);
        }

        return null;
    }

    private function extractPassengerCount(string $normalizedText, ?string $expectedInput, array $entityResult): ?int
    {
        $entityCount = $entityResult['passenger_count'] ?? null;

        if (is_int($entityCount) && $entityCount > 0) {
            return $entityCount;
        }

        if ($expectedInput === 'passenger_count' && preg_match('/^\d{1,2}$/', $normalizedText)) {
            return (int) $normalizedText;
        }

        if (
            preg_match(
                '/\b(?:jumlah(?:nya)?|penumpang(?:nya)?|orang(?:nya)?)\s*(?:adalah|=|:)?\s*(\d{1,2}|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b/u',
                $normalizedText,
                $matches,
            )
        ) {
            return $this->countFromToken($matches[1] ?? null);
        }

        if (preg_match('/\b(\d{1,2})\s*(orang|penumpang|org)\b/u', $normalizedText, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\bsendiri\b/u', $normalizedText)) {
            return 1;
        }

        if (preg_match('/\bberdua\b/u', $normalizedText)) {
            return 2;
        }

        foreach (self::NUMBER_WORDS as $token => $count) {
            if (preg_match('/\b'.preg_quote($token, '/').'\s*(orang|penumpang)\b/u', $normalizedText)) {
                return $count;
            }
        }

        if ($expectedInput === 'passenger_count') {
            foreach (self::NUMBER_WORDS as $token => $count) {
                if ($normalizedText === $token) {
                    return $count;
                }
            }

            if (preg_match('/^\d{1,2}\s*(orang|penumpang|org)?$/u', $normalizedText, $matches)) {
                return (int) preg_replace('/\D/u', '', $matches[0]);
            }
        }

        return null;
    }

    private function extractDate(string $messageText, array $entityResult): ?string
    {
        $fromEntity = $entityResult['departure_date'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            try {
                return Carbon::parse($fromEntity, $this->timezone())->toDateString();
            } catch (\Throwable) {
            }
        }

        $text = $this->normalizeText($messageText);
        $now = Carbon::now($this->timezone());

        if (str_contains($text, 'hari ini')) {
            return $now->toDateString();
        }

        if (str_contains($text, 'besok')) {
            return $now->copy()->addDay()->toDateString();
        }

        if (str_contains($text, 'lusa')) {
            return $now->copy()->addDays(2)->toDateString();
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $matches)) {
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;
            $year = $year < 100 ? 2000 + $year : $year;

            try {
                return Carbon::createSafe($year, (int) $matches[2], (int) $matches[1], 0, 0, 0, $this->timezone())
                    ->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\btanggal\s+(\d{1,2})(?:\s+([a-z]+))?(?:\s+(\d{4}))?\b/u', $text, $matches)) {
            $day = (int) $matches[1];
            $month = $this->monthFromText($matches[2] ?? '') ?? $now->month;
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;

            try {
                return Carbon::createSafe($year, $month, $day, 0, 0, 0, $this->timezone())
                    ->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(\d{1,2})\s+([a-z]+)(?:\s+(\d{4}))?\b/u', $text, $matches)) {
            $day = (int) $matches[1];
            $month = $this->monthFromText($matches[2] ?? '');
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;

            if ($month !== null) {
                try {
                    return Carbon::createSafe($year, $month, $day, 0, 0, 0, $this->timezone())
                        ->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @return array{time: string|null, ambiguous: bool}
     */
    private function extractTime(string $normalizedText, ?string $expectedInput, array $entityResult): array
    {
        $fromEntity = $entityResult['departure_time'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            $resolved = $this->matchTimeToSlot($fromEntity);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if ($expectedInput === 'travel_time' && ctype_digit($normalizedText)) {
            $fromMenu = $this->departureTimeByOrder((int) $normalizedText);

            if ($fromMenu !== null) {
                return ['time' => $fromMenu, 'ambiguous' => false];
            }
        }

        foreach ($this->departureSlots() as $slot) {
            foreach ($slot['aliases'] ?? [] as $alias) {
                if (! is_string($alias) || $alias === '') {
                    continue;
                }

                $normalizedAlias = $this->normalizeText($alias);

                if (
                    ctype_digit($normalizedAlias)
                    && $expectedInput !== 'travel_time'
                    && $normalizedText !== $normalizedAlias
                ) {
                    continue;
                }

                if (str_contains(' '.$normalizedText.' ', ' '.$normalizedAlias.' ')) {
                    return ['time' => (string) ($slot['time'] ?? ''), 'ambiguous' => false];
                }
            }
        }

        if (preg_match('/\bjam\s+([01]?\d|2[0-3])(?:(?:[:.])([0-5]\d))?\b/u', $normalizedText, $matches)) {
            $minute = $matches[2] ?? '00';
            $resolved = $this->matchTimeToSlot($matches[1].':'.$minute);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\b([01]?\d|2[0-3])(?:[:.])([0-5]\d)\b/u', $normalizedText, $matches)) {
            $resolved = $this->matchTimeToSlot($matches[1].':'.$matches[2]);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\bsubuh\b/u', $normalizedText)) {
            return ['time' => '05:00', 'ambiguous' => false];
        }

        if (preg_match('/\bsiang\b/u', $normalizedText)) {
            return ['time' => '14:00', 'ambiguous' => false];
        }

        if (preg_match('/\bsore\b/u', $normalizedText)) {
            return ['time' => '16:00', 'ambiguous' => false];
        }

        if (preg_match('/\bmalam\b/u', $normalizedText)) {
            return ['time' => '19:00', 'ambiguous' => false];
        }

        if (preg_match('/\bpagi\b/u', $normalizedText)) {
            return ['time' => null, 'ambiguous' => true];
        }

        return ['time' => null, 'ambiguous' => false];
    }

    private function extractPaymentMethod(string $normalizedText, ?string $expectedInput, array $entityResult): ?string
    {
        $fromEntity = $entityResult['payment_method'] ?? null;

        if (is_string($fromEntity)) {
            $resolved = $this->normalizePaymentMethod($fromEntity);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach ((array) config('chatbot.jet.payment_methods', []) as $index => $method) {
            $aliases = array_merge(
                [(string) ($method['id'] ?? '')],
                is_array($method['aliases'] ?? null) ? $method['aliases'] : [],
            );

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                if (str_contains(' '.$normalizedText.' ', ' '.$this->normalizeText($alias).' ')) {
                    return (string) ($method['id'] ?? null);
                }
            }

            if ($expectedInput === 'payment_method' && ctype_digit($normalizedText) && ((int) $normalizedText) === ($index + 1)) {
                return (string) ($method['id'] ?? null);
            }
        }

        return null;
    }

    private function extractPhoneNumber(string $messageText): ?string
    {
        if (! preg_match('/(?:\+?62|0)\d{8,15}/', $messageText, $matches)) {
            return null;
        }

        $normalized = $this->phoneService->toE164($matches[0]);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function extractLabeledLocation(string $messageText, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $messageText, $matches)) {
                continue;
            }

            $candidate = $this->cleanLocationCapture((string) ($matches[1] ?? ''));

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{pickup_location: string|null, destination: string|null}
     */
    private function extractCompactRoutePair(string $messageText): array
    {
        if (preg_match('/\bdari\s+(.+?)\s+ke\s+(.+)$/ui', $messageText, $matches)) {
            return [
                'pickup_location' => $this->cleanLocationCapture((string) ($matches[1] ?? '')),
                'destination' => $this->cleanLocationCapture((string) ($matches[2] ?? '')),
            ];
        }

        $knownLocations = [];

        foreach ($this->routeValidator->allKnownLocations() as $location) {
            $needle = ' '.$this->normalizeText($location).' ';
            $haystack = ' '.$this->normalizeText($messageText).' ';
            $position = mb_strpos($haystack, $needle);

            if ($position !== false) {
                $knownLocations[] = [
                    'location' => $location,
                    'position' => $position,
                ];
            }
        }

        usort($knownLocations, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        $ordered = array_values(array_unique(array_map(
            fn (array $item) => $item['location'],
            $knownLocations,
        )));

        if (count($ordered) >= 2) {
            return [
                'pickup_location' => $this->routeValidator->normalizeLocation($ordered[0]),
                'destination' => $this->routeValidator->normalizeLocation($ordered[1]),
            ];
        }

        if (count($ordered) === 1) {
            $single = $ordered[0];
            $normalized = $this->normalizeText($messageText);
            $singleKey = $this->normalizeText($single);

            if (preg_match('/\bke\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return [
                    'pickup_location' => null,
                    'destination' => $this->routeValidator->normalizeLocation($single),
                ];
            }

            if (preg_match('/\b(dari|jemput di|asal)\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return [
                    'pickup_location' => $this->routeValidator->normalizeLocation($single),
                    'destination' => null,
                ];
            }
        }

        return [
            'pickup_location' => null,
            'destination' => null,
        ];
    }

    private function extractLooseLocation(string $messageText): ?string
    {
        $candidate = trim($messageText);

        if ($candidate === '' || mb_strlen($candidate) > 60) {
            return null;
        }

        if (preg_match('/\b(nama|jumlah|tanggal|jam|metode|bayar|penumpang)\b/ui', $candidate)) {
            return null;
        }

        return $this->normalizeLocationValue($candidate);
    }

    private function cleanLocationCapture(string $value): ?string
    {
        $clean = trim($value, " \t\n\r\0\x0B,.;:-");
        $clean = preg_replace('/(?:,|;)\s*(?:tujuan(?:nya)?|destinasi|antar(?:nya)?|nama|jumlah|penumpang|tanggal|jam|metode|bayar)\b.*$/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+(?:ke|menuju)\s+[a-z][\p{L}\s.-]*$/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(apakah|ada|tersedia|ya|kak|admin|min|dong|nih|untuk|tanggal|jam|jumlah|nama|metode|bayar|besok|lusa|hari ini|pagi|siang|sore|malam)\b.*$/ui', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.;:-");

        if ($clean === '' || mb_strlen($clean) < 3) {
            return null;
        }

        return $this->normalizeLocationValue($clean);
    }

    private function normalizeLocationValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->routeValidator->normalizeLocation($value);
    }

    private function normalizePassengerName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        $clean = preg_replace('/^(?:nama(?:\s+saya)?|atas\s+nama|a\/n)\s*/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/^(?:saya|sy|aku)\s+/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(jumlah|tanggal|jam|metode|bayar)\b.*$/ui', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.;:-");

        if ($clean === '' || mb_strlen($clean) > 60) {
            return null;
        }

        if (preg_match('/\d/', $clean)) {
            return null;
        }

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    private function extractMenuLocation(string $normalizedText): ?string
    {
        if (! ctype_digit($normalizedText)) {
            return null;
        }

        return $this->routeValidator->menuLocationByOrder((int) $normalizedText);
    }

    private function countFromToken(mixed $token): ?int
    {
        if (is_string($token) && ctype_digit($token)) {
            return (int) $token;
        }

        return is_string($token) ? (self::NUMBER_WORDS[$token] ?? null) : null;
    }

    private function normalizePaymentMethod(string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        foreach ((array) config('chatbot.jet.payment_methods', []) as $method) {
            $aliases = array_merge(
                [(string) ($method['id'] ?? '')],
                is_array($method['aliases'] ?? null) ? $method['aliases'] : [],
            );

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                if ($normalized === $this->normalizeText($alias)) {
                    return (string) ($method['id'] ?? null);
                }
            }
        }

        return null;
    }

    private function hasIslamicGreeting(string $normalizedText): bool
    {
        return (bool) preg_match('/\b(assalamualaikum|assalamu alaikum|ass wr wb|ass wr\. wb|salam)\b/u', $normalizedText);
    }

    private function isGreeting(string $normalizedText): bool
    {
        return $this->hasIslamicGreeting($normalizedText)
            || (bool) preg_match('/\b(halo|hai|hello|selamat pagi|selamat siang|selamat sore|selamat malam)\b/u', $normalizedText);
    }

    private function isGreetingOnly(string $normalizedText): bool
    {
        return $this->isGreeting($normalizedText)
            && ! preg_match('/\b(harga|ongkos|jadwal|pesan|booking|berangkat|jemput|antar|rute|tujuan)\b/u', $normalizedText);
    }

    /**
     * @param  array<int, string>  $vocabulary
     */
    private function matchesVocabulary(string $normalizedText, array $vocabulary): bool
    {
        foreach ($vocabulary as $word) {
            if ($normalizedText === $word || preg_match('/\b'.preg_quote($word, '/').'\b/u', $normalizedText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function departureSlots(): array
    {
        /** @var array<int, array<string, mixed>> $slots */
        $slots = config('chatbot.jet.departure_slots', []);

        return $slots;
    }

    private function departureTimeByOrder(int $order): ?string
    {
        foreach ($this->departureSlots() as $slot) {
            if ((int) ($slot['order'] ?? 0) === $order) {
                return (string) $slot['time'];
            }
        }

        return null;
    }

    private function matchTimeToSlot(string $candidate): ?string
    {
        $clean = str_replace('.', ':', trim($candidate));

        if (preg_match('/^\d{1,2}$/', $clean)) {
            $clean .= ':00';
        }

        foreach ($this->departureSlots() as $slot) {
            if ((string) ($slot['time'] ?? '') === $clean) {
                return $clean;
            }
        }

        return null;
    }

    private function monthFromText(string $month): ?int
    {
        $months = [
            'jan' => 1,
            'januari' => 1,
            'feb' => 2,
            'februari' => 2,
            'mar' => 3,
            'maret' => 3,
            'apr' => 4,
            'april' => 4,
            'mei' => 5,
            'jun' => 6,
            'juni' => 6,
            'jul' => 7,
            'juli' => 7,
            'agu' => 8,
            'agustus' => 8,
            'sep' => 9,
            'september' => 9,
            'okt' => 10,
            'oktober' => 10,
            'nov' => 11,
            'november' => 11,
            'des' => 12,
            'desember' => 12,
        ];

        return $months[$this->normalizeText($month)] ?? null;
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace(["\u{2019}", "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s:\/.-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }
}
```

## app/Services/Chatbot/HumanEscalationService.php

```php
<?php

namespace App\Services\Chatbot;

use App\Enums\BookingFlowState;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingConfirmationService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\WaLog;

class HumanEscalationService
{
    public function __construct(
        private readonly WhatsAppSenderService $senderService,
        private readonly BookingConfirmationService $confirmationService,
        private readonly ConversationStateService $stateService,
    ) {}

    public function escalateQuestion(Conversation $conversation, Customer $customer, string $reason): void
    {
        $conversation->takeoverBy(null);
        $conversation->update([
            'needs_human' => true,
            'escalation_reason' => $reason,
        ]);
        $this->syncEscalationState($conversation, $reason);

        EscalateConversationToAdminJob::dispatch(
            $conversation->id,
            $reason,
            'normal',
        );

        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            WaLog::warning('[HumanEscalation] escalation not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'reason' => $reason,
            ]);

            return;
        }

        $result = $this->senderService->sendText(
            $adminPhone,
            'Bos, ini ada pertanyaan dari nomor '.ltrim((string) $customer->phone_e164, '+').', bisa bantu jawab ya bos?',
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'context' => 'question_escalation',
            ],
        );

        WaLog::info('[HumanEscalation] escalation forwarded to admin', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'admin_phone' => WaLog::maskPhone($adminPhone),
            'reason' => $reason,
            'status' => $result['status'],
        ]);

        if ($result['status'] !== 'sent') {
            WaLog::warning('[HumanEscalation] escalation forward did not send successfully', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'admin_phone' => WaLog::maskPhone($adminPhone),
                'status' => $result['status'],
                'error' => $result['error'],
            ]);
        }
    }

    public function forwardBooking(Conversation $conversation, Customer $customer, BookingRequest $booking): void
    {
        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            WaLog::warning('[HumanEscalation] booking not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return;
        }

        $summary = $this->confirmationService->buildAdminSummary(
            booking: $booking,
            customerPhone: $customer->phone_e164 ?? '-',
        );

        $result = $this->senderService->sendText(
            $adminPhone,
            $summary,
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
                'context' => 'booking_forward',
            ],
        );

        WaLog::info('[HumanEscalation] booking forwarded to admin', [
            'conversation_id' => $conversation->id,
            'booking_id' => $booking->id,
            'admin_phone' => WaLog::maskPhone($adminPhone),
            'status' => $result['status'],
        ]);
    }

    private function adminPhone(): string
    {
        return trim((string) config('chatbot.jet.admin_phone', ''));
    }

    private function syncEscalationState(Conversation $conversation, string $reason): void
    {
        $this->stateService->put($conversation, 'needs_human_escalation', true);
        $this->stateService->put($conversation, 'admin_takeover', true);
        $this->stateService->put($conversation, 'waiting_for', 'admin');
        $this->stateService->put($conversation, 'waiting_reason', $reason);
        $this->stateService->put($conversation, 'booking_intent_status', BookingFlowState::Closed->value);
    }
}
```

## tests/Feature/BookingFlowStateMachineTest.php

```php
<?php

namespace Tests\Feature;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Booking\BookingConversationStateService;
use App\Services\Booking\BookingFlowStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_context_and_only_asks_missing_slots_after_route_is_completed(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $firstReply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput', $firstReply['reply']['text']);
        $this->assertStringContainsString('nama penumpang', $firstReply['reply']['text']);
        $this->assertStringContainsString('jumlah penumpang', $firstReply['reply']['text']);
        $firstSlots = $stateService->load($conversation->fresh());
        $this->assertSame('Pekanbaru', $firstSlots['destination']);
        $this->assertSame(BookingFlowState::CollectingRoute->value, $firstSlots['booking_intent_status']);
        $this->assertSame('pickup_location', $stateService->expectedInput($conversation->fresh()));

        $secondReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian, nama Nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $freshSlots = $stateService->load($conversation->fresh());

        $this->assertStringContainsString('catat', $secondReply['reply']['text']);
        $this->assertStringContainsString('Pasir Pengaraian ke Pekanbaru', $secondReply['reply']['text']);
        $this->assertStringContainsString('tersedia', $secondReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $secondReply['reply']['text']);
        $this->assertSame('Pasir Pengaraian', $freshSlots['pickup_location']);
        $this->assertSame('Nerry', $freshSlots['passenger_name']);
        $this->assertSame(2, $freshSlots['passenger_count']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $freshSlots['booking_intent_status']);
        $this->assertSame('travel_date', $stateService->expectedInput($conversation->fresh()));
    }

    public function test_it_accepts_route_correction_without_restarting_booking(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $unsupportedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'nama Nerry, jumlah 2, titik jemput di panam'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('Panam ke Pekanbaru saat ini belum tersedia', $unsupportedReply['reply']['text']);
        $unsupportedSlots = app(BookingConversationStateService::class)->load($conversation->fresh());
        $this->assertSame(BookingFlowState::RouteUnavailable->value, $unsupportedSlots['booking_intent_status']);
        $this->assertSame('pickup_location', app(BookingConversationStateService::class)->expectedInput($conversation->fresh()));

        $correctedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput', $correctedReply['reply']['text']);
        $this->assertStringContainsString('Pasir Pengaraian', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tersedia', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $correctedReply['reply']['text']);
        $correctedSlots = app(BookingConversationStateService::class)->load($conversation->fresh());
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $correctedSlots['booking_intent_status']);
    }

    public function test_it_hydrates_slot_memory_from_existing_booking_draft_and_only_asks_next_missing_slot(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'booking_status' => BookingStatus::Draft,
        ]);

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'lanjut'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Pekanbaru', $slots['destination']);
        $this->assertSame('Nerry', $slots['passenger_name']);
        $this->assertSame(2, $slots['passenger_count']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertStringContainsString('tanggal keberangkatan', $reply['reply']['text']);
        $this->assertStringNotContainsString('nama penumpang', mb_strtolower($reply['reply']['text'], 'UTF-8'));
    }

    public function test_it_overwrites_multiple_slots_and_continues_with_latest_values(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di panam, nama nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $correctedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian, nama andi, jumlah 3'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Andi', $slots['passenger_name']);
        $this->assertSame(3, $slots['passenger_count']);
        $this->assertSame('supported', $slots['route_status']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertStringContainsString('Pasir Pengaraian', $correctedReply['reply']['text']);
        $this->assertStringContainsString('Andi', $correctedReply['reply']['text']);
        $this->assertStringContainsString('3 orang', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $correctedReply['reply']['text']);
        $this->assertStringNotContainsString('Panam', $correctedReply['reply']['text']);
    }

    public function test_it_transitions_to_ready_to_confirm_then_confirmed_when_booking_is_completed(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang, besok, jam 08.00, transfer'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $readySlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::ReadyToConfirm->value, $readySlots['booking_intent_status']);
        $this->assertTrue($readySlots['review_sent']);
        $this->assertNull($stateService->expectedInput($conversation->fresh()));

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'ya'),
            intentResult: ['intent' => 'booking_confirm', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $confirmedSlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::Confirmed->value, $confirmedSlots['booking_intent_status']);
        $this->assertTrue($confirmedSlots['booking_confirmed']);
    }

    public function test_it_closes_booking_state_and_can_resume_from_the_correct_step(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'terima kasih'),
            intentResult: ['intent' => 'farewell', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $closedSlots = $stateService->load($conversation->fresh());
        $this->assertSame(BookingFlowState::Closed->value, $closedSlots['booking_intent_status']);

        $resumeReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'lanjut'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $resumedSlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingSchedule->value, $resumedSlots['booking_intent_status']);
        $this->assertStringContainsString('tanggal keberangkatan', $resumeReply['reply']['text']);
    }

    public function test_it_uses_state_based_fallback_while_collecting_schedule(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $fallbackReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'kurang paham'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertMatchesRegularExpression(
            '/(belum menangkap detailnya dengan jelas|supaya tidak salah|biar tidak keliru)/u',
            mb_strtolower($fallbackReply['reply']['text'], 'UTF-8'),
        );
        $this->assertStringContainsString('tanggal keberangkatan', $fallbackReply['reply']['text']);
    }

    public function test_it_uses_short_general_fallback_without_booking_context(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'tolong bantu dong'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('saya bantu', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertMatchesRegularExpression(
            '/(rute|jadwal|detail)/u',
            mb_strtolower($reply['reply']['text'], 'UTF-8'),
        );
    }

    public function test_it_uses_route_unavailable_fallback_when_customer_does_not_send_a_new_route(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di panam, nama nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $fallbackReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'bagaimana ya'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput lain yang tersedia', $fallbackReply['reply']['text']);
        $this->assertStringNotContainsString('Panam ke Pekanbaru saat ini belum tersedia', $fallbackReply['reply']['text']);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }

    private function inboundMessage(Conversation $conversation, string $text): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => $text,
            'raw_payload' => [],
            'is_fallback' => false,
            'sent_at' => now(),
        ]);
    }
}
```

## tests/Unit/BookingConversationStateServiceTest.php

```php
<?php

namespace Tests\Unit;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingConversationStateService;
use App\Services\Chatbot\ConversationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConversationStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hydrates_missing_slots_from_existing_draft_booking(): void
    {
        $service = app(BookingConversationStateService::class);
        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'booking_status' => BookingStatus::Draft,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Pekanbaru', $slots['destination']);
        $this->assertSame('Nerry', $slots['passenger_name']);
        $this->assertSame(2, $slots['passenger_count']);
        $this->assertSame('2026-03-28', $slots['travel_date']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertFalse($slots['review_sent']);
    }

    public function test_it_marks_ready_to_confirm_when_hydrating_pending_review_booking(): void
    {
        $service = app(BookingConversationStateService::class);
        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'payment_method' => 'transfer',
            'booking_status' => BookingStatus::AwaitingConfirmation,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame(BookingFlowState::ReadyToConfirm->value, $slots['booking_intent_status']);
        $this->assertTrue($slots['review_sent']);
        $this->assertFalse($slots['booking_confirmed']);
    }

    public function test_it_transitions_flow_state_with_expected_input_snapshot(): void
    {
        $service = app(BookingConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $service->transitionFlowState(
            $conversation,
            BookingFlowState::CollectingSchedule,
            'travel_date',
            'test_transition',
        );

        $snapshot = $service->snapshot($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingSchedule->value, $snapshot['booking_intent_status']);
        $this->assertSame('travel_date', $snapshot['expected_input']);
    }

    public function test_it_normalizes_legacy_collecting_state_into_explicit_flow_state(): void
    {
        $service = app(BookingConversationStateService::class);
        $conversationState = app(ConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $conversationState->put($conversation, 'booking_intent_status', 'collecting');
        $conversationState->put($conversation, 'destination', 'Pekanbaru');

        $slots = $service->load($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingRoute->value, $slots['booking_intent_status']);
    }

    public function test_it_classifies_tracked_slot_changes_into_created_and_overwritten(): void
    {
        $service = app(BookingConversationStateService::class);

        $changes = $service->trackedSlotChanges([
            'pickup_location' => ['old' => 'Panam', 'new' => 'Pasir Pengaraian'],
            'passenger_name' => ['old' => 'Nerry', 'new' => 'Andi'],
            'travel_date' => ['old' => null, 'new' => '2026-03-28'],
            'route_status' => ['old' => 'unsupported', 'new' => 'supported'],
        ]);

        $this->assertArrayHasKey('pickup_location', $changes['overwritten']);
        $this->assertArrayHasKey('passenger_name', $changes['overwritten']);
        $this->assertArrayHasKey('travel_date', $changes['created']);
        $this->assertArrayNotHasKey('route_status', $changes['overwritten']);
        $this->assertSame('Panam', $changes['overwritten']['pickup_location']['old']);
        $this->assertSame('Pasir Pengaraian', $changes['overwritten']['pickup_location']['new']);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }
}
```

## tests/Unit/BookingReplyNaturalizerServiceTest.php

```php
<?php

namespace Tests\Unit;

use App\Enums\BookingFlowState;
use App\Models\BookingRequest;
use App\Services\Booking\BookingReplyNaturalizerService;
use Tests\TestCase;

class BookingReplyNaturalizerServiceTest extends TestCase
{
    public function test_it_uses_natural_admin_tone_when_asking_basic_details(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->askBasicDetails(
            ['pickup_location', 'passenger_name', 'passenger_count'],
            ['destination' => 'Pekanbaru'],
        );

        $this->assertMatchesRegularExpression('/^(Baik|Siap|Oke),/u', $text);
        $this->assertStringContainsString('titik jemput', $text);
        $this->assertStringContainsString('nama penumpang', $text);
        $this->assertStringContainsString('jumlah penumpang', $text);
        $this->assertStringNotContainsString('Bapak/Ibu', $text);
    }

    public function test_it_keeps_pending_acknowledgement_natural_and_not_repetitive(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $pendingPrompt = $service->askBasicDetails(
            ['pickup_location', 'passenger_name'],
            ['destination' => 'Pekanbaru'],
        );

        $text = $service->inProgressAcknowledgement($pendingPrompt);

        $this->assertStringContainsString('kalau sudah siap', mb_strtolower($text, 'UTF-8'));
        $this->assertStringNotContainsString('saya bantu ya. Supaya', $text);
        $this->assertStringContainsString('titik jemput', $text);
    }

    public function test_it_builds_a_warm_review_summary_without_formal_wording(): void
    {
        $service = app(BookingReplyNaturalizerService::class);
        $booking = BookingRequest::make([
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'payment_method' => 'transfer',
            'price_estimate' => 300000,
        ]);

        $text = $service->reviewSummary($booking);

        $this->assertStringContainsString('data perjalanannya', $text);
        $this->assertStringContainsString('balas YA atau BENAR', $text);
        $this->assertStringContainsString('kirim', $text);
        $this->assertStringNotContainsString('Bapak/Ibu', $text);
    }

    public function test_it_naturalizes_rule_reply_into_a_compact_follow_up_message(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->naturalizeRuleReply(
            capturedUpdates: [
                'pickup_location' => 'Pasir Pengaraian',
                'passenger_name' => 'Nerry',
                'passenger_count' => 2,
            ],
            correctionLines: [],
            prompt: 'Boleh kirim tanggal keberangkatannya ya?',
            routeLine: 'Rute Pasir Pengaraian ke Pekanbaru tersedia ya.',
        );

        $this->assertStringContainsString('Pasir Pengaraian', $text);
        $this->assertStringContainsString('Nerry', $text);
        $this->assertStringContainsString('2 orang', $text);
        $this->assertStringContainsString('tersedia ya', $text);
        $this->assertStringContainsString('Tinggal mohon kirim tanggal keberangkatannya ya.', $text);
        $this->assertStringNotContainsString('- titik jemput:', $text);
    }

    public function test_it_naturalizes_unsupported_route_reply_without_losing_the_rule_result(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->naturalizeUnsupportedRuleReply(
            capturedUpdates: ['pickup_location' => 'Panam'],
            correctionLines: [],
            unsupportedReply: 'Mohon maaf ya, untuk penjemputan dari Panam ke Pekanbaru saat ini belum tersedia. Kalau mau lanjut, silakan kirim titik jemput lain yang tersedia ya. Nanti saya bantu lanjutkan.',
        );

        $this->assertStringContainsString('Panam', $text);
        $this->assertStringContainsString('belum tersedia', $text);
        $this->assertStringContainsString('titik jemput lain', $text);
        $this->assertStringContainsString('saya catat', $text);
    }

    public function test_it_builds_a_state_based_fallback_for_collecting_schedule(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->fallbackForState(
            state: BookingFlowState::CollectingSchedule->value,
            slots: [
                'pickup_location' => 'Pasir Pengaraian',
                'destination' => 'Pekanbaru',
            ],
            pendingPrompt: 'Boleh kirim tanggal keberangkatannya ya?',
        );

        $this->assertStringContainsString('belum menangkap detailnya dengan jelas', mb_strtolower($text, 'UTF-8'));
        $this->assertStringContainsString('tanggal keberangkatan', $text);
    }

    public function test_it_builds_a_state_based_fallback_for_route_unavailable(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->fallbackForState(
            state: BookingFlowState::RouteUnavailable->value,
            routeIssue: 'pickup_location',
            routeSuggestions: ['Pasir Pengaraian', 'Kabun'],
        );

        $this->assertStringContainsString('titik jemput lain yang tersedia', $text);
        $this->assertStringContainsString('Pasir Pengaraian', $text);
    }

    public function test_it_builds_a_general_fallback_based_on_user_signal(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->generalFallback(['price_keyword' => true]);

        $this->assertStringContainsString('cek harga', mb_strtolower($text, 'UTF-8'));
        $this->assertStringContainsString('titik jemput', $text);
        $this->assertStringContainsString('tujuan', $text);
    }
}
```

## tests/Unit/BookingSlotExtractorServiceTest.php

```php
<?php

namespace Tests\Unit;

use App\Services\Booking\BookingSlotExtractorService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingSlotExtractorServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_extracts_name_count_and_ambiguous_morning_time_from_free_text(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'nama penumpangnya Nerry, jumlahnya 2, besok pagi',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Nerry', $result['updates']['passenger_name']);
        $this->assertSame(2, $result['updates']['passenger_count']);
        $this->assertSame('2026-03-28', $result['updates']['travel_date']);
        $this->assertArrayNotHasKey('travel_time', $result['updates']);
        $this->assertTrue($result['signals']['time_ambiguous']);
    }

    public function test_it_extracts_unknown_pickup_location_for_route_validation(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'titik jemput di panam',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'pickup_location',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Panam', $result['updates']['pickup_location']);
    }

    public function test_it_extracts_multiple_slots_from_one_free_form_message(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'jemput di kabun, tujuan pekanbaru, nama nerry, 2 orang',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Kabun', $result['updates']['pickup_location']);
        $this->assertSame('Pekanbaru', $result['updates']['destination']);
        $this->assertSame('Nerry', $result['updates']['passenger_name']);
        $this->assertSame(2, $result['updates']['passenger_count']);
    }

    public function test_it_normalizes_name_variants_without_capturing_pronouns(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'nama saya nerry',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Nerry', $result['updates']['passenger_name']);
    }

    public function test_it_does_not_treat_date_numbers_as_passenger_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: '28 maret jam 8',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('2026-03-28', $result['updates']['travel_date']);
        $this->assertSame('08:00', $result['updates']['travel_time']);
        $this->assertArrayNotHasKey('passenger_count', $result['updates']);
    }
}
```

