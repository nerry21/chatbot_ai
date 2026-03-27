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
}
