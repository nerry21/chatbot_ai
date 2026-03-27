<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;

class BookingConfirmationService
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
    ) {
    }

    public function buildSummary(BookingRequest $booking): string
    {
        $names = $booking->passengerNamesList();
        $seatText = $booking->selected_seats !== null && $booking->selected_seats !== []
            ? implode(', ', $booking->selected_seats)
            : '-';
        $fare = $booking->price_estimate !== null
            ? (int) round((float) $booking->price_estimate)
            : $this->fareCalculator->calculate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );

        $lines = [
            'Baik, saya rangkum dulu data perjalanannya:',
            '',
            'Jumlah penumpang : ' . (($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count . ' orang' : '-'),
            'Tanggal          : ' . ($booking->departure_date?->translatedFormat('d F Y') ?? '-'),
            'Jam berangkat    : ' . ($booking->departure_time ? $booking->departure_time . ' WIB' : '-'),
            'Seat             : ' . $seatText,
            'Titik jemput     : ' . ($booking->pickup_location ?? '-'),
            'Alamat jemput    : ' . ($booking->pickup_full_address ?? '-'),
            'Tujuan           : ' . ($booking->destination ?? '-'),
            'Nama penumpang   : ' . ($names !== [] ? implode(', ', $names) : '-'),
            'Metode bayar     : ' . $this->paymentMethodLabel($booking->payment_method),
            'Nomor kontak     : ' . ($booking->contact_number ?? '-'),
            'Estimasi ongkos  : ' . $this->fareCalculator->formatRupiah($fare),
            '',
            'Kalau datanya sudah benar, silakan balas YA / BENAR / SUDAH ya.',
        ];

        return implode("\n", $lines);
    }

    public function buildAdminSummary(BookingRequest $booking, string $customerPhone): string
    {
        $names = $booking->passengerNamesList();

        return implode("\n", [
            'Forward booking baru JET dari WhatsApp AI.',
            'No customer      : ' . $customerPhone,
            'Jumlah penumpang : ' . (($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count . ' orang' : '-'),
            'Tanggal          : ' . ($booking->departure_date?->format('Y-m-d') ?? '-'),
            'Jam              : ' . ($booking->departure_time ? $booking->departure_time . ' WIB' : '-'),
            'Seat             : ' . (($booking->selected_seats ?? []) !== [] ? implode(', ', $booking->selected_seats ?? []) : '-'),
            'Titik jemput     : ' . ($booking->pickup_location ?? '-'),
            'Alamat jemput    : ' . ($booking->pickup_full_address ?? '-'),
            'Tujuan           : ' . ($booking->destination ?? '-'),
            'Nama penumpang   : ' . ($names !== [] ? implode(', ', $names) : '-'),
            'Metode bayar     : ' . $this->paymentMethodLabel($booking->payment_method),
            'Nomor kontak     : ' . ($booking->contact_number ?? '-'),
            'Total ongkos     : ' . $this->fareCalculator->formatRupiah(
                $booking->price_estimate !== null
                    ? (int) round((float) $booking->price_estimate)
                    : null,
            ),
        ]);
    }

    public function requestConfirmation(BookingRequest $booking): void
    {
        if ($booking->price_estimate === null) {
            $estimate = $this->fareCalculator->calculate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );

            if ($estimate !== null) {
                $booking->price_estimate = $estimate;
            }
        }

        $booking->markAwaitingConfirmation();
        $booking->save();
    }

    public function confirm(BookingRequest $booking): void
    {
        $booking->markConfirmed();
        $booking->save();
    }

    private function paymentMethodLabel(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        foreach ((array) config('chatbot.jet.payment_methods', []) as $method) {
            if (($method['id'] ?? null) === $value) {
                return (string) ($method['label'] ?? $value);
            }
        }

        return mb_convert_case((string) $value, MB_CASE_TITLE, 'UTF-8');
    }
}
