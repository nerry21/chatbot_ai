<?php

namespace App\Services\Booking;

class FareCalculatorService
{
    public function __construct(
        private readonly RouteValidationService $routeValidator,
    ) {
    }

    public function unitFare(?string $pickup, ?string $destination): ?int
    {
        $route = $this->normalizedRoute($pickup, $destination);

        if ($route === null) {
            return null;
        }

        return $this->routeValidator->resolveFareAmount($route['pickup'], $route['destination']);
    }

    /**
     * @return array{pickup: string, destination: string, trip_key: string}|null
     */
    public function normalizedRoute(?string $pickup, ?string $destination): ?array
    {
        $normalizedPickup = $this->routeValidator->knownLocation($pickup)
            ?? $this->routeValidator->normalizeLocation($pickup);
        $normalizedDestination = $this->routeValidator->knownLocation($destination)
            ?? $this->routeValidator->normalizeLocation($destination);

        if ($normalizedPickup === null || $normalizedDestination === null) {
            return null;
        }

        $tripKey = $this->routeValidator->tripKey($normalizedPickup, $normalizedDestination);

        if ($tripKey === null) {
            return null;
        }

        return [
            'pickup' => $normalizedPickup,
            'destination' => $normalizedDestination,
            'trip_key' => $tripKey,
        ];
    }

    /**
     * @return array{pickup: string, destination: string, trip_key: string, unit_fare: int, passenger_count: int, total_fare: int}|null
     */
    public function fareBreakdown(?string $pickup, ?string $destination, ?int $passengerCount = 1): ?array
    {
        $route = $this->normalizedRoute($pickup, $destination);

        if ($route === null) {
            return null;
        }

        $unitFare = $this->routeValidator->resolveFareAmount($route['pickup'], $route['destination']);

        if ($unitFare === null) {
            return null;
        }

        $count = max(1, (int) ($passengerCount ?? 1));

        return [
            'pickup' => $route['pickup'],
            'destination' => $route['destination'],
            'trip_key' => $route['trip_key'],
            'unit_fare' => $unitFare,
            'passenger_count' => $count,
            'total_fare' => $unitFare * $count,
        ];
    }

    public function calculate(?string $pickup, ?string $destination, ?int $passengerCount = 1): ?int
    {
        $breakdown = $this->fareBreakdown($pickup, $destination, $passengerCount);

        return $breakdown['total_fare'] ?? null;
    }

    public function needsAdminEscalation(?string $pickup, ?string $destination): bool
    {
        return $this->unitFare($pickup, $destination) === null;
    }

    public function formatRupiah(int|float|null $amount): string
    {
        if ($amount === null) {
            return '-';
        }

        return 'Rp ' . number_format((int) round($amount), 0, ',', '.');
    }
}
