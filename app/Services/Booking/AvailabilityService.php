<?php

namespace App\Services\Booking;

/**
 * Availability stub — Tahap 4.
 *
 * This service is intentionally minimal. Its interface is defined here
 * so that BookingAssistantService can depend on it via DI, and a real
 * implementation (API call, seat inventory DB query, etc.) can be swapped
 * in during Tahap 5+ without changing any call sites.
 */
class AvailabilityService
{
    /**
     * Check whether a trip slot can be scheduled with the given parameters.
     *
     * @param  array<string, mixed>  $data  May contain: pickup_location, destination,
     *                                       departure_date, departure_time, passenger_count.
     */
    public function canSchedule(array $data): bool
    {
        // Tahap 4 stub: always available.
        // Replace with real inventory / schedule check in Tahap 5.
        return true;
    }

    /**
     * Return a human-readable explanation when availability is denied.
     * Returns null when the slot is available (canSchedule returns true).
     *
     * @param  array<string, mixed>  $data
     */
    public function explainUnavailability(array $data): ?string
    {
        // Stub: no explanation needed while canSchedule always returns true.
        return null;
    }
}
