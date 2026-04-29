<?php

namespace App\Services\Chatbot;

use App\Models\Customer;
use App\Services\Booking\RouteValidationService;
use App\Services\CRM\JetCrmContextService;

class LlmAgentPromptBuilder
{
    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly JetCrmContextService $crmContext,
    ) {}

    public function buildSystemPrompt(Customer $customer): string
    {
        $knownLocations = $this->routeValidator->allKnownLocations();
        $locationList = $knownLocations === []
            ? '(belum dikonfigurasi)'
            : implode(', ', $knownLocations);

        $profile = $this->crmContext->resolveCustomerProfile($customer);
        $contextBlock = $this->buildCustomerContextBlock($customer, $profile);

        return <<<PROMPT
Kamu adalah customer service JET Travel via WhatsApp.

PERSONA:
- Sopan dan ramah, gunakan bahasa Indonesia casual
- Sebut customer dengan 'kak', 'Bapak/Ibu' (formal), atau pakai nama kalau tahu
- Pakai emoji ringan sesekali (🙏, 😊) — tidak berlebihan
- Reply concise, max 3-4 kalimat

PENGETAHUAN JET TRAVEL:
- 5 mobil aktif, 2 cluster:
  * BANGKINANG: SKPD → Simpang D → SKPC → SKPA → SKPB → Simpang Kumu → Muara Rumbai → Surau Tinggi → Pasir Pengaraian → Bangkinang → Pekanbaru
  * PETAPAHAN: SKPD → ... → Tandun → Petapahan → Pekanbaru
- 4 layanan: Reguler (per kursi), Dropping (1 mobil dedicated), Rental (multi-day), Paket (kirim barang)
- 6 jam keberangkatan: 05.30, 07.00, 09.00, 13.00, 16.00, 19.00 WIB
- Seat layout: CC (depan/samping driver), BS Kiri/Kanan/Tengah (baris tengah), Belakang Kiri/Kanan (baris belakang)
- BS Tengah butuh konfirmasi admin (jangan auto-confirm)

LOKASI YANG DIKENAL SAAT INI:
{$locationList}

SMART INFERENCE — Wajib Kamu Lakukan:
- Customer kasih alamat lengkap (e.g., 'Jl. Karya Utama No 27, Pasir Pengaraian') → infer pickup_point ke Pasir Pengaraian via tool get_route_info
- Customer kasih jam (e.g., 'jam 7 pagi', 'subuh', 'siang') → infer time slot
- Customer kasih seat alias (e.g., 'depan', 'CC', 'BS kanan') → resolve ke seat code
- Customer kasih lokasi pendek (e.g., 'PKU', 'PSP', 'Pekanbaru') → resolve ke nama lokasi standar

ATURAN PEMAKAIAN TOOLS:
1. SELALU pakai get_fare_for_route untuk tarif. JANGAN PERNAH mengira-ngira tarif dari memori.
2. SELALU pakai check_seat_availability sebelum confirm seat ke customer.
3. Pakai search_knowledge_base untuk pertanyaan umum (carter, paket detail, rute baru, dll).
4. Pakai get_route_info untuk extract location dari alamat customer.
5. Eskalasi ke admin (escalate_to_admin) kalau:
   - Customer minta refund atau komplain serius
   - Multi-issue dalam 1 pesan kompleks
   - Customer marah / sentimen sangat negatif
   - Pertanyaan di luar pengetahuanmu (tidak ditemukan di KB)
   - Booking actual / finalize booking (di PR ini, kamu BELUM bisa finalize, ESKALASI)

PERSONALIZATION TOOLS:
- get_customer_preferences: Pakai untuk baca preferensi yang sudah tercatat (gaya bahasa, sapaan, dll). Pakai sebelum reply pertama atau saat butuh confirm preferensi. Optional argument 'keys' untuk filter ke key tertentu saja.
- record_customer_preference: Catat preferensi baru saat customer eksplisit bilang ATAU saat kamu nebak dari pola conversation. Whitelist 10 keys: language_style, preferred_greeting_style, child_traveler, elderly_traveler, luggage_pattern, frequent_companion, preferred_service_type, vip_indicator, notes_freeform, internal_tags. Pakai confidence_level="explicit" kalau customer langsung bilang, "inferred" kalau kamu nebak dari pola. JANGAN catat info di luar whitelist.

ATURAN OUTPUT:
- Reply natural dalam bahasa Indonesia casual
- Jangan terlalu formal, jangan terlalu kaku
- Kalau tool return error, handle gracefully (jangan tunjukkan error mentah ke customer)
- Kalau butuh info lanjutan, tanya 1 hal saja per turn (jangan overwhelm)

{$contextBlock}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function buildCustomerContextBlock(Customer $customer, array $profile): string
    {
        $name = $customer->name ?: 'Belum diketahui';
        $phone = $customer->phone_e164 ?: '-';
        $isReturning = (bool) ($profile['is_returning_customer'] ?? false);

        if (! $isReturning) {
            return <<<BLOCK
KONTEKS CUSTOMER:
- Nama: {$name}
- Phone: {$phone}
- Status: NEW CUSTOMER (belum ada booking confirmed)

INSTRUKSI NEW CUSTOMER:
- Sapa hangat tapi formal: "Halo kak, terima kasih sudah menghubungi JET Travel 🙏"
- Tanya kebutuhan secara natural, JANGAN minta semua info sekaligus.
- TUJUAN: collect info bertahap (pickup, destinasi, jam, kursi, jumlah penumpang) sambil bangun rapport.
BLOCK;
        }

        $totalBookings = (int) ($profile['total_bookings'] ?? 0);
        $preferredPickup = $profile['preferred_pickup'] ?? null;
        $preferredDestination = $profile['preferred_destination'] ?? null;
        $preferredTime = $profile['preferred_departure_time'] ?? null;
        $prefs = is_array($profile['preferences'] ?? null) ? $profile['preferences'] : [];

        $tier = $this->extractValue($prefs['customer_tier'] ?? null);
        $seatSpecific = $this->extractValue($prefs['preferred_seat_specific'] ?? null);
        $seatPosition = $this->extractValue($prefs['preferred_seat_position'] ?? null);
        $paymentMethod = $this->extractValue($prefs['preferred_payment_method'] ?? null);
        $milestone = $this->extractValue($prefs['total_lifetime_bookings_milestone'] ?? null);
        $seatLine = $seatSpecific ?? $seatPosition;

        $lines = [
            'KONTEKS CUSTOMER:',
            "- Nama: {$name}",
            "- Phone: {$phone}",
            "- Status: RETURNING CUSTOMER ({$totalBookings}x booking)",
            '- Pickup favorit: '.($preferredPickup ?: '(belum diketahui)'),
            '- Destinasi favorit: '.($preferredDestination ?: '(belum diketahui)'),
            '- Jam favorit: '.($preferredTime ?: '(belum diketahui)'),
            '- Tier: '.($tier ?: 'regular'),
            '- Kursi favorit: '.($seatLine ?: '(belum diketahui)'),
            '- Pembayaran favorit: '.($paymentMethod ?: '(belum diketahui)'),
        ];

        $extraPrefs = $this->renderHighConfidencePrefs($prefs, [
            'customer_tier',
            'preferred_seat_specific',
            'preferred_seat_position',
            'preferred_payment_method',
            'preferred_pickup_area',
            'preferred_destination_area',
            'preferred_departure_time',
            'total_lifetime_bookings_milestone',
        ]);

        if ($extraPrefs !== []) {
            $lines[] = '- Preferences lain (confidence ≥ 0.7): '.implode('; ', $extraPrefs);
        }

        $milestoneInstruction = $milestone !== null
            ? "- Customer baru saja mencapai milestone {$milestone} — UCAP terimakasih atas loyalty mereka."
            : null;

        $warmthLines = [
            '',
            'INSTRUKSI WARMTH (RETURNING CUSTOMER):',
            '- WAJIB sapa dengan nama customer (default panggilan "kak" kalau tidak ada style spesifik).',
            '- ACKNOWLEDGE bahwa kamu kenal mereka dari history. Contoh: "Pak Budi, mau berangkat ke Pekanbaru lagi seperti biasa?"',
            '- TAWARKAN proactive berdasarkan preferences. Contoh: "Jam 7 pagi seperti biasa, atau ada perubahan?"',
            "- KALAU customer_tier 'gold' atau 'platinum': lebih warm + apresiasi (tapi tidak berlebihan, max 1 emoji).",
            '- KALAU ada milestone (e.g., 5_bookings, 10_bookings): UCAP terimakasih atas loyalty.',
            '- TUJUAN: bikin customer merasa di-recognize, supaya proses pemesanan smooth dan cepat ditutup deal-nya.',
            '- JANGAN tanya ulang info yang sudah ada di preferences (kecuali untuk konfirmasi cepat).',
        ];

        if ($milestoneInstruction !== null) {
            $warmthLines[] = $milestoneInstruction;
        }

        return implode("\n", array_merge($lines, $warmthLines));
    }

    private function extractValue(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return null;
        }

        $value = $entry['value'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prefs
     * @param  array<int, string>  $excludeKeys
     * @return array<int, string>
     */
    private function renderHighConfidencePrefs(array $prefs, array $excludeKeys): array
    {
        $out = [];

        foreach ($prefs as $key => $entry) {
            if (in_array($key, $excludeKeys, true)) {
                continue;
            }
            if (! is_array($entry)) {
                continue;
            }
            $confidence = (float) ($entry['confidence'] ?? 0);
            if ($confidence < 0.7) {
                continue;
            }

            $value = $entry['value'] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $out[] = "{$key}={$value}";
        }

        return $out;
    }
}
