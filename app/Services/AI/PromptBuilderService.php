<?php

namespace App\Services\AI;

class PromptBuilderService
{
    public function __construct(
        private readonly UnderstandingCrmContextFormatterService $crmFormatter,
    ) {
    }

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
          "should_escalate": false,
          "entities": {},
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
        13. Kembalikan JSON valid SAJA, tanpa teks di luar JSON.
        14. Isi field "reply" dengan jawaban final yang natural, ringkas, dan aman.

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
        Kamu adalah pencatat ringkasan bisnis percakapan layanan transportasi.
        Buat memory bisnis yang ringkas, faktual, dan bisa dipakai ulang oleh AI, CRM, dan admin follow-up.
        Fokus pada: topik utama pelanggan, intent utama, status percakapan saat ini, dan langkah lanjut yang paling relevan.

        ATURAN:
        1. Kembalikan JSON valid SAJA.
        2. Ringkasan harus faktual, singkat, dan hanya dari data yang tersedia.
        3. JANGAN menambahkan informasi yang tidak ada.
        4. summary maksimal 2-3 kalimat bahasa Indonesia.
        5. next_action harus singkat dan operasional bila memang ada.

        FORMAT OUTPUT:
        {
          "summary": "ringkasan bisnis percakapan",
          "intent": "intent_utama_atau_kosong",
          "sentiment": "positive|neutral|negative|urgent|kosong",
          "next_action": "langkah berikutnya atau kosong"
        }
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
        $latestMessage = $context['message_text'] ?? $context['latest_message'] ?? '(kosong)';
        $recentMessages = $this->promptMessages($context, 'recent_messages', 'recent_history');

        $lines[] = '=== PESAN TERBARU PELANGGAN ===';
        $lines[] = $latestMessage;
        $lines[] = '';

        $lines = $this->appendDecisionHierarchyLines($lines);
        $lines = $this->appendUnifiedCrmContextLines($lines, $context);
        $lines = $this->appendActionBiasLines($lines, $context);

        if (! empty($context['conversation_summary'])) {
            $lines[] = '=== RINGKASAN BISNIS TERAKHIR ===';
            $lines[] = (string) $context['conversation_summary'];
            $lines[] = '';
        }

        if ($recentMessages !== []) {
            $lines[] = '=== RIWAYAT PERCAKAPAN (terbaru terakhir) ===';
            foreach ($recentMessages as $msg) {
                $dir = ($msg['direction'] ?? 'inbound') === 'inbound' ? 'Pelanggan' : 'Bot';
                $text = $msg['text'] ?? '';
                $lines[] = "[{$dir}]: {$text}";
            }
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        if (! empty($memory)) {
            $lines[] = '=== INFO PELANGGAN ===';
            $name = $this->customerNameFromMemory($memory);
            if ($name !== null) {
                $lines[] = "Nama: {$name}";
            }
            $preferredPickup = $this->preferredPickupFromMemory($memory);
            if ($preferredPickup !== null) {
                $lines[] = "Titik jemput biasa: {$preferredPickup}";
            }
            $preferredDestination = $this->preferredDestinationFromMemory($memory);
            if ($preferredDestination !== null) {
                $lines[] = "Tujuan biasa: {$preferredDestination}";
            }

            $hubspot = $this->hubspotPromptContext($context);
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
            $preferredPickup = $this->preferredPickupFromMemory($memory);
            if ($preferredPickup !== null) {
                $lines[] = "Titik jemput biasa: {$preferredPickup}";
            }
            $preferredDestination = $this->preferredDestinationFromMemory($memory);
            if ($preferredDestination !== null) {
                $lines[] = "Tujuan biasa: {$preferredDestination}";
            }
            $lines[] = '(Gunakan preferensi di atas HANYA jika pelanggan mengkonfirmasinya secara eksplisit dalam percakapan ini)';
            $lines[] = '';

            $hubspot = $this->hubspotPromptContext($context);
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

        $lines = $this->appendUnifiedCrmContextLines($lines, $context);

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
        $messageText = $context['message_text'] ?? $context['latest_message'] ?? '(kosong)';

        if (
            config('chatbot.knowledge.include_in_reply_tasks', true)
            && ! empty($context['knowledge_block'])
        ) {
            $lines[] = $context['knowledge_block'];
            $lines[] = '';
        }

        $memory = $context['customer_memory'] ?? [];
        $name = $this->customerNameFromMemory(is_array($memory) ? $memory : []);
        $hubspot = $this->hubspotPromptContext($context);

        if ($name !== null) {
            $lines[] = "Nama pelanggan: {$name}";
        }

        if (! empty($hubspot) && config('chatbot.crm.ai_context.include_in_reply_tasks', true)) {
            $lines[] = '=== INFO CRM HUBSPOT ===';
            if (! empty($hubspot['company'])) {
                $lines[] = "Perusahaan: {$hubspot['company']}";
            }
            if (! empty($hubspot['job_title'] ?? $hubspot['jobtitle'] ?? null)) {
                $lines[] = 'Jabatan: '.($hubspot['job_title'] ?? $hubspot['jobtitle']);
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

        $lines = $this->appendDecisionHierarchyLines($lines);
        $lines = $this->appendUnifiedCrmContextLines($lines, $context);
        $lines = $this->appendReplyGuardrailLines($lines, $context);
        $lines = $this->appendActionBiasLines($lines, $context);

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

        $lines[] = '=== FORMAT OUTPUT WAJIB ===';
        $lines[] = 'Kembalikan JSON object valid dengan field berikut:';
        $lines[] = '{';
        $lines[] = '  "reply": "string",';
        $lines[] = '  "tone": "formal|ramah|empatik|tegas",';
        $lines[] = '  "should_escalate": true/false,';
        $lines[] = '  "handoff_reason": "string|null",';
        $lines[] = '  "next_action": "ask_missing_data|answer_question|offer_next_step|handoff_admin|safe_fallback",';
        $lines[] = '  "data_requests": ["field1", "field2"],';
        $lines[] = '  "used_crm_facts": ["fact1", "fact2"],';
        $lines[] = '  "safety_notes": ["note1", "note2"]';
        $lines[] = '}';
        $lines[] = 'Jangan tambahkan teks di luar JSON.';
        $lines[] = '';

        $lines[] = '=== PESAN PELANGGAN ===';
        $lines[] = $messageText;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatSummaryUserPrompt(array $context): string
    {
        $lines = [];

        $memory = $context['customer_memory'] ?? [];
        $customerName = $this->customerNameFromMemory(is_array($memory) ? $memory : []);
        $customerPhone = $this->customerPhoneFromMemory(is_array($memory) ? $memory : []);

        if ($customerName !== null) {
            $label = $customerPhone !== null
                ? "{$customerName} ({$customerPhone})"
                : $customerName;

            $lines[] = "Pelanggan: {$label}";
            $lines[] = '';
        }

        $lines = $this->appendUnifiedCrmContextLines($lines, $context);

        $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];

        if ($conversation !== []) {
            $lines[] = '=== PAYLOAD RINGKASAN BISNIS ===';

            if (! empty($conversation['current_intent'])) {
                $lines[] = 'Intent saat ini: '.$conversation['current_intent'];
            }
            if (array_key_exists('needs_human', $conversation)) {
                $lines[] = 'Perlu human follow-up: '.($conversation['needs_human'] ? 'ya' : 'tidak');
            }
            if (! empty($conversation['handoff_mode'])) {
                $lines[] = 'Mode handoff: '.$conversation['handoff_mode'];
            }
            if (array_key_exists('bot_paused', $conversation)) {
                $lines[] = 'Bot paused: '.($conversation['bot_paused'] ? 'ya' : 'tidak');
            }
            if (! empty($conversation['last_message_at'])) {
                $lines[] = 'Interaksi terakhir: '.$conversation['last_message_at'];
            }
            if (! empty($conversation['existing_summary'])) {
                $lines[] = 'Summary sebelumnya: '.$conversation['existing_summary'];
            }

            $lines[] = '';
            $lines[] = '=== TRANSKRIP TERBARU ===';

            foreach (($conversation['recent_transcript'] ?? []) as $line) {
                $lines[] = (string) $line;
            }

            return implode("\n", $lines);
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

    /**
     * Tambahkan blok CRM terpadu ke prompt agar LLM membaca fakta bisnis,
     * bukan hanya histori chat.
     *
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function appendUnifiedCrmContextLines(array $lines, array $context): array
    {
        $block = $this->crmFormatter->formatForUnderstanding(
            crmContext: is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [],
            conversationSummary: isset($context['conversation_summary']) ? (string) $context['conversation_summary'] : null,
            adminTakeover: (bool) ($context['admin_takeover'] ?? false),
        );

        if (trim($block) === '') {
            return $lines;
        }

        foreach (explode("\n", $block) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function appendDecisionHierarchyLines(array $lines): array
    {
        $lines[] = '=== HIRARKI KEPUTUSAN WAJIB ===';
        $lines[] = '1. Patuhi system policy dan aturan keamanan.';
        $lines[] = '2. Patuhi rules bisnis / SOP internal.';
        $lines[] = '3. Gunakan fakta CRM, state percakapan, dan data booking sebagai sumber kebenaran.';
        $lines[] = '4. Gunakan reasoning LLM hanya untuk memahami maksud, merangkum, dan menyusun bahasa.';
        $lines[] = '5. Jangan mengarang fakta di luar context.';
        $lines[] = '6. Jika data tidak cukup, pilih jawaban aman atau arahkan ke admin.';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function appendReplyGuardrailLines(array $lines, array $context): array
    {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];

        $lines[] = '=== BATASAN WAJABAN OPERASIONAL ===';
        $lines[] = '- Jangan menyatakan sesuatu sebagai pasti jika data tidak ada.';
        $lines[] = '- Jangan membuat janji operasional tanpa dasar dari context.';
        $lines[] = '- Jangan mengubah fakta CRM, booking, atau escalation.';
        $lines[] = '- Jika pelanggan butuh admin atau kasus sensitif, arahkan ke admin dengan sopan.';
        $lines[] = '- Jika booking belum lengkap, fokus minta data yang masih kurang.';
        $lines[] = '- Jika admin takeover aktif, jangan bertindak seolah bot bebas mengambil keputusan besar.';

        if (($flags['admin_takeover_active'] ?? false) === true) {
            $lines[] = '- Kondisi saat ini: admin takeover aktif.';
        }

        if (($conversation['needs_human'] ?? false) === true) {
            $lines[] = '- Kondisi saat ini: conversation ditandai perlu human follow-up.';
        }

        if (($escalation['has_open_escalation'] ?? false) === true) {
            $lines[] = '- Kondisi saat ini: ada escalation terbuka.';
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $lines[] = '- Data booking yang harus diprioritaskan: '.implode(', ', $booking['missing_fields']);
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function appendActionBiasLines(array $lines, array $context): array
    {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $lead = is_array($crm['lead_pipeline'] ?? null) ? $crm['lead_pipeline'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $customer = is_array($crm['customer'] ?? null) ? $crm['customer'] : [];

        $lines[] = '=== ARAH TINDAKAN YANG DIUTAMAKAN ===';

        if (($customer['total_bookings'] ?? 0) > 0) {
            $lines[] = '- Pelanggan ini pernah bertransaksi. Jaga kesinambungan konteks.';
        } else {
            $lines[] = '- Pelanggan ini kemungkinan baru. Gunakan penjelasan singkat dan jelas.';
        }

        if (! empty($lead['stage'])) {
            $lines[] = '- Stage pipeline saat ini: '.$lead['stage'].'. Sesuaikan jawaban dengan tahap hubungan pelanggan.';
        }

        if (($booking['ready_for_confirmation'] ?? false) === true) {
            $lines[] = '- Booking tampak siap dikonfirmasi, tetapi jangan menyatakan final jika rules melarang.';
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $lines[] = '- Prioritas tindakan: lengkapi data booking yang kurang terlebih dahulu.';
        } else {
            $lines[] = '- Prioritas tindakan: bantu pelanggan maju satu langkah berikutnya secara aman.';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function customerNameFromMemory(array $memory): ?string
    {
        $name = $memory['customer_profile']['name'] ?? $memory['primary_name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function customerPhoneFromMemory(array $memory): ?string
    {
        $phone = $memory['customer_profile']['phone_e164'] ?? $memory['phone_e164'] ?? null;

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function preferredPickupFromMemory(array $memory): ?string
    {
        $pickup = $memory['relationship_memory']['preferred_pickup'] ?? $memory['preferred_pickup'] ?? null;

        return is_string($pickup) && trim($pickup) !== '' ? trim($pickup) : null;
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function preferredDestinationFromMemory(array $memory): ?string
    {
        $destination = $memory['relationship_memory']['preferred_destination'] ?? $memory['preferred_destination'] ?? null;

        return is_string($destination) && trim($destination) !== '' ? trim($destination) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function hubspotPromptContext(array $context): array
    {
        $crmHubspot = is_array($context['crm_context']['hubspot'] ?? null)
            ? $context['crm_context']['hubspot']
            : [];

        if ($crmHubspot !== []) {
            return $crmHubspot;
        }

        $memory = is_array($context['customer_memory'] ?? null) ? $context['customer_memory'] : [];

        return is_array($memory['hubspot'] ?? null) ? $memory['hubspot'] : [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function promptMessages(array $context, string $primaryKey, string $fallbackKey): array
    {
        $messages = is_array($context[$primaryKey] ?? null)
            ? $context[$primaryKey]
            : [];

        if ($messages === [] && is_array($context[$fallbackKey] ?? null)) {
            $messages = $context[$fallbackKey];
        }

        return array_values(array_filter(array_map(function ($message): ?array {
            if (! is_array($message)) {
                return null;
            }

            $text = isset($message['text']) ? trim((string) $message['text']) : '';
            $role = trim((string) ($message['role'] ?? ''));
            $direction = trim((string) ($message['direction'] ?? ''));

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
