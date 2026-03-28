<?php

namespace App\Services\Booking\Guardrails;

use App\Models\BookingRequest;

class BookingStateGuardService
{
    /**
     * @param  array<string, mixed>  $slots
     * @return array{
     *     has_active_booking_context: bool,
     *     is_waiting_admin_takeover: bool,
     *     is_review_pending: bool,
     *     is_completed: bool,
     *     booking_state: string,
     *     expected_input: string|null,
     *     has_booking_record: bool
     * }
     */
    public function snapshot(?BookingRequest $booking, array $slots): array
    {
        return [
            'has_active_booking_context' => $this->hasActiveBookingContext($booking, $slots),
            'is_waiting_admin_takeover' => (bool) ($slots['waiting_admin_takeover'] ?? false),
            'is_review_pending' => (bool) ($slots['review_sent'] ?? false) && ! (bool) ($slots['booking_confirmed'] ?? false),
            'is_completed' => (bool) ($slots['booking_confirmed'] ?? false),
            'booking_state' => (string) ($slots['booking_intent_status'] ?? 'idle'),
            'expected_input' => is_string($slots['booking_expected_input'] ?? null)
                ? $slots['booking_expected_input']
                : null,
            'has_booking_record' => $booking !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    public function hasActiveBookingContext(?BookingRequest $booking, array $slots): bool
    {
        if ($booking !== null) {
            return true;
        }

        foreach ([
            'pickup_location',
            'pickup_full_address',
            'destination',
            'destination_full_address',
            'passenger_name',
            'passenger_names',
            'passenger_count',
            'travel_date',
            'travel_time',
            'selected_seats',
            'contact_number',
        ] as $key) {
            $value = $slots[$key] ?? null;

            if (is_array($value) && $value !== []) {
                return true;
            }

            if (! is_array($value) && $value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }
}
