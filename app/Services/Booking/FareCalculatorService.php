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
        return $this->routeValidator->resolveFareAmount($pickup, $destination);
    }

    public function calculate(?string $pickup, ?string $destination, ?int $passengerCount = 1): ?int
    {
        $unitFare = $this->unitFare($pickup, $destination);

        if ($unitFare === null) {
            return null;
        }

        $count = max(1, (int) ($passengerCount ?? 1));

        return $unitFare * $count;
    }

    public function formatRupiah(int|float|null $amount): string
    {
        if ($amount === null) {
            return 'Belum tersedia';
        }

        return 'Rp ' . number_format((int) round($amount), 0, ',', '.');
    }
}
