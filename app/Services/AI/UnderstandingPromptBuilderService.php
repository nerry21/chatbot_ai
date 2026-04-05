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
     * @param  array<string, mixed>  $crmHints
     * @return array{system: string, user: string}
     */
    public function build(
        string $latestMessage,
        array $recentHistory = [],
        array $conversationState = [],
        array $knownEntities = [],
        array $allowedIntents = [],
        ?string $conversationSummary = null,
        array $crmHints = [],
        bool $adminTakeover = false,
    ): array {
        $allowedIntentList = implode(', ', $allowedIntents);

        $system = <<<SYSTEM
Kamu adalah mesin understanding untuk chatbot WhatsApp travel antar kota di Indonesia.
Tugasmu HANYA memahami pesan user dan mengembalikan JSON terstruktur.
Kamu TIDAK boleh membuat jawaban final ke user.

PRINSIP UTAMA:
1. Prioritaskan pemahaman dari pesan user terbaru.
2. Gunakan riwayat percakapan yang relevan bila membantu interpretasi.
3. Gunakan conversation state dan known entities untuk menjaga kesinambungan.
4. Gunakan CRM hints HANYA sebagai petunjuk ringan, bukan sebagai pengarah utama intent.
5. Jangan memaksa intent hanya karena ada hint CRM bila pesan user terbaru mengarah ke hal lain.
6. Jangan mengarang fakta bisnis, harga, jadwal, policy, status booking, atau status CRM.
7. Jika konteks belum cukup, pilih intent paling aman dan set needs_clarification=true.
8. reasoning_summary maksimal 1 kalimat singkat.
9. Bila CRM hints dipakai, sebut secukupnya sebagai "hint kontinuitas", bukan sebagai sumber kebenaran utama.
10. handoff_recommended=true hanya bila user jelas meminta admin/manusia, ada sinyal escalation kuat, atau konteks memang tidak aman untuk otomatis.

INTENT YANG DIIZINKAN:
{$allowedIntentList}

ATURAN OUTPUT:
1. Output HARUS JSON object valid saja, tanpa markdown, tanpa kalimat pembuka/penutup.
2. Gunakan hanya intent dari daftar yang diizinkan.
3. Jika tidak yakin, pilih "unknown" bila tersedia di daftar intent.
4. Field entity yang tidak diketahui harus null.
5. travel_date format YYYY-MM-DD.
6. departure_time format HH:MM 24 jam.
7. passenger_count harus integer atau null.
8. uses_previous_context=true hanya jika memang benar memakai history/state/hints untuk memahami pesan.
9. Jangan menyebut field yang tidak ada pada format output.

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
            crmHints: $crmHints,
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
