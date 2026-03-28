<?php

namespace App\Data\AI;

final readonly class LlmUnderstandingEntities
{
    public function __construct(
        public ?string $origin,
        public ?string $destination,
        public ?string $travelDate,
        public ?string $departureTime,
        public ?int $passengerCount,
        public ?string $passengerName,
        public ?string $seatNumber,
        public ?string $paymentMethod,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            origin: null,
            destination: null,
            travelDate: null,
            departureTime: null,
            passengerCount: null,
            passengerName: null,
            seatNumber: null,
            paymentMethod: null,
        );
    }

    /**
     * @return array{
     *     origin: string|null,
     *     destination: string|null,
     *     travel_date: string|null,
     *     departure_time: string|null,
     *     passenger_count: int|null,
     *     passenger_name: string|null,
     *     seat_number: string|null,
     *     payment_method: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin,
            'destination' => $this->destination,
            'travel_date' => $this->travelDate,
            'departure_time' => $this->departureTime,
            'passenger_count' => $this->passengerCount,
            'passenger_name' => $this->passengerName,
            'seat_number' => $this->seatNumber,
            'payment_method' => $this->paymentMethod,
        ];
    }
}
