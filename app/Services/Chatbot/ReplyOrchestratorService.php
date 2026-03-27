<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingConfirmationService;
use App\Services\Booking\RouteValidationService;

class ReplyOrchestratorService
{
    /**
     * Human-readable label for each required booking field.
     * Used when generating the "missing fields" prompt.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'pickup_location' => 'titik penjemputan',
        'destination'     => 'tujuan perjalanan',
        'passenger_name'  => 'nama penumpang',
        'passenger_count' => 'jumlah penumpang',
        'departure_date'  => 'tanggal keberangkatan',
        'departure_time'  => 'jam keberangkatan',
        'payment_method'  => 'metode pembayaran',
    ];

    public function __construct(
        private readonly BookingConfirmationService $confirmationService,
        private readonly RouteValidationService     $routeValidator,
    ) {}

    /**
     * Compose the final outbound reply text by combining:
     *  - booking engine decision (takes priority when present)
     *  - AI-generated reply from Tahap 3 (fallback when no booking decision)
     *
     * Required context keys:
     *   conversation  (Conversation)
     *   customer      (Customer)
     *   intentResult  (array)
     *   entityResult  (array)
     *   replyResult   (array{text: string, is_fallback: bool})
     *
     * Optional context keys:
     *   bookingDecision  (array|null)   — output of BookingAssistantService::decideNextStep()
     *   booking          (BookingRequest|null)
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>}
     */
    public function compose(array $context): array
    {
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        /** @var Customer $customer */
        $customer        = $context['customer'];
        $intentResult    = $context['intentResult'] ?? [];
        $entityResult    = $context['entityResult'] ?? [];
        $replyResult     = $context['replyResult']  ?? ['text' => '', 'is_fallback' => true];
        $bookingDecision = $context['bookingDecision'] ?? null;
        /** @var BookingRequest|null $booking */
        $booking = $context['booking'] ?? null;

        $customerName = $customer->name ?? null;

        // ── No booking engine involvement → pass through AI reply ─────────
        if ($bookingDecision === null) {
            return [
                'text'        => $replyResult['text'],
                'is_fallback' => $replyResult['is_fallback'],
                'meta'        => ['source' => 'ai_reply'],
            ];
        }

        $action = $bookingDecision['action'] ?? 'general_reply';

        $text = match($action) {
            'ask_missing_fields' => $this->composeMissingFields(
                missingFields : $bookingDecision['missing_fields'] ?? [],
                customerName  : $customerName,
            ),

            'unsupported_route'  => $this->composeUnsupportedRoute(
                booking      : $booking,
                customerName : $customerName,
            ),

            'ask_confirmation'   => $this->composeAskConfirmation(
                booking      : $booking,
                customerName : $customerName,
            ),

            'confirmed'          => $this->composeConfirmed($customerName),

            'booking_cancelled'  => $this->composeCancelled($customerName),

            'unavailable'        => $this->composeUnavailable(
                reason       : $bookingDecision['reason'] ?? null,
                customerName : $customerName,
            ),

            'general_reply'      => $replyResult['text'],

            default              => $replyResult['text'],
        };

        return [
            'text'        => $text !== '' ? $text : $replyResult['text'],
            'is_fallback' => false,
            'meta'        => [
                'source'         => 'booking_engine',
                'action'         => $action,
                'booking_status' => $bookingDecision['booking_status'] ?? null,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Reply composers
    // -------------------------------------------------------------------------

    /** @param array<int, string> $missingFields */
    private function composeMissingFields(array $missingFields, ?string $customerName): string
    {
        $greeting = $customerName ? "Halo, {$customerName}! " : 'Halo! ';

        $fieldLabels = array_map(
            fn (string $field) => '- ' . (self::FIELD_LABELS[$field] ?? $field),
            $missingFields,
        );

        $listStr = implode("\n", $fieldLabels);

        return <<<TEXT
        {$greeting}Untuk melengkapi pesanan Anda, kami masih membutuhkan informasi berikut:

        {$listStr}

        Mohon informasikan data di atas agar kami bisa memproses pesanan Anda.
        TEXT;
    }

    private function composeUnsupportedRoute(?BookingRequest $booking, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';

        $pickup = $booking?->pickup_location ?? 'yang Anda pilih';
        $dest   = $booking?->destination     ?? 'tujuan tersebut';

        $supported = $this->routeValidator->supportedPickups();
        $routeHint = ! empty($supported)
            ? "\n\nKota keberangkatan yang saat ini kami layani: " . implode(', ', $supported) . '.'
            : '';

        return <<<TEXT
        {$greeting} Rute dari *{$pickup}* menuju *{$dest}* belum tersedia dalam layanan kami saat ini.{$routeHint}

        Ada rute lain yang bisa kami bantu?
        TEXT;
    }

    private function composeAskConfirmation(?BookingRequest $booking, ?string $customerName): string
    {
        if ($booking === null) {
            return 'Mohon konfirmasikan pesanan Anda dengan membalas YA atau BENAR.';
        }

        return $this->confirmationService->buildSummary($booking);
    }

    private function composeConfirmed(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return <<<TEXT
        Terima kasih{$name}! Permintaan pemesanan Anda telah berhasil kami catat.

        Tim kami akan segera menghubungi Anda untuk konfirmasi jadwal dan detail pembayaran. Mohon pastikan nomor WhatsApp Anda aktif.
        TEXT;
    }

    private function composeCancelled(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return "Baik{$name}, pesanan Anda telah kami batalkan. Jika suatu saat Anda ingin memesan kembali, kami siap membantu.";
    }

    private function composeUnavailable(?string $reason, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';
        $detail   = $reason ? " {$reason}" : ' Slot keberangkatan yang Anda pilih sedang tidak tersedia.';

        return "{$greeting}{$detail} Apakah Anda ingin mencoba tanggal atau waktu lain?";
    }
}
