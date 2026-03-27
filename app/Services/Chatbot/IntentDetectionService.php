<?php

namespace App\Services\Chatbot;

use App\Enums\IntentType;

class IntentDetectionService
{
    public function __construct(
        private readonly ConversationTextNormalizerService $textNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $rawIntentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $replyResult
     * @return array{intent: string, confidence: float, reasoning_short: string}
     */
    public function detect(
        array $rawIntentResult,
        string $messageText,
        array $signals,
        array $slots,
        array $updates = [],
        array $replyResult = [],
    ): array {
        $rawIntent = IntentType::tryFrom((string) ($rawIntentResult['intent'] ?? ''));
        $confidence = (float) ($rawIntentResult['confidence'] ?? 0.0);

        if (($signals['close_intent'] ?? false) === true) {
            return $this->result(IntentType::CloseIntent, max($confidence, 0.98), 'Customer memberi sinyal penutup.');
        }

        if (($signals['greeting_only'] ?? false) === true && ($signals['salam_type'] ?? null) === 'islamic') {
            return $this->result(IntentType::SalamIslam, max($confidence, 0.98), 'Pesan berisi salam Islam tanpa kebutuhan lain.');
        }

        if (($signals['greeting_only'] ?? false) === true) {
            return $this->result(IntentType::Greeting, max($confidence, 0.95), 'Pesan berupa sapaan pembuka.');
        }

        if (($signals['today_schedule_keyword'] ?? false) === true) {
            return $this->result(IntentType::TanyaKeberangkatanHariIni, max($confidence, 0.98), 'Customer menanyakan keberangkatan hari ini.');
        }

        if ($this->isFinalConfirmation($signals, $slots, $updates)) {
            return $this->result(IntentType::KonfirmasiBooking, max($confidence, 0.98), 'Customer mengonfirmasi review booking.');
        }

        if ($this->isChangeRequest($signals, $slots)) {
            return $this->result(IntentType::UbahDataBooking, max($confidence, 0.97), 'Customer ingin mengubah data booking.');
        }

        if (($signals['price_keyword'] ?? false) === true || in_array($rawIntent, [IntentType::PriceInquiry, IntentType::TanyaHarga], true)) {
            return $this->result(IntentType::TanyaHarga, max($confidence, 0.95), 'Customer menanyakan harga perjalanan.');
        }

        if ($this->isRouteInquiry($signals, $messageText, $rawIntent)) {
            return $this->result(IntentType::TanyaRute, max($confidence, 0.95), 'Customer menanyakan rute atau titik layanan.');
        }

        if (($signals['schedule_keyword'] ?? false) === true || in_array($rawIntent, [IntentType::ScheduleInquiry, IntentType::TanyaJam], true)) {
            return $this->result(IntentType::TanyaJam, max($confidence, 0.95), 'Customer menanyakan jam atau jadwal keberangkatan.');
        }

        if (
            ($signals['booking_keyword'] ?? false) === true
            || $updates !== []
            || $this->hasBookingState($slots)
            || ($rawIntent?->isBookingRelated() === true)
            || in_array($rawIntent, [IntentType::Booking, IntentType::KonfirmasiBooking, IntentType::UbahDataBooking], true)
        ) {
            return $this->result(IntentType::Booking, max($confidence, 0.90), 'Pesan terkait alur booking aktif.');
        }

        if (
            ($replyResult['is_fallback'] ?? false) === true
            || in_array($rawIntent, [
                IntentType::Unknown,
                IntentType::OutOfScope,
                IntentType::Support,
                IntentType::HumanHandoff,
                IntentType::PertanyaanTidakTerjawab,
            ], true)
        ) {
            return $this->result(IntentType::PertanyaanTidakTerjawab, max($confidence, 0.75), 'Pertanyaan belum dapat dijawab otomatis.');
        }

        return match ($rawIntent) {
            IntentType::SalamIslam,
            IntentType::TanyaKeberangkatanHariIni,
            IntentType::TanyaHarga,
            IntentType::TanyaRute,
            IntentType::TanyaJam,
            IntentType::BookingCancel,
            IntentType::KonfirmasiBooking,
            IntentType::UbahDataBooking,
            IntentType::PertanyaanTidakTerjawab,
            IntentType::CloseIntent,
            IntentType::Greeting,
            IntentType::Booking => $this->result($rawIntent, max($confidence, 0.80), (string) ($rawIntentResult['reasoning_short'] ?? 'Intent terdeteksi.')),
            IntentType::BookingConfirm,
            IntentType::Confirmation => $this->result(IntentType::KonfirmasiBooking, max($confidence, 0.85), 'Konfirmasi pelanggan terdeteksi.'),
            IntentType::PriceInquiry => $this->result(IntentType::TanyaHarga, max($confidence, 0.85), 'Pertanyaan harga terdeteksi.'),
            IntentType::ScheduleInquiry => $this->result(IntentType::TanyaJam, max($confidence, 0.85), 'Pertanyaan jadwal terdeteksi.'),
            IntentType::LocationInquiry => $this->result(IntentType::TanyaRute, max($confidence, 0.85), 'Pertanyaan rute terdeteksi.'),
            IntentType::Farewell => $this->result(IntentType::CloseIntent, max($confidence, 0.85), 'Pesan penutup terdeteksi.'),
            IntentType::Rejection => $this->result(IntentType::UbahDataBooking, max($confidence, 0.85), 'Penolakan atau perubahan data terdeteksi.'),
            IntentType::BookingCancel,
            IntentType::Support,
            IntentType::HumanHandoff,
            IntentType::OutOfScope,
            IntentType::Unknown,
            null => [
                'intent' => IntentType::PertanyaanTidakTerjawab->value,
                'confidence' => max($confidence, 0.50),
                'reasoning_short' => (string) ($rawIntentResult['reasoning_short'] ?? 'Intent tidak terpetakan secara spesifik.'),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     */
    private function isFinalConfirmation(array $signals, array $slots, array $updates): bool
    {
        return ($slots['review_sent'] ?? false) === true
            && ($signals['affirmation'] ?? false) === true
            && $updates === [];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     */
    private function isChangeRequest(array $signals, array $slots): bool
    {
        return (($signals['rejection'] ?? false) === true || ($signals['change_request'] ?? false) === true)
            && ($this->hasBookingState($slots) || ($slots['review_sent'] ?? false) === true);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isRouteInquiry(array $signals, string $messageText, ?IntentType $rawIntent): bool
    {
        if (in_array($rawIntent, [IntentType::LocationInquiry, IntentType::TanyaRute], true)) {
            return true;
        }

        if (($signals['route_keyword'] ?? false) !== true) {
            return false;
        }

        $normalized = $this->textNormalizer->normalize($messageText);

        return (bool) preg_match('/\b(rute|trayek|lokasi jemput|titik jemput|tujuan tersedia|antar ke mana)\b/u', $normalized);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasBookingState(array $slots): bool
    {
        foreach ([
            'pickup_location',
            'pickup_full_address',
            'destination',
            'passenger_name',
            'passenger_names',
            'passenger_count',
            'travel_date',
            'travel_time',
            'selected_seats',
            'contact_number',
        ] as $key) {
            $value = $slots[$key] ?? null;

            if (is_array($value) && $value !== []) {
                return true;
            }

            if (! is_array($value) && filled($value)) {
                return true;
            }
        }

        return false;
    }

    private function result(IntentType $intent, float $confidence, string $reasoning): array
    {
        return [
            'intent' => $intent->value,
            'confidence' => min(1.0, max(0.0, $confidence)),
            'reasoning_short' => $reasoning,
        ];
    }
}
