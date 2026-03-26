<?php

namespace App\Services\Booking;

class RouteValidationService
{
    /**
     * @return array<string, array<string, int>>
     */
    private function routeTable(): array
    {
        return config('chatbot.booking.routes', []);
    }

    /**
     * Check whether the pickup → destination combination is in the supported
     * route table. Both values are normalized before comparison.
     */
    public function isSupported(?string $pickup, ?string $destination): bool
    {
        if ($pickup === null || $destination === null) {
            return false;
        }

        $normalizedPickup = $this->normalizeForLookup($pickup);
        $normalizedDest   = $this->normalizeForLookup($destination);

        return isset($this->routeTable()[$normalizedPickup][$normalizedDest]);
    }

    /**
     * Normalize a location string for display purposes (Title Case).
     * Returns null when the input is null or blank.
     *
     * Examples:
     *   'UJUNG BATU'        → 'Ujung Batu'
     *   '  pekanbaru  '     → 'Pekanbaru'
     *   'pasir pengaraian'  → 'Pasir Pengaraian'
     */
    public function normalizeLocation(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Return all supported pickup cities as title-cased strings.
     *
     * @return array<int, string>
     */
    public function supportedPickups(): array
    {
        return array_map(
            fn (string $key) => $this->normalizeLocation($key),
            array_keys($this->routeTable()),
        );
    }

    /**
     * Return all supported destinations for a given pickup city.
     *
     * @return array<int, string>
     */
    public function supportedDestinations(?string $pickup): array
    {
        if ($pickup === null) {
            return [];
        }

        $routes = $this->routeTable()[$this->normalizeForLookup($pickup)] ?? [];

        return array_map(
            fn (string $key) => $this->normalizeLocation($key),
            array_keys($routes),
        );
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function normalizeForLookup(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
