<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Services\Chatbot\ConversationStateService;

class BookingConversationStateService
{
    public const EXPECTED_INPUT_KEY = 'booking_expected_input';

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'greeting_detected'       => false,
            'salam_type'              => null,
            'time_greeting'           => null,
            'booking_intent_status'   => 'idle',
            'passenger_count'         => null,
            'travel_date'             => null,
            'travel_time'             => null,
            'seat_choices_available'  => [],
            'selected_seats'          => [],
            'pickup_point'            => null,
            'pickup_full_address'     => null,
            'destination_point'       => null,
            'passenger_names'         => [],
            'contact_number'          => null,
            'contact_same_as_sender'  => null,
            'fare_amount'             => null,
            'route_status'            => null,
            'admin_takeover'          => false,
            'needs_human_escalation'  => false,
            'review_sent'             => false,
            'booking_confirmed'       => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function load(Conversation $conversation): array
    {
        $slots = $this->defaults();

        foreach (array_keys($slots) as $key) {
            $value = $this->stateService->get($conversation, $key, $slots[$key]);
            $slots[$key] = $value ?? $slots[$key];
        }

        $slots['admin_takeover'] = $conversation->isAdminTakeover();
        $slots['needs_human_escalation'] = (bool) ($slots['needs_human_escalation'] || $conversation->needs_human);

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function putMany(Conversation $conversation, array $updates): array
    {
        $current = $this->load($conversation);
        $changes = [];

        foreach ($updates as $key => $value) {
            if (! array_key_exists($key, $this->defaults())) {
                continue;
            }

            if ($this->sameValue($current[$key] ?? null, $value)) {
                continue;
            }

            $this->stateService->put($conversation, $key, $value);
            $changes[$key] = [
                'old' => $current[$key] ?? null,
                'new' => $value,
            ];
        }

        return $changes;
    }

    public function expectedInput(Conversation $conversation): ?string
    {
        $value = $this->stateService->get($conversation, self::EXPECTED_INPUT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setExpectedInput(Conversation $conversation, ?string $expectedInput): void
    {
        if ($expectedInput === null || trim($expectedInput) === '') {
            $this->stateService->forget($conversation, self::EXPECTED_INPUT_KEY);

            return;
        }

        $this->stateService->put($conversation, self::EXPECTED_INPUT_KEY, trim($expectedInput));
    }

    public function syncBooking(BookingRequest $booking, array $slots, string $senderPhone): BookingRequest
    {
        $contactSame = $slots['contact_same_as_sender'];
        $contactNumber = $contactSame === true
            ? $senderPhone
            : ($slots['contact_number'] ?: null);

        $booking->pickup_location = $slots['pickup_point'];
        $booking->pickup_full_address = $slots['pickup_full_address'];
        $booking->destination = $slots['destination_point'];
        $booking->departure_date = $slots['travel_date'];
        $booking->departure_time = $slots['travel_time'];
        $booking->passenger_count = $slots['passenger_count'];
        $booking->selected_seats = $slots['selected_seats'] ?: null;
        $booking->passenger_names = $slots['passenger_names'] ?: null;
        $booking->passenger_name = $this->primaryPassengerName($slots['passenger_names'] ?? []);
        $booking->contact_number = $contactNumber;
        $booking->contact_same_as_sender = $contactSame;
        $booking->price_estimate = $slots['fare_amount'];
        $booking->save();

        return $booking->fresh();
    }

    /**
     * @param  array<int, string>|null  $names
     */
    public function primaryPassengerName(?array $names): ?string
    {
        if (! is_array($names) || $names === []) {
            return null;
        }

        $first = trim((string) $names[0]);

        return $first !== '' ? $first : null;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }
}
