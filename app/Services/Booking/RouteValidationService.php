<?php

namespace App\Services\Booking;

class RouteValidationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function locations(): array
    {
        /** @var array<int, array<string, mixed>> $locations */
        $locations = config('chatbot.jet.locations', []);

        return $locations;
    }

    /**
     * @return array<int, string>
     */
    public function menuLocations(): array
    {
        return array_values(array_map(
            fn (array $location) => (string) $location['label'],
            array_filter($this->locations(), fn (array $location): bool => (bool) ($location['menu'] ?? true)),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function allKnownLocations(): array
    {
        $labels = array_map(
            fn (array $location) => (string) $location['label'],
            $this->locations(),
        );

        return array_values(array_unique($labels));
    }

    public function isSupported(?string $pickup, ?string $destination): bool
    {
        return $this->resolveFareAmount($pickup, $destination) !== null;
    }

    public function resolveFareAmount(?string $pickup, ?string $destination): ?int
    {
        $origin = $this->knownLocation($pickup);
        $target = $this->knownLocation($destination);

        if ($origin === null || $target === null) {
            return null;
        }

        foreach ($this->fareRules() as $rule) {
            $a = array_map([$this, 'knownLocation'], $rule['a'] ?? []);
            $b = array_map([$this, 'knownLocation'], $rule['b'] ?? []);
            $amount = $rule['amount'] ?? null;

            if (! is_int($amount)) {
                continue;
            }

            $forward = in_array($origin, $a, true) && in_array($target, $b, true);
            $reverse = ($rule['bidirectional'] ?? false) === true
                && in_array($origin, $b, true)
                && in_array($target, $a, true);

            if ($forward || $reverse) {
                return $amount;
            }
        }

        return null;
    }

    public function normalizeLocation(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->knownLocation($value)
            ?? mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
    }

    public function knownLocation(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $lookup = $this->locationAliasMap();

        return $lookup[$this->normalizeLookupKey($value)] ?? null;
    }

    public function menuLocationByOrder(int $order): ?string
    {
        foreach ($this->locations() as $location) {
            if ((int) ($location['order'] ?? 0) === $order && (bool) ($location['menu'] ?? true)) {
                return (string) $location['label'];
            }
        }

        return null;
    }

    public function findLocationInText(string $text): ?string
    {
        $normalizedText = $this->normalizeLookupKey($text);

        if ($normalizedText === '') {
            return null;
        }

        $direct = $this->locationAliasMap()[$normalizedText] ?? null;

        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->locationAliasMap() as $alias => $label) {
            if (str_contains(' ' . $normalizedText . ' ', ' ' . $alias . ' ')) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function supportedPickups(): array
    {
        $locations = [];

        foreach ($this->fareRules() as $rule) {
            foreach (array_merge($rule['a'] ?? [], $rule['b'] ?? []) as $point) {
                $known = $this->knownLocation((string) $point);

                if ($known !== null) {
                    $locations[] = $known;
                }
            }
        }

        return array_values(array_unique($locations));
    }

    /**
     * @return array<int, string>
     */
    public function supportedDestinations(?string $pickup): array
    {
        $origin = $this->knownLocation($pickup);

        if ($origin === null) {
            return [];
        }

        $destinations = [];

        foreach ($this->fareRules() as $rule) {
            $a = array_filter(array_map([$this, 'knownLocation'], $rule['a'] ?? []));
            $b = array_filter(array_map([$this, 'knownLocation'], $rule['b'] ?? []));

            if (in_array($origin, $a, true)) {
                $destinations = array_merge($destinations, $b);
            }

            if (($rule['bidirectional'] ?? false) === true && in_array($origin, $b, true)) {
                $destinations = array_merge($destinations, $a);
            }
        }

        return array_values(array_unique($destinations));
    }

    /**
     * @return array<int, string>
     */
    public function supportedPickupsForDestination(?string $destination): array
    {
        $target = $this->knownLocation($destination);

        if ($target === null) {
            return [];
        }

        $pickups = [];

        foreach ($this->fareRules() as $rule) {
            $a = array_filter(array_map([$this, 'knownLocation'], $rule['a'] ?? []));
            $b = array_filter(array_map([$this, 'knownLocation'], $rule['b'] ?? []));

            if (in_array($target, $b, true)) {
                $pickups = array_merge($pickups, $a);
            }

            if (($rule['bidirectional'] ?? false) === true && in_array($target, $a, true)) {
                $pickups = array_merge($pickups, $b);
            }
        }

        return array_values(array_unique($pickups));
    }

    public function isKnownLocation(?string $value): bool
    {
        return $this->knownLocation($value) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fareRules(): array
    {
        /** @var array<int, array<string, mixed>> $rules */
        $rules = config('chatbot.jet.fare_rules', []);

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function locationAliasMap(): array
    {
        $map = [];

        foreach ($this->locations() as $location) {
            $label = (string) ($location['label'] ?? '');

            if ($label === '') {
                continue;
            }

            $aliases = array_merge([$label], $location['aliases'] ?? []);

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                $map[$this->normalizeLookupKey($alias)] = $label;
            }
        }

        return $map;
    }

    private function normalizeLookupKey(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace(['’', "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
