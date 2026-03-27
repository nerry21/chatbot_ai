<?php

namespace Tests\Unit;

use App\Models\BookingRequest;
use App\Services\Booking\BookingReviewFormatterService;
use Tests\TestCase;

class BookingReviewFormatterServiceTest extends TestCase
{
    public function test_it_builds_customer_review_with_all_required_jet_fields(): void
    {
        $service = app(BookingReviewFormatterService::class);
        $booking = BookingRequest::make([
            'pickup_location' => 'Pasirpengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Andi',
            'passenger_names' => ['Andi', 'Budi'],
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'selected_seats' => ['CC', 'BS'],
            'contact_number' => '+6281234567890',
        ]);

        $text = $service->buildCustomerReview($booking);

        $this->assertStringContainsString('Tanggal keberangkatan', $text);
        $this->assertStringContainsString('Jam keberangkatan', $text);
        $this->assertStringContainsString('Jumlah penumpang', $text);
        $this->assertStringContainsString('Seat terpilih', $text);
        $this->assertStringContainsString('Titik jemput', $text);
        $this->assertStringContainsString('Alamat jemput', $text);
        $this->assertStringContainsString('Tujuan antar', $text);
        $this->assertStringContainsString('Nama penumpang', $text);
        $this->assertStringContainsString('No HP', $text);
        $this->assertStringContainsString('Rp 300.000', $text);
        $this->assertStringContainsString('apakah data perjalanan ini sudah benar', mb_strtolower($text, 'UTF-8'));
    }

    public function test_it_builds_admin_review_and_marks_manual_confirmation_for_six_passengers(): void
    {
        $service = app(BookingReviewFormatterService::class);
        $booking = BookingRequest::make([
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Veteran No 2',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Rina',
            'passenger_names' => ['Rina', 'Dodi', 'Salsa', 'Yudi', 'Fikri', 'Lina'],
            'passenger_count' => 6,
            'departure_date' => '2026-03-28',
            'departure_time' => '10:00',
            'selected_seats' => ['CC', 'BS', 'Tengah', 'Belakang Kiri', 'Belakang Kanan', 'Belakang Sekali'],
            'contact_number' => '+6281111111111',
            'price_estimate' => 900000,
        ]);

        $text = $service->buildAdminReview($booking, '+6281234567890');

        $this->assertStringContainsString('Forward booking baru JET dari WhatsApp AI.', $text);
        $this->assertStringContainsString('Status tindak lanjut : Pending admin', $text);
        $this->assertStringContainsString('No customer', $text);
        $this->assertStringContainsString('Jumlah penumpang     : 6 orang', $text);
        $this->assertStringContainsString('Total ongkos         : Rp 900.000', $text);
        $this->assertStringContainsString('Perlu konfirmasi manual admin untuk 6 penumpang', $text);
    }
}
