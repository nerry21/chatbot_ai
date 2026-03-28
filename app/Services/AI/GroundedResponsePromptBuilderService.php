<?php

namespace App\Services\AI;

use App\Data\AI\GroundedResponseFacts;

class GroundedResponsePromptBuilderService
{
    /**
     * @return array{system: string, user: string}
     */
    public function build(GroundedResponseFacts $facts): array
    {
        $system = <<<'SYSTEM'
Kamu adalah admin travel WhatsApp di Indonesia.
Tugasmu: menyusun balasan yang natural, sopan, ringkas, dan manusiawi BERDASARKAN FAKTA RESMI YANG DIBERIKAN.

ATURAN WAJIB:
1. Gunakan HANYA data yang ada di payload "official_facts", "intent_result", "entity_result", "resolved_context", dan "conversation_summary".
2. DILARANG mengarang harga, jadwal, ketersediaan seat, promo, kebijakan, atau fakta bisnis lain di luar payload.
3. Jika payload belum cukup untuk menjawab, jangan menebak. Ajukan klarifikasi singkat atau arahkan ke admin sesuai mode.
4. Jika pesan pelanggan mengandung sapaan, balas dengan hangat dan natural.
5. Jika pesan pelanggan adalah follow-up, jaga kesinambungan konteks. Jangan mengulang dari nol.
6. Maksimal 3 kalimat. Ringkas, natural, tidak kaku, tidak terasa seperti template mesin.
7. Tidak perlu bullet list kecuali benar-benar diperlukan.
8. Gunakan bahasa Indonesia sopan dengan gaya admin travel.
9. Jika customer_name tersedia, kamu boleh menyapa "Bapak/Ibu" atau gunakan nama secara natural.
10. Kembalikan JSON valid SAJA, tanpa markdown, tanpa penjelasan tambahan.

MODE RESPONS:
- direct_answer: jawab langsung berdasarkan fakta resmi
- clarification_question: ajukan satu pertanyaan klarifikasi yang paling relevan
- booking_continuation: lanjutkan langkah booking berikutnya secara natural
- polite_refusal: sampaikan penolakan/ketidaktersediaan dengan sopan, boleh sertakan alternatif yang memang ada di fakta
- handoff_message: sampaikan akan diteruskan/dicek admin

FORMAT OUTPUT:
{
  "text": "balasan final untuk customer",
  "mode": "direct_answer"
}
SYSTEM;

        $user = $this->buildUserPrompt($facts);

        return [
            'system' => trim($system),
            'user' => $user,
        ];
    }

    private function buildUserPrompt(GroundedResponseFacts $facts): string
    {
        $lines = [];
        $payload = $facts->toArray();

        $lines[] = '=== MODE RESPONS YANG DIMINTA ===';
        $lines[] = $facts->mode->value;
        $lines[] = '';

        $lines[] = '=== PESAN CUSTOMER TERBARU ===';
        $lines[] = $facts->latestMessageText;
        $lines[] = '';

        if ($facts->customerName !== null && $facts->customerName !== '') {
            $lines[] = 'Nama customer: '.$facts->customerName;
            $lines[] = '';
        }

        if ($facts->conversationSummary !== null && trim($facts->conversationSummary) !== '') {
            $lines[] = '=== RINGKASAN PERCAKAPAN ===';
            $lines[] = $facts->conversationSummary;
            $lines[] = '';
        }

        if ($facts->resolvedContext !== []) {
            $lines[] = '=== KONTEKS AKTIF ===';
            $lines[] = $this->json($facts->resolvedContext);
            $lines[] = '';
        }

        $lines[] = '=== INTENT RESULT ===';
        $lines[] = $this->json($facts->intentResult);
        $lines[] = '';

        if ($facts->entityResult !== []) {
            $lines[] = '=== ENTITY RESULT ===';
            $lines[] = $this->json($facts->entityResult);
            $lines[] = '';
        }

        $lines[] = '=== OFFICIAL FACTS ===';
        $lines[] = $this->json($facts->officialFacts);
        $lines[] = '';

        $lines[] = '=== PENGINGAT ===';
        $lines[] = 'Jawab hanya dari fakta resmi di atas. Jika fakta tidak cukup, jangan mengarang.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
