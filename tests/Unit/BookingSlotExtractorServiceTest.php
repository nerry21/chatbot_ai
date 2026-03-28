<?php

namespace Tests\Unit;

use App\Services\Booking\BookingSlotExtractorService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingSlotExtractorServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_extracts_name_count_and_ambiguous_morning_time_from_free_text(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'nama penumpangnya Nerry, jumlahnya 2, besok pagi',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Nerry', $result['updates']['passenger_name']);
        $this->assertSame(2, $result['updates']['passenger_count']);
        $this->assertSame('2026-03-28', $result['updates']['travel_date']);
        $this->assertArrayNotHasKey('travel_time', $result['updates']);
        $this->assertTrue($result['signals']['time_ambiguous']);
    }

    public function test_it_extracts_unknown_pickup_location_for_route_validation(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'titik jemput di panam',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'pickup_location',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Panam', $result['updates']['pickup_location']);
    }

    public function test_it_extracts_multiple_slots_from_one_free_form_message(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'jemput di kabun, tujuan pekanbaru, nama nerry, 2 orang',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Kabun', $result['updates']['pickup_location']);
        $this->assertSame('Pekanbaru', $result['updates']['destination']);
        $this->assertSame('Nerry', $result['updates']['passenger_name']);
        $this->assertSame(2, $result['updates']['passenger_count']);
    }

    public function test_it_normalizes_name_variants_without_capturing_pronouns(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'nama saya nerry',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Nerry', $result['updates']['passenger_name']);
    }

    public function test_it_does_not_treat_date_numbers_as_passenger_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: '28 maret jam 8',
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertSame('2026-03-28', $result['updates']['travel_date']);
        $this->assertSame('08:00', $result['updates']['travel_time']);
        $this->assertArrayNotHasKey('passenger_count', $result['updates']);
    }

    public function test_it_detects_islamic_greeting_variants_without_treating_specific_question_as_greeting_only(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: "Assalamu'alaikum wr wb jadwal hari ini ada?",
            currentSlots: [],
            entityResult: [],
            expectedInput: null,
            senderPhone: '+6281234567890',
        );

        $this->assertTrue($result['signals']['greeting_detected']);
        $this->assertSame('islamic', $result['signals']['salam_type']);
        $this->assertFalse($result['signals']['greeting_only']);
        $this->assertTrue($result['signals']['today_schedule_keyword']);
    }

    public function test_it_uses_sender_phone_when_customer_answers_sama_for_contact_confirmation(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'sama',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'contact_number',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('+6281234567890', $result['updates']['contact_number']);
        $this->assertTrue($result['updates']['contact_same_as_sender']);
    }

    public function test_it_reads_departure_time_from_numbered_fallback_menu(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: '2',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'travel_time',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('08:00', $result['updates']['travel_time']);
    }

    public function test_it_reads_pickup_location_from_numbered_fallback_menu(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: '7',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'pickup_location',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Pasir Pengaraian', $result['updates']['pickup_location']);
    }

    public function test_it_only_maps_pickup_address_when_pickup_address_is_expected(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'Alamat jemput: Jl Sudirman No 1',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'pickup_full_address',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Jl Sudirman No 1', $result['updates']['pickup_full_address']);
        $this->assertArrayNotHasKey('destination_full_address', $result['updates']);
    }

    public function test_it_only_maps_destination_address_when_destination_address_is_expected(): void
    {
        $extractor = app(BookingSlotExtractorService::class);

        $result = $extractor->extract(
            messageText: 'Alamat tujuan antar: Jl Tuanku Tambusai No 5',
            currentSlots: [],
            entityResult: [],
            expectedInput: 'destination_full_address',
            senderPhone: '+6281234567890',
        );

        $this->assertSame('Jl Tuanku Tambusai No 5', $result['updates']['destination_full_address']);
        $this->assertArrayNotHasKey('pickup_full_address', $result['updates']);
    }
}
