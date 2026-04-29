<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;
use App\Models\Customer;
use App\Services\CRM\CustomerJourneyService;
use App\Services\CRM\CustomerPreferenceUpdaterService;
use Illuminate\Support\Facades\Log;

class BookingConfirmationService
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly BookingReviewFormatterService $reviewFormatter,
        private readonly CustomerPreferenceUpdaterService $preferenceUpdater,
        private readonly CustomerJourneyService $journeyService,
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

        try {
            $this->preferenceUpdater->updateFromBooking($booking);
        } catch (\Throwable $e) {
            Log::warning('[BookingConfirmation] Preference update failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $customer = $booking->customer ?? Customer::find($booking->customer_id);
            if ($customer !== null && $customer->first_booking_at === null) {
                $customer->forceFill(['first_booking_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('[BookingConfirmation] First booking timestamp failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $customer = $booking->customer ?? Customer::find($booking->customer_id);
            if ($customer !== null) {
                $this->journeyService->syncBookingMilestones($customer);
            }
        } catch (\Throwable $e) {
            Log::warning('[BookingConfirmation] Milestone sync failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
