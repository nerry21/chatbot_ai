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
            'Izin Bapak/Ibu, berikut kami kirimkan ringkasan perjalanan Anda.',
            '',
            'Jumlah penumpang : ' . (($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count . ' orang' : '-'),
            'Tanggal          : ' . ($booking->departure_date?->translatedFormat('d F Y') ?? '-'),
            'Jam              : ' . ($booking->departure_time ? $booking->departure_time . ' WIB' : '-'),
            'Seat             : ' . $seatText,
            'Titik jemput     : ' . ($booking->pickup_location ?? '-'),
            'Alamat jemput    : ' . ($booking->pickup_full_address ?? '-'),
            'Tujuan antar     : ' . ($booking->destination ?? '-'),
            'Nama penumpang   : ' . ($names !== [] ? implode(', ', $names) : '-'),
            'Nomor kontak     : ' . ($booking->contact_number ?? '-'),
            'Total ongkos     : ' . $this->fareCalculator->formatRupiah($fare),
            '',
            'Apakah data ini sudah benar ya Bapak/Ibu?',
            'Jika sudah sesuai, silakan balas YA / BENAR / SUDAH / OKE ya.',
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
            'Tujuan antar     : ' . ($booking->destination ?? '-'),
            'Nama penumpang   : ' . ($names !== [] ? implode(', ', $names) : '-'),
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
}
