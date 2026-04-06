<?php

namespace App\Services\Chatbot;

use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;

/**
 * TravelFareService
 *
 * Thin facade over RouteValidationService + FareCalculatorService that exposes
 * a simple, read-only API for tariff and location lookups.
 *
 * Config source: chatbot.jet.locations, chatbot.jet.fare_rules
 *
 * Do NOT duplicate the location alias / fare-rule resolution logic here.
 * Both already live in RouteValidationService and are governed by the same
 * config.  Keeping them in sync is the reason for this delegation pattern.
 */
class TravelFareService
{
    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly FareCalculatorService $fareCalculator,
    ) {}

    /**
     * Find the unit fare (per seat) for origin → destination.
     * Returns null when the route is not supported.
     *
     * @return array{
     *     origin: string,
     *     destination: string,
     *     unit_fare: int,
     *     formatted_price: string,
     * }|null
     */
    public function findFare(string $origin, string $destination): ?array
    {
        $normalizedOrigin = $this->routeValidator->knownLocation($origin);
        $normalizedDestination = $this->routeValidator->knownLocation($destination);

        if ($normalizedOrigin === null || $normalizedDestination === null) {
            return null;
        }

        $amount = $this->routeValidator->resolveFareAmount($normalizedOrigin, $normalizedDestination);

        if ($amount === null) {
            return null;
        }

        return [
            'origin' => $normalizedOrigin,
            'destination' => $normalizedDestination,
            'unit_fare' => $amount,
            'formatted_price' => $this->fareCalculator->formatRupiah($amount),
        ];
    }

    /**
     * Return a human-readable fare sentence, or null when route is unsupported.
     *
     * Example: "Untuk rute Pasir Pengaraian ke Pekanbaru, ongkosnya Rp 150.000."
     */
    public function getFareText(string $origin, string $destination): ?string
    {
        $fare = $this->findFare($origin, $destination);

        if ($fare === null) {
            return null;
        }

        return sprintf(
            'Untuk rute %s ke %s, ongkosnya %s.',
            $fare['origin'],
            $fare['destination'],
            $fare['formatted_price'],
        );
    }

    /**
     * All location labels known to the system (label values from chatbot.jet.locations).
     *
     * @return array<int, string>
     */
    public function getAllLocations(): array
    {
        return $this->routeValidator->allKnownLocations();
    }

    /**
     * Location labels that appear in the interactive menu (menu = true).
     *
     * @return array<int, string>
     */
    public function getMenuLocations(): array
    {
        return $this->routeValidator->menuLocations();
    }

    /**
     * Whether a location string resolves to a known label.
     */
    public function isValidLocation(string $location): bool
    {
        return $this->routeValidator->isKnownLocation($location);
    }

    /**
     * Normalize a free-form location string to its canonical label,
     * or return null when not recognized.
     */
    public function normalizeLocation(string $location): ?string
    {
        return $this->routeValidator->knownLocation($location);
    }

    /**
     * Return a numbered list of all locations for plain-text display.
     *
     * Example:
     *   1. SKPD
     *   2. Simpang D
     *   ...
     */
    public function buildLocationListText(): string
    {
        $locations = $this->getAllLocations();

        if ($locations === []) {
            return '';
        }

        $lines = [];

        foreach ($locations as $index => $label) {
            $lines[] = ($index + 1).'. '.$label;
        }

        return implode("\n", $lines);
    }

    /**
     * Format an integer amount as Rupiah, e.g. "Rp 150.000".
     */
    public function formatRupiah(int $amount): string
    {
        return $this->fareCalculator->formatRupiah($amount);
    }
}
