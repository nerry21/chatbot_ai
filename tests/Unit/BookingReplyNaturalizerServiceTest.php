<?php

namespace Tests\Unit;

use App\Models\BookingRequest;
use App\Services\Booking\BookingReplyNaturalizerService;
use Tests\TestCase;

class BookingReplyNaturalizerServiceTest extends TestCase
{
    public function test_it_asks_passenger_count_in_a_brief_natural_tone(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->askPassengerCount();

        $this->assertSame(
            'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya?',
            $text,
        );
    }

    public function test_it_asks_departure_date_and_time_in_one_natural_prompt(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->askTravelDate();

        $this->assertStringContainsString('tanggal berapa dan jam berapa', mb_strtolower($text, 'UTF-8'));
    }

    public function test_it_formats_available_seats_for_the_selected_time(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->availableSeatsLine('08:00', ['CC', 'BS', 'Belakang Sekali']);

        $this->assertStringContainsString('08:00 WIB', $text);
        $this->assertStringContainsString('CC, BS, dan Belakang Sekali', $text);
    }

    public function test_it_formats_seat_shortage_reply_with_alternative_times(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->seatCapacityInsufficient('08:00', 3, ['CC'], [
            ['label' => 'Pagi (10.00 WIB)', 'time' => '10:00'],
            ['label' => 'Siang (14.00 WIB)', 'time' => '14:00'],
        ]);

        $this->assertStringContainsString('08:00 WIB', $text);
        $this->assertStringContainsString('belum cukup untuk 3 penumpang', mb_strtolower($text, 'UTF-8'));
        $this->assertStringContainsString('Pagi (10.00 WIB)', $text);
    }

    public function test_it_returns_a_short_acknowledgement_for_pending_passenger_count(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->shortAcknowledgement(expectedInput: 'passenger_count');

        $this->assertStringContainsString('jumlah penumpangnya', mb_strtolower($text, 'UTF-8'));
        $this->assertStringNotContainsString('untuk keberangkatan ini ada berapa orang penumpangnya', mb_strtolower($text, 'UTF-8'));
    }

    public function test_it_builds_review_summary_with_booking_fields_required_by_jet(): void
    {
        $service = app(BookingReplyNaturalizerService::class);
        $booking = BookingRequest::make([
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Andi',
            'passenger_names' => ['Andi', 'Budi'],
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'selected_seats' => ['CC', 'BS'],
            'contact_number' => '+6281234567890',
            'price_estimate' => 300000,
        ]);

        $text = $service->reviewSummary($booking);

        $this->assertStringContainsString('Tanggal keberangkatan', $text);
        $this->assertStringContainsString('Seat terpilih', $text);
        $this->assertStringContainsString('Titik jemput', $text);
        $this->assertStringContainsString('Alamat jemput', $text);
        $this->assertStringContainsString('Tujuan antar', $text);
        $this->assertStringContainsString('No HP', $text);
        $this->assertStringContainsString('Rp 300.000', $text);
        $this->assertStringContainsString('apakah data perjalanan ini sudah benar', mb_strtolower($text, 'UTF-8'));
    }

    public function test_it_returns_the_expected_admin_fallback_message(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $this->assertSame(
            'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Izin kami konsultasikan dahulu ya.',
            $service->fallbackQuestionToAdmin(),
        );
    }

    public function test_it_returns_unsupported_route_reply_with_suggestions(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->unsupportedRouteReply(
            'Panam',
            'Pekanbaru',
            ['Pasir Pengaraian', 'Bangkinang'],
            'pickup_location',
        );

        $this->assertStringContainsString('Panam ke Pekanbaru', $text);
        $this->assertStringContainsString('Pilihan lokasi jemput yang tersedia', $text);
        $this->assertStringContainsString('Pasir Pengaraian', $text);
    }
}
