<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;
use App\Services\Chatbot\ResponseVariationService;

class BookingReviewFormatterService
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly ResponseVariationService $variation,
    ) {}

    public function buildCustomerReview(BookingRequest $booking, ?string $seed = null): string
    {
        $reviewSeed = $seed;

        return implode("\n", [
            $this->variation->pick([
                'Baik Bapak/Ibu, berikut review booking perjalanannya:',
                'Baik Bapak/Ibu, saya rangkum dulu data perjalanannya ya:',
                'Izin Bapak/Ibu, berikut ringkasan bookingnya ya:',
            ], $reviewSeed),
            '',
            'Tanggal keberangkatan : '.($booking->departure_date?->translatedFormat('d F Y') ?? '-'),
            'Jam keberangkatan     : '.($booking->departure_time ? $booking->departure_time.' WIB' : '-'),
            'Jumlah penumpang      : '.(($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count.' orang' : '-'),
            'Seat terpilih         : '.(($booking->selected_seats ?? []) !== [] ? implode(', ', $booking->selected_seats ?? []) : '-'),
            'Titik jemput          : '.($booking->pickup_location ?? '-'),
            'Alamat jemput         : '.($booking->pickup_full_address ?? '-'),
            'Tujuan antar          : '.($booking->destination ?? '-'),
            'Nama penumpang        : '.($booking->passengerNamesList() !== [] ? implode(', ', $booking->passengerNamesList()) : '-'),
            'No HP                 : '.($booking->contact_number ?? '-'),
            'Total ongkos          : '.$this->fareCalculator->formatRupiah($this->resolvedFare($booking)),
            '',
            $this->variation->pick([
                'Mohon izin Bapak/Ibu, apakah data perjalanan ini sudah benar?',
                'Mohon izin Bapak/Ibu, apakah detail perjalanan ini sudah sesuai?',
                'Izin konfirmasi Bapak/Ibu, apakah data perjalanan ini sudah tepat?',
            ], $reviewSeed !== null ? $reviewSeed.'|confirm' : null),
        ]);
    }

    public function buildAdminReview(BookingRequest $booking, string $customerPhone): string
    {
        $lines = [
            'Forward booking baru JET dari WhatsApp AI.',
            'Status tindak lanjut : Pending admin',
            'No customer          : '.$customerPhone,
            'Tanggal keberangkatan: '.($booking->departure_date?->format('Y-m-d') ?? '-'),
            'Jam keberangkatan    : '.($booking->departure_time ? $booking->departure_time.' WIB' : '-'),
            'Jumlah penumpang     : '.(($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count.' orang' : '-'),
            'Seat terpilih        : '.(($booking->selected_seats ?? []) !== [] ? implode(', ', $booking->selected_seats ?? []) : '-'),
            'Titik jemput         : '.($booking->pickup_location ?? '-'),
            'Alamat jemput        : '.($booking->pickup_full_address ?? '-'),
            'Tujuan antar         : '.($booking->destination ?? '-'),
            'Nama penumpang       : '.($booking->passengerNamesList() !== [] ? implode(', ', $booking->passengerNamesList()) : '-'),
            'No HP                : '.($booking->contact_number ?? '-'),
            'Total ongkos         : '.$this->fareCalculator->formatRupiah($this->resolvedFare($booking)),
        ];

        if ((int) ($booking->passenger_count ?? 0) === (int) config('chatbot.jet.passenger.manual_confirm_max', 6)) {
            $lines[] = 'Catatan              : Perlu konfirmasi manual admin untuk 6 penumpang.';
        }

        return implode("\n", $lines);
    }

    private function resolvedFare(BookingRequest $booking): ?int
    {
        if ($booking->price_estimate !== null) {
            return (int) round((float) $booking->price_estimate);
        }

        return $this->fareCalculator->calculate(
            $booking->pickup_location,
            $booking->destination,
            $booking->passenger_count,
        );
    }
}
