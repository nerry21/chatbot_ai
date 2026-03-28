<?php

namespace App\Services\AI;

class UnderstandingPromptBuilderService
{
    /**
     * @param  array<int, array<string, mixed>>  $recentHistory
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @param  array<int, string>  $allowedIntents
     * @return array{system: string, user: string}
     */
    public function build(
        string $latestMessage,
        array $recentHistory = [],
        array $conversationState = [],
        array $knownEntities = [],
        array $allowedIntents = [],
    ): array {
        $allowedIntentList = implode(', ', $allowedIntents);

        $system = <<<SYSTEM
        Kamu adalah mesin understanding untuk chatbot WhatsApp travel antar kota di Indonesia.
        Tugasmu HANYA memahami pesan user dan mengembalikan JSON terstruktur.
        Kamu TIDAK boleh membuat jawaban final ke user.

        INTENT YANG DIIZINKAN:
        {$allowedIntentList}

        ATURAN WAJIB:
        1. Output HARUS JSON object valid saja, tanpa markdown, tanpa kalimat pembuka/penutup.
        2. Gunakan hanya intent dari daftar yang diizinkan.
        3. Jika tidak yakin, pilih "unknown" bila tersedia di daftar intent.
        4. Jangan mengarang fakta bisnis, harga, jadwal, atau policy.
        5. Gunakan konteks percakapan sebelumnya hanya jika memang diperlukan untuk memahami pesan terbaru.
        6. Jika data belum cukup, set needs_clarification=true dan isi clarification_question dengan pertanyaan singkat.
        7. handoff_recommended=true hanya bila user jelas meminta admin/manusia atau konteks sangat tidak aman untuk otomatis.
        8. reasoning_summary maksimal 1 kalimat singkat.
        9. Field entity yang tidak diketahui harus null.
        10. travel_date format YYYY-MM-DD. departure_time format HH:MM 24 jam.
        11. passenger_count harus integer atau null.

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

        $user = implode("\n\n", array_filter([
            "=== PESAN USER TERBARU ===\n".$latestMessage,
            "=== RIWAYAT PERCAKAPAN RINGKAS ===\n".$this->jsonBlock($recentHistory),
            "=== CONVERSATION STATE ===\n".$this->jsonBlock($conversationState),
            "=== SLOT / ENTITY YANG SUDAH DIKETAHUI ===\n".$this->jsonBlock($knownEntities),
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
