<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;

class BookingConfirmationService
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly BookingReviewFormatterService $reviewFormatter,
    ) {}

    public function buildSummary(BookingRequest $booking): string
    {
        return $this->reviewFormatter->buildCustomerReview($booking);
    }

    public function buildAdminSummary(BookingRequest $booking, string $customerPhone): string
    {
        return $this->reviewFormatter->buildAdminReview($booking, $customerPhone);
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
        $this->seatAvailability->confirmSeats($booking);
    }
}
