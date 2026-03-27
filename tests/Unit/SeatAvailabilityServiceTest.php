<?php

namespace Tests\Unit;

use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\BookingSeatReservation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\SeatAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $customerSequence = 0;

    public function test_it_builds_availability_snapshot_with_alternative_slots_for_the_draft(): void
    {
        $service = app(SeatAvailabilityService::class);
        $booking = $this->makeBooking([
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'trip_key' => 'pasir-pengaraian__pekanbaru',
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'passenger_count' => 2,
        ]);

        $blockingBooking = $this->makeBooking([
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'passenger_count' => 5,
            'booking_status' => BookingStatus::Draft,
        ]);

        foreach (['CC', 'BS', 'Tengah', 'Belakang Kiri', 'Belakang Kanan'] as $seatCode) {
            BookingSeatReservation::create([
                'booking_request_id' => $blockingBooking->id,
                'departure_date' => '2026-03-28',
                'departure_time' => '08:00',
                'trip_key' => SeatAvailabilityService::GLOBAL_TRIP_KEY,
                'seat_code' => $seatCode,
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        $snapshot = $service->availabilitySnapshot($booking);

        $this->assertSame('pasir pengaraian__pekanbaru', $snapshot['trip_key']);
        $this->assertSame(1, $snapshot['available_count']);
        $this->assertFalse($snapshot['has_capacity']);
        $this->assertSame(['Belakang Sekali'], $snapshot['available_seats']);
        $this->assertNotEmpty($snapshot['alternative_slots']);
        $this->assertContains('10:00', array_column($snapshot['alternative_slots'], 'time'));
    }

    public function test_it_syncs_reserved_seats_from_global_scope_to_route_trip_key(): void
    {
        $service = app(SeatAvailabilityService::class);
        $booking = $this->makeBooking([
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'passenger_count' => 2,
            'selected_seats' => ['CC', 'BS'],
            'booking_status' => BookingStatus::Draft,
        ]);

        $service->reserveSeats($booking, ['CC', 'BS']);

        $this->assertSame(
            [SeatAvailabilityService::GLOBAL_TRIP_KEY, SeatAvailabilityService::GLOBAL_TRIP_KEY],
            $booking->seatReservations()->orderBy('seat_code')->pluck('trip_key')->all(),
        );

        $booking->pickup_location = 'Pasir Pengaraian';
        $booking->destination = 'Pekanbaru';
        $booking->trip_key = 'pasir pengaraian__pekanbaru';
        $booking->save();

        $service->syncDraftReservationContext($booking->fresh());

        $this->assertSame(
            ['pasir pengaraian__pekanbaru', 'pasir pengaraian__pekanbaru'],
            $booking->fresh()->seatReservations()->orderBy('seat_code')->pluck('trip_key')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBooking(array $attributes = []): BookingRequest
    {
        self::$customerSequence++;

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567'.str_pad((string) self::$customerSequence, 4, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return BookingRequest::create(array_merge([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'booking_status' => BookingStatus::Draft,
        ], $attributes));
    }
}
