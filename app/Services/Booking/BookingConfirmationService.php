<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;

class BookingConfirmationService
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    /**
     * Build a human-readable booking summary for display to the customer.
     * All values are sourced from the booking record — no AI involvement.
     */
    public function buildSummary(BookingRequest $booking): string
    {
        $lines = [];

        $lines[] = '*Ringkasan Pesanan Anda:*';
        $lines[] = '';

        $lines[] = $this->row('Nama Penumpang', $booking->passenger_name ?? '-');
        $lines[] = $this->row('Titik Jemput',   $booking->pickup_location ?? '-');
        $lines[] = $this->row('Tujuan',          $booking->destination ?? '-');
        $lines[] = $this->row('Jml. Penumpang',  $booking->passenger_count
            ? $booking->passenger_count . ' orang'
            : '-');

        if ($booking->departure_date !== null) {
            $lines[] = $this->row(
                'Tanggal',
                $booking->departure_date->translatedFormat('d F Y'),
            );
        }

        if ($booking->departure_time !== null) {
            $lines[] = $this->row('Jam Berangkat', $booking->departure_time . ' WIB');
        }

        // Refresh price estimate at summary time if still null
        $price = $booking->price_estimate !== null
            ? (float) $booking->price_estimate
            : $this->pricing->estimate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );

        $lines[] = $this->row(
            'Estimasi Harga',
            $this->pricing->formatRupiah($price),
        );

        if ($booking->special_notes !== null) {
            $lines[] = '';
            $lines[] = '_Catatan: ' . $booking->special_notes . '_';
        }

        $lines[] = '';
        $lines[] = 'Mohon balas *YA* atau *BENAR* untuk mengkonfirmasi pesanan Anda.';
        $lines[] = 'Balas *TIDAK* atau *BATAL* untuk membatalkan.';

        return implode("\n", $lines);
    }

    /**
     * Transition a booking to awaiting_confirmation status and persist it.
     * Also refreshes the price estimate if it can be calculated.
     */
    public function requestConfirmation(BookingRequest $booking): void
    {
        // Refresh price estimate before asking for confirmation
        if ($booking->price_estimate === null) {
            $estimate = $this->pricing->estimate(
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

    /**
     * Confirm a booking that is currently in awaiting_confirmation status.
     */
    public function confirm(BookingRequest $booking): void
    {
        $booking->markConfirmed();
        $booking->save();
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function row(string $label, string $value): string
    {
        return str_pad($label, 17) . ': ' . $value;
    }
}
