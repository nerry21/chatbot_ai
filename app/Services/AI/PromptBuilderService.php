<?php

namespace App\Services\AI;

class PromptBuilderService
{
    // -------------------------------------------------------------------------
    // Public builders - each returns ['system' => '...', 'user' => '...']
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
        - greeting                     : sapaan umum
        - salam_islam                 : salam Islam tanpa kebutuhan lain
        - booking                     : ingin memesan atau melanjutkan proses booking
        - tanya_keberangkatan_hari_ini: bertanya jadwal keberangkatan hari ini
        - tanya_harga                 : bertanya harga atau ongkos
        - tanya_rute                  : bertanya rute, titik jemput, atau tujuan yang tersedia
        - tanya_jam                   : bertanya jam atau jadwal keberangkatan umum
        - konfirmasi_booking          : menyetujui review/data booking
        - ubah_data_booking           : ingin mengubah data booking yang sudah disebut
        - pertanyaan_tidak_terjawab   : pertanyaan di luar jawaban bot / perlu admin
        - close_intent                : pamit, ucapan terima kasih, atau penutup percakapan
        - booking_cancel              : membatalkan pemesanan
        - human_handoff               : ingin berbicara langsung dengan petugas/admin
        - confirmation                : konfirmasi singkat terhadap pertanyaan sebelumnya
        - rejection                   : penolakan singkat / koreksi / tidak setuju
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

        ATURAN KETAT - WAJIB DIIKUTI:
        1. JANGAN mengarang atau menyebutkan harga, jadwal, ketersediaan, atau nomor booking yang tidak ada dalam data.
        2. JANGAN membuat keputusan bisnis final (konfirmasi booking, pembatalan resmi, dll.).
        3. Jika informasi tidak tersedia, katakan dengan jujur bahwa kamu akan cek atau minta info lebih.
        4. Sapa pelanggan dengan nama jika tersedia.
        5. Respons maksimal 3-4 kalimat - ringkas dan tepat sasaran.
        6. Bahasa Indonesia yang sopan dan natural.
        7. JANGAN tambahkan emoji kecuali memang diperlukan.
        8. Jika ada knowledge base di bawah, PRIORITASKAN informasinya. Jangan mengarang aturan, harga, atau jadwal di luar knowledge/data sistem.
        9. Jika knowledge tidak memiliki jawaban yang tepat, jawab secara konservatif dan tawarkan untuk mengecek lebih lanjut.
        10. Terdengar seperti admin travel WhatsApp di Indonesia: hangat, sopan, ringkas, dan profesional.
        11. Hindari bahasa birokratis, kaku, terlalu formal, atau terasa seperti template sistem.
        12. Hindari bullet/list kecuali memang perlu untuk merangkum data.

        INTENT SAAT INI: {$intentLabel}

        PANDUAN PER INTENT:
        - greeting / salam_islam       : sambut dengan hangat, tanya kebutuhan perjalanan bila perlu
        - booking                      : kumpulkan info yang kurang secara bertahap
        - tanya_keberangkatan_hari_ini : jawab jadwal hari ini secara singkat
        - tanya_jam                    : jawab jam keberangkatan yang tersedia
        - tanya_harga                  : minta rute bila belum lengkap, jangan mengarang harga
        - tanya_rute                   : jawab area layanan atau minta titik yang ingin dicek
        - konfirmasi_booking           : akui konfirmasi dengan singkat
        - ubah_data_booking            : terima perubahan dan arahkan ke field yang perlu disesuaikan
        - pertanyaan_tidak_terjawab    : sampaikan akan konsultasi ke admin
        - human_handoff / support      : sampaikan akan menghubungkan dengan tim, minta tunggu sebentar
        - close_intent                 : tutup dengan sopan, ucapkan terima kasih
        - unknown / out_of_scope       : minta klarifikasi dengan sopan, tetap konservatif
        - confirmation                 : terima konfirmasi, lanjutkan alur sesuai konteks
        - rejection                    : terima dengan sopan, tanya kebutuhan lain
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
        2. Ringkasan harus faktual - hanya dari apa yang ada dalam percakapan.
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

    /**
     * @param  array<string, mixed>  $context
     */
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

            $hubspot = is_array($memory['hubspot'] ?? null) ? $memory['hubspot'] : [];
            if (! empty($hubspot) && config('chatbot.crm.ai_context.include_in_intent_tasks', true)) {
                $lines[] = '';
                $lines[] = '=== INFO CRM HUBSPOT ===';
                if (! empty($hubspot['lifecycle_stage'])) {
                    $lines[] = "Lifecycle stage: {$hubspot['lifecycle_stage']}";
                }
                if (! empty($hubspot['lead_status'])) {
                    $lines[] = "Lead status: {$hubspot['lead_status']}";
                }
                if (! empty($hubspot['company'])) {
                    $lines[] = "Perusahaan: {$hubspot['company']}";
                }
            }

            $lines[] = '';
        }

        if (
            config('chatbot.knowledge.include_in_intent_tasks', false)
            && ! empty($context['knowledge_hint'])
        ) {
            $lines[] = $context['knowledge_hint'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
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

            $hubspot = is_array($memory['hubspot'] ?? null) ? $memory['hubspot'] : [];
            if (! empty($hubspot) && config('chatbot.crm.ai_context.include_in_extraction_tasks', true)) {
                $lines[] = '=== KONTEKS CRM HUBSPOT ===';
                if (! empty($hubspot['company'])) {
                    $lines[] = "Perusahaan: {$hubspot['company']}";
                }
                if (! empty($hubspot['lifecycle_stage'])) {
                    $lines[] = "Lifecycle stage: {$hubspot['lifecycle_stage']}";
                }
                if (! empty($hubspot['lead_status'])) {
                    $lines[] = "Lead status: {$hubspot['lead_status']}";
                }
                $lines[] = '(Gunakan data CRM hanya sebagai konteks tambahan, jangan mengada-ada detail yang tidak disebut pelanggan.)';
                $lines[] = '';
            }
        }

        $intent = $context['intent_result']['intent'] ?? null;
        if ($intent !== null) {
            $lines[] = "Intent terdeteksi: {$intent}";
        }

        if (
            config('chatbot.knowledge.include_in_extraction_tasks', false)
            && ! empty($context['knowledge_hint'])
        ) {
            $lines[] = '';
            $lines[] = $context['knowledge_hint'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatReplyUserPrompt(array $context): string
    {
        $lines = [];

        if (
            config('chatbot.knowledge.include_in_reply_tasks', true)
            && ! empty($context['knowledge_block'])
        ) {
            $lines[] = $context['knowledge_block'];
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        $name = $memory['primary_name'] ?? null;
        $hubspot = is_array($memory['hubspot'] ?? null) ? $memory['hubspot'] : [];

        if ($name !== null) {
            $lines[] = "Nama pelanggan: {$name}";
        }

        if (! empty($hubspot) && config('chatbot.crm.ai_context.include_in_reply_tasks', true)) {
            $lines[] = '=== INFO CRM HUBSPOT ===';
            if (! empty($hubspot['company'])) {
                $lines[] = "Perusahaan: {$hubspot['company']}";
            }
            if (! empty($hubspot['jobtitle'])) {
                $lines[] = "Jabatan: {$hubspot['jobtitle']}";
            }
            if (! empty($hubspot['lifecycle_stage'])) {
                $lines[] = "Lifecycle stage: {$hubspot['lifecycle_stage']}";
            }
            if (! empty($hubspot['lead_status'])) {
                $lines[] = "Lead status: {$hubspot['lead_status']}";
            }
            if (! empty($hubspot['score'])) {
                $lines[] = "HubSpot score: {$hubspot['score']}";
            }
            if (! empty($hubspot['source'])) {
                $lines[] = "Sumber CRM: {$hubspot['source']}";
            }
            $lines[] = '';
        }

        $intent = $context['intent_result']['intent'] ?? 'unknown';
        $lines[] = "Intent: {$intent}";
        $lines[] = '';

        $understanding = $context['understanding_result'] ?? [];
        if (is_array($understanding) && $understanding !== []) {
            $lines[] = '=== HASIL UNDERSTANDING ===';
            if (! empty($understanding['sub_intent'])) {
                $lines[] = 'Sub-intent: '.$understanding['sub_intent'];
            }
            if (! empty($understanding['reasoning_summary'])) {
                $lines[] = 'Reasoning: '.$understanding['reasoning_summary'];
            }
            if (($understanding['needs_clarification'] ?? false) === true && ! empty($understanding['clarification_question'])) {
                $lines[] = 'Klarifikasi yang dibutuhkan: '.$understanding['clarification_question'];
            }
            if (($understanding['uses_previous_context'] ?? false) === true) {
                $lines[] = 'Pesan terbaru bergantung pada konteks sebelumnya.';
            }
            $lines[] = '';
        }

        $entities = $context['entity_result'] ?? [];
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

        if (! empty($context['conversation_summary'])) {
            $lines[] = '=== RINGKASAN KONTEKS ===';
            $lines[] = (string) $context['conversation_summary'];
            $lines[] = '';
        }

        if (! empty($context['resolved_context']) && is_array($context['resolved_context'])) {
            $lines[] = '=== KONTEKS AKTIF ===';
            foreach ($context['resolved_context'] as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : (string) $value;
                $lines[] = "  {$key}: {$valueStr}";
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

    /**
     * @param  array<string, mixed>  $context
     */
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
