<?php

namespace App\Services\Booking;

class PricingService
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
    ) {
    }

    /**
     * Calculate the total price estimate for a trip.
     *
     * Rules (Tahap 4 — static config, no external API):
     *   1. If either location is missing  → return null.
     *   2. If route is not in the table   → return null.
     *   3. Total = base_price_per_seat × passenger_count × multiplier.
     *   4. multiplier is configurable via BOOKING_PASSENGER_MULTIPLIER (default 1.0).
     *
     * Returns null when the price cannot be determined (missing data or unknown route).
     */
    public function estimate(
        ?string $pickup,
        ?string $destination,
        ?int $passengerCount = 1,
    ): ?float {
        $total = $this->fareCalculator->calculate($pickup, $destination, $passengerCount);

        return $total !== null ? (float) $total : null;
    }

    /**
     * Format a price as a human-readable Indonesian Rupiah string.
     * E.g. 300000.0 → "Rp 300.000"
     */
    public function formatRupiah(?float $amount): string
    {
        return $this->fareCalculator->formatRupiah($amount);
    }
}
