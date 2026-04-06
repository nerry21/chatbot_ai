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
        ?string $traceId = null,
    ): array {
        $allowedIntentList = $allowedIntents !== []
            ? implode(', ', $allowedIntents)
            : 'unknown';

        $latestMessage = $this->normalizeInlineText($latestMessage) ?? '';
        $conversationSummary = $this->normalizeInlineText($conversationSummary);

        $system = <<<SYSTEM
Kamu adalah mesin understanding untuk chatbot WhatsApp travel antar kota di Indonesia.
Tugasmu HANYA memahami pesan user dan mengembalikan JSON terstruktur.
Kamu TIDAK boleh membuat jawaban final ke user.
Kamu TIDAK boleh membuat keputusan bisnis final.
Kamu TIDAK boleh menganggap CRM sebagai sumber kebenaran utama.

PRINSIP UTAMA:
1. Prioritaskan pemahaman dari pesan user terbaru.
2. Gunakan riwayat percakapan yang relevan bila membantu interpretasi.
3. Gunakan conversation state dan known entities untuk menjaga kesinambungan.
4. Gunakan CRM hints HANYA sebagai petunjuk ringan kontinuitas, bukan sebagai pengarah utama intent.
5. Jangan memaksa intent hanya karena ada hint CRM bila pesan user terbaru mengarah ke hal lain.
6. Jangan mengarang fakta bisnis, harga, jadwal, policy, status booking, status pembayaran, atau status CRM.
7. Jika konteks belum cukup, pilih intent paling aman dan set needs_clarification=true.
8. reasoning_summary maksimal 1 kalimat singkat.
9. Bila CRM hints dipakai, perlakukan sebagai continuity hints, bukan sumber kebenaran utama.
10. handoff_recommended=true hanya bila user jelas meminta admin/manusia, ada sinyal escalation kuat, atau konteks memang tidak aman untuk otomatis.
11. Jangan menyalin mentah isi CRM hints ke reasoning_summary.
12. Jika ada ketegangan antara pesan terbaru dan CRM hints, utamakan pesan terbaru.
13. Jika conversation_state, known_entities, dan CRM hints saling bertentangan, gunakan pesan terbaru sebagai prioritas pertama, conversation_state sebagai prioritas kedua, known_entities sebagai prioritas ketiga, dan CRM hints sebagai prioritas terakhir.
14. Jangan menyimpulkan booking sudah valid, confirmed, paid, atau selesai kecuali user terbaru benar-benar menunjukkan itu.
15. Jika pesan user terlalu pendek, ambigu, atau hanya berupa balasan singkat seperti "iya", "lanjut", "jadi", "yang itu", gunakan history/state secara hati-hati; bila masih tidak jelas, set needs_clarification=true dan turunkan confidence.
16. Jika admin_takeover=true atau CRM hints menunjukkan bot_paused/admin_takeover_active, jangan otomatis menganggap intent berubah; tetap pahami isi pesan user secara literal dan konservatif.
17. Gunakan crm_hints hanya untuk continuity, misalnya mengenali bahwa user sedang di alur booking, pernah escalation, atau perlu follow-up, tetapi bukan untuk mengubah makna pesan terbaru.
18. Jangan menambahkan field baru di luar format output.

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
8. uses_previous_context=true hanya jika memang benar memakai history/state/hints untuk memahami pesan. Jika intent bisa dipahami dari pesan user terbaru saja, gunakan false.
9. Jangan menyebut field yang tidak ada pada format output.
10. confidence harus angka 0 sampai 1.
11. clarification_question wajib null bila needs_clarification=false.
12. handoff_recommended jangan dijadikan true hanya karena CRM hints menyebut lead/escalation lama tanpa dukungan pesan terbaru.
13. confidence harus konservatif. Jika pesan ambigu, jangan memberi confidence tinggi.
14. reasoning_summary harus menjelaskan alasan singkat berbasis pesan user terbaru, dan bila konteks dipakai cukup sebut secara umum tanpa menyalin detail CRM.
15. Jika intent tetap belum jelas setelah mempertimbangkan history/state/hints, gunakan intent paling aman dan set needs_clarification=true.

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
            $traceId !== null && trim($traceId) !== '' ? "=== TRACE CONTEXT ===\ntrace_id=".trim($traceId) : null,
            "=== BOUNDARY KONTEKS UNDERSTANDING ===\n".$this->understandingBoundaryInstructionBlock(),
            "=== PESAN USER TERBARU ===\n".$latestMessage,
            "=== RIWAYAT PERCAKAPAN RINGKAS ===\n".$this->jsonBlock($recentHistory),
            "=== CONVERSATION STATE ===\n".$this->jsonBlock($conversationState),
            "=== SLOT / ENTITY YANG SUDAH DIKETAHUI ===\n".$this->jsonBlock($knownEntities),
            $this->conversationSummaryBlock($conversationSummary),
            $crmBlock,
            "=== INTENT YANG DIIZINKAN ===\n".$this->jsonBlock($allowedIntents),
        ]));

        return [
            'system' => trim($system),
            'user' => trim($user),
        ];
    }

    private function normalizeInlineText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private function understandingBoundaryInstructionBlock(): string
    {
        return implode("\n", [
            '- Gunakan pesan user terbaru sebagai sumber utama.',
            '- Riwayat, conversation state, dan known entities hanya membantu kesinambungan.',
            '- CRM hints hanya continuity hints, bukan sumber kebenaran utama.',
            '- Jangan mengambil keputusan bisnis final dari hints.',
            '- Jika konteks kurang, pilih intent paling aman dan set needs_clarification=true.',
        ]);
    }

    private function conversationSummaryBlock(?string $conversationSummary): ?string
    {
        $summary = $this->normalizeInlineText($conversationSummary);

        if ($summary === null) {
            return null;
        }

        return "=== RINGKASAN PERCAKAPAN ===\n".$summary;
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
