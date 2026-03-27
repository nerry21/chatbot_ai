<?php

namespace App\Services\AI;

class PromptBuilderService
{
    // -------------------------------------------------------------------------
    // Public builders — each returns ['system' => '...', 'user' => '...']
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

        ATURAN KETAT — WAJIB DIIKUTI:
        1. JANGAN mengarang atau menyebutkan harga, jadwal, ketersediaan, atau nomor booking yang tidak ada dalam data.
        2. JANGAN membuat keputusan bisnis final (konfirmasi booking, pembatalan resmi, dll.).
        3. Jika informasi tidak tersedia, katakan dengan jujur bahwa kamu akan cek atau minta info lebih.
        4. Sapa pelanggan dengan nama jika tersedia.
        5. Respons maksimal 3-4 kalimat — ringkas dan tepat sasaran.
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
        2. Ringkasan harus faktual — hanya dari apa yang ada dalam percakapan.
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

        // Tahap 10: compact knowledge hint (only when config allows — keeps intent prompt lean)
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
