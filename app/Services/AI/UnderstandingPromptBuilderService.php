<?php

namespace App\Services\AI;

class UnderstandingPromptBuilderService
{
    public function __construct(
        private readonly UnderstandingCrmContextFormatterService $crmFormatter,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $recentHistory
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @param  array<int, string>  $allowedIntents
     * @param  array<string, mixed>  $crmContext
     * @return array{system: string, user: string}
     */
    public function build(
        string $latestMessage,
        array $recentHistory = [],
        array $conversationState = [],
        array $knownEntities = [],
        array $allowedIntents = [],
        ?string $conversationSummary = null,
        array $crmContext = [],
        bool $adminTakeover = false,
    ): array {
        $allowedIntentList = implode(', ', $allowedIntents);

        $system = <<<SYSTEM
        Kamu adalah mesin understanding untuk chatbot WhatsApp travel antar kota di Indonesia.
        Tugasmu HANYA memahami pesan user dan mengembalikan JSON terstruktur.
        Kamu TIDAK boleh membuat jawaban final ke user.

        SUMBER KEBENARAN UTAMA:
        1. Fakta CRM / snapshot bisnis / status booking / escalation / conversation state.
        2. Riwayat percakapan yang relevan.
        3. Pesan user terbaru.
        4. Reasoning LLM hanya untuk memahami maksud, BUKAN untuk mengarang fakta.

        INTENT YANG DIIZINKAN:
        {$allowedIntentList}

        ATURAN WAJIB:
        1. Output HARUS JSON object valid saja, tanpa markdown, tanpa kalimat pembuka/penutup.
        2. Gunakan hanya intent dari daftar yang diizinkan.
        3. Jika tidak yakin, pilih "unknown" bila tersedia di daftar intent.
        4. Jangan mengarang fakta bisnis, harga, jadwal, policy, status booking, atau status CRM.
        5. Jika CRM menyatakan ada status/fakta tertentu, anggap itu lebih kuat daripada tebakan LLM.
        6. Gunakan konteks percakapan sebelumnya hanya jika memang diperlukan untuk memahami pesan terbaru.
        7. Jika data belum cukup, set needs_clarification=true dan isi clarification_question dengan pertanyaan singkat.
        8. handoff_recommended=true hanya bila user jelas meminta admin/manusia, ada sinyal escalation, atau konteks sangat tidak aman untuk otomatis.
        9. reasoning_summary maksimal 1 kalimat singkat, dan bila relevan sebut bahwa keputusan memakai konteks CRM.
        10. Field entity yang tidak diketahui harus null.
        11. travel_date format YYYY-MM-DD. departure_time format HH:MM 24 jam.
        12. passenger_count harus integer atau null.
        13. Jika ada booking yang sedang berjalan, prioritaskan interpretasi yang menjaga kesinambungan alur booking tersebut.
        14. Jika ada open escalation / human follow-up / admin takeover, jangan klasifikasikan seolah percakapan benar-benar netral tanpa konteks.

        FORMAT OUTPUT:
        {
          "intent": "booking",
          "sub_intent": null,
          "confidence": 0.91,
          "uses_previous_context": true,
          "entities": {
            "origin": null,
            "destination": null,
            "travel_date": null,
            "departure_time": null,
            "passenger_count": null,
            "passenger_name": null,
            "seat_number": null,
            "payment_method": null
          },
          "needs_clarification": false,
          "clarification_question": null,
          "handoff_recommended": false,
          "reasoning_summary": "Ringkasan singkat alasan klasifikasi."
        }
        SYSTEM;

        $crmBlock = $this->crmFormatter->formatForUnderstanding(
            crmContext: $crmContext,
            conversationSummary: $conversationSummary,
            adminTakeover: $adminTakeover,
        );

        $user = implode("\n\n", array_filter([
            "=== PESAN USER TERBARU ===\n".$latestMessage,
            "=== RIWAYAT PERCAKAPAN RINGKAS ===\n".$this->jsonBlock($recentHistory),
            "=== CONVERSATION STATE ===\n".$this->jsonBlock($conversationState),
            "=== SLOT / ENTITY YANG SUDAH DIKETAHUI ===\n".$this->jsonBlock($knownEntities),
            $crmBlock,
            "=== INTENT YANG DIIZINKAN ===\n".$this->jsonBlock($allowedIntents),
        ]));

        return [
            'system' => trim($system),
            'user' => trim($user),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function jsonBlock(array $data): string
    {
        if ($data === []) {
            return '[]';
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '[]';
    }
}
