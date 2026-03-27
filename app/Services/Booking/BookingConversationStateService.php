<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Services\Chatbot\ConversationStateService;
use App\Support\WaLog;

class BookingConversationStateService
{
    public const EXPECTED_INPUT_KEY = 'booking_expected_input';

    /**
     * Minimal booking slots that must survive across messages.
     *
     * @var array<int, string>
     */
    private const TRACKED_SLOT_KEYS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
        'travel_date',
        'travel_time',
        'payment_method',
    ];

    /**
     * Compact state snapshot kept in logs for easier debugging.
     *
     * @var array<int, string>
     */
    private const SNAPSHOT_KEYS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
        'travel_date',
        'travel_time',
        'payment_method',
        'booking_intent_status',
        'route_status',
        'route_issue',
        'fare_amount',
        'review_sent',
        'booking_confirmed',
        'needs_human_escalation',
        'admin_takeover',
    ];

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'greeting_detected' => false,
            'salam_type' => null,
            'time_greeting' => null,
            'booking_intent_status' => BookingFlowState::Idle->value,
            'pickup_location' => null,
            'destination' => null,
            'passenger_name' => null,
            'passenger_count' => null,
            'travel_date' => null,
            'travel_time' => null,
            'payment_method' => null,
            'route_status' => null,
            'route_issue' => null,
            'fare_amount' => null,
            'admin_takeover' => false,
            'needs_human_escalation' => false,
            'review_sent' => false,
            'booking_confirmed' => false,

            // Legacy mirrors retained for backward compatibility with older states.
            'pickup_point' => null,
            'destination_point' => null,
            'pickup_full_address' => null,
            'passenger_names' => [],
            'selected_seats' => [],
            'seat_choices_available' => [],
            'contact_number' => null,
            'contact_same_as_sender' => null,
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

        $slots['pickup_location'] ??= $slots['pickup_point'];
        $slots['destination'] ??= $slots['destination_point'];

        if ($slots['passenger_name'] === null && is_array($slots['passenger_names']) && $slots['passenger_names'] !== []) {
            $slots['passenger_name'] = $this->primaryPassengerName($slots['passenger_names']);
        }

        $slots['booking_intent_status'] = $this->normalizeFlowState(
            $slots['booking_intent_status'] ?? null,
            $slots,
        );

        $slots['admin_takeover'] = $conversation->isAdminTakeover();
        $slots['needs_human_escalation'] = (bool) ($slots['needs_human_escalation'] || $conversation->needs_human);

        return $slots;
    }

    /**
     * Hydrate missing slot memory from an existing booking draft.
     *
     * @return array<string, mixed>
     */
    public function hydrateFromBooking(Conversation $conversation, ?BookingRequest $booking): array
    {
        $slots = $this->load($conversation);

        if ($booking === null) {
            return $slots;
        }

        $updates = [];
        $bookingSlots = $this->slotsFromBooking($booking);

        foreach ($bookingSlots as $key => $value) {
            if ($this->sameValue($slots[$key] ?? null, $value)) {
                continue;
            }

            if ($this->isBlank($slots[$key] ?? null) && ! $this->isBlank($value)) {
                $updates[$key] = $value;
            }
        }

        foreach ($this->hydrationStateUpdates($booking, $slots, $bookingSlots) as $key => $value) {
            if (! $this->sameValue($slots[$key] ?? null, $value)) {
                $updates[$key] = $value;
            }
        }

        if ($updates === []) {
            return $slots;
        }

        $changes = $this->putMany($conversation, $updates, 'booking_draft_hydration');
        $hydrated = array_replace(
            $slots,
            array_map(fn (array $change) => $change['new'], $changes),
        );

        WaLog::info('[BookingState] hydrated from booking draft', [
            'conversation_id' => $conversation->id,
            'booking_id' => $booking->id,
            'changes' => $changes,
            'snapshot' => $this->snapshotFromSlots($hydrated, $this->expectedInput($conversation)),
        ]);

        return $hydrated;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function putMany(Conversation $conversation, array $updates, string $source = 'runtime'): array
    {
        $current = $this->load($conversation);
        $changes = [];
        $normalizedUpdates = $this->normalizeUpdates($current, $updates);

        foreach ($normalizedUpdates as $key => $value) {
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

        if ($changes !== []) {
            $this->logChanges($conversation, $changes, $current, $source);
        }

        return $changes;
    }

    public function normalizeFlowState(?string $state, array $slots = []): string
    {
        return match (trim((string) $state)) {
            BookingFlowState::Idle->value => BookingFlowState::Idle->value,
            BookingFlowState::CollectingRoute->value => BookingFlowState::CollectingRoute->value,
            BookingFlowState::CollectingPassenger->value => BookingFlowState::CollectingPassenger->value,
            BookingFlowState::CollectingSchedule->value => BookingFlowState::CollectingSchedule->value,
            BookingFlowState::RouteUnavailable->value => BookingFlowState::RouteUnavailable->value,
            BookingFlowState::ReadyToConfirm->value,
            BookingStatus::AwaitingConfirmation->value => BookingFlowState::ReadyToConfirm->value,
            BookingFlowState::Confirmed->value => BookingFlowState::Confirmed->value,
            BookingFlowState::Closed->value,
            'needs_human' => BookingFlowState::Closed->value,
            'collecting' => $this->inferOpenStateFromSlots($slots),
            default => BookingFlowState::Idle->value,
        };
    }

    public function isCollectingState(?string $state): bool
    {
        return BookingFlowState::from($this->normalizeFlowState($state))->isCollecting();
    }

    public function expectedInput(Conversation $conversation): ?string
    {
        $value = $this->stateService->get($conversation, self::EXPECTED_INPUT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setExpectedInput(
        Conversation $conversation,
        ?string $expectedInput,
        string $source = 'runtime',
    ): void {
        $current = $this->expectedInput($conversation);
        $normalized = is_string($expectedInput) && trim($expectedInput) !== ''
            ? trim($expectedInput)
            : null;

        if ($current === $normalized) {
            return;
        }

        if ($normalized === null) {
            $this->stateService->forget($conversation, self::EXPECTED_INPUT_KEY);
        } else {
            $this->stateService->put($conversation, self::EXPECTED_INPUT_KEY, $normalized);
        }

        WaLog::debug('[BookingState] expected input updated', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'old' => $current,
            'new' => $normalized,
            'snapshot' => $this->snapshotFromSlots($this->load($conversation), $normalized),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function transitionFlowState(
        Conversation $conversation,
        BookingFlowState|string $state,
        ?string $expectedInput,
        string $source = 'runtime',
        array $context = [],
    ): void {
        $currentSlots = $this->load($conversation);
        $previousState = $this->normalizeFlowState((string) ($currentSlots['booking_intent_status'] ?? null), $currentSlots);
        $previousExpectedInput = $this->expectedInput($conversation);
        $normalizedExpectedInput = is_string($expectedInput) && trim($expectedInput) !== ''
            ? trim($expectedInput)
            : null;
        $nextState = $state instanceof BookingFlowState
            ? $state->value
            : $this->normalizeFlowState($state, $currentSlots);

        $this->putMany($conversation, ['booking_intent_status' => $nextState], $source);
        $this->setExpectedInput($conversation, $normalizedExpectedInput, $source);

        if ($previousState === $nextState && $previousExpectedInput === $normalizedExpectedInput) {
            return;
        }

        WaLog::info('[BookingState] flow state transitioned', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'from_state' => $previousState,
            'to_state' => $nextState,
            'from_expected_input' => $previousExpectedInput,
            'to_expected_input' => $normalizedExpectedInput,
            'context' => $context,
            'snapshot' => $this->snapshot($conversation),
        ]);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    public function syncBooking(BookingRequest $booking, array $slots, string $senderPhone): BookingRequest
    {
        $booking->pickup_location = $slots['pickup_location'];
        $booking->pickup_full_address = $slots['pickup_full_address'] ?? $booking->pickup_full_address;
        $booking->destination = $slots['destination'];
        $booking->departure_date = $slots['travel_date'];
        $booking->departure_time = $slots['travel_time'];
        $booking->passenger_count = $slots['passenger_count'];
        $booking->passenger_name = $slots['passenger_name'];
        $booking->passenger_names = $slots['passenger_name'] ? [$slots['passenger_name']] : null;
        $booking->payment_method = $slots['payment_method'];
        $booking->price_estimate = $slots['fare_amount'];
        $booking->contact_number = $slots['contact_number'] ?: ($booking->contact_number ?: ($senderPhone !== '' ? $senderPhone : null));
        $booking->contact_same_as_sender = $senderPhone !== '' && $booking->contact_number === $senderPhone;

        $dirty = $booking->getDirty();

        if ($dirty === []) {
            return $booking;
        }

        $booking->save();

        WaLog::debug('[BookingState] booking draft synced', [
            'conversation_id' => $booking->conversation_id,
            'booking_id' => $booking->id,
            'dirty_fields' => array_keys($dirty),
            'snapshot' => $this->snapshotFromSlots($slots),
        ]);

        return $booking->fresh();
    }

    /**
     * Public snapshot helper for support/debug tooling.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Conversation $conversation): array
    {
        return $this->snapshotFromSlots($this->load($conversation), $this->expectedInput($conversation));
    }

    /**
     * Split tracked slot changes into fresh captures vs overwrites.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array{
     *     created: array<string, array{old: mixed, new: mixed}>,
     *     overwritten: array<string, array{old: mixed, new: mixed}>
     * }
     */
    public function trackedSlotChanges(array $changes): array
    {
        $result = [
            'created' => [],
            'overwritten' => [],
        ];

        foreach (self::TRACKED_SLOT_KEYS as $key) {
            if (! array_key_exists($key, $changes)) {
                continue;
            }

            $bucket = $this->isBlank($changes[$key]['old'] ?? null)
                ? 'created'
                : 'overwritten';

            $result[$bucket][$key] = $changes[$key];
        }

        return $result;
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

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function normalizeUpdates(array $current, array $updates): array
    {
        if (array_key_exists('pickup_location', $updates) && ! array_key_exists('pickup_point', $updates)) {
            $updates['pickup_point'] = $updates['pickup_location'];
        }

        if (array_key_exists('destination', $updates) && ! array_key_exists('destination_point', $updates)) {
            $updates['destination_point'] = $updates['destination'];
        }

        if (array_key_exists('passenger_name', $updates) && ! array_key_exists('passenger_names', $updates)) {
            $updates['passenger_names'] = filled($updates['passenger_name'])
                ? [$updates['passenger_name']]
                : [];
        }

        if (array_key_exists('route_status', $updates) && $updates['route_status'] !== 'supported') {
            $updates['fare_amount'] = null;
        }

        if (array_key_exists('pickup_location', $updates) || array_key_exists('destination', $updates)) {
            $updates['route_issue'] = $updates['route_issue'] ?? null;
        }

        if ($this->hasTrackedSlotChange($updates)) {
            $updates['review_sent'] = $updates['review_sent'] ?? false;
            $updates['booking_confirmed'] = $updates['booking_confirmed'] ?? false;
        }

        if (array_key_exists('contact_number', $updates) && blank($updates['contact_number'])) {
            $updates['contact_number'] = $current['contact_number'] ?? null;
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function hasTrackedSlotChange(array $updates): bool
    {
        return array_intersect(array_keys($updates), self::TRACKED_SLOT_KEYS) !== [];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $current
     */
    private function logChanges(Conversation $conversation, array $changes, array $current, string $source): void
    {
        $loggableChanges = array_intersect_key($changes, array_flip(self::SNAPSHOT_KEYS));

        if ($loggableChanges === []) {
            return;
        }

        $trackedSlotChanges = $this->trackedSlotChanges($changes);

        $merged = array_replace(
            $current,
            array_map(fn (array $change) => $change['new'], $changes),
        );

        WaLog::debug('[BookingState] conversation state updated', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'changes' => $loggableChanges,
            'tracked_slot_changes' => [
                'created' => array_keys($trackedSlotChanges['created']),
                'overwritten' => $trackedSlotChanges['overwritten'],
            ],
            'snapshot' => $this->snapshotFromSlots($merged, $this->expectedInput($conversation)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function snapshotFromSlots(array $slots, ?string $expectedInput = null): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT_KEYS as $key) {
            $snapshot[$key] = $slots[$key] ?? null;
        }

        $snapshot['expected_input'] = $expectedInput;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function slotsFromBooking(BookingRequest $booking): array
    {
        return [
            'pickup_location' => $booking->pickup_location,
            'destination' => $booking->destination,
            'passenger_name' => $booking->passenger_name ?? $this->primaryPassengerName($booking->passenger_names),
            'passenger_count' => $booking->passenger_count,
            'travel_date' => $booking->departure_date?->format('Y-m-d'),
            'travel_time' => $booking->departure_time,
            'payment_method' => $booking->payment_method,
            'fare_amount' => $booking->price_estimate !== null
                ? (int) round((float) $booking->price_estimate)
                : null,
            'pickup_full_address' => $booking->pickup_full_address,
            'contact_number' => $booking->contact_number,
            'contact_same_as_sender' => $booking->contact_same_as_sender,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $bookingSlots
     * @return array<string, mixed>
     */
    private function hydrationStateUpdates(BookingRequest $booking, array $current, array $bookingSlots): array
    {
        $updates = [];
        $currentStatus = $this->normalizeFlowState((string) ($current['booking_intent_status'] ?? null), $current);

        if ($currentStatus === BookingFlowState::Closed->value) {
            return $updates;
        }

        if ($booking->booking_status === BookingStatus::AwaitingConfirmation) {
            if (! in_array($currentStatus, [BookingFlowState::ReadyToConfirm->value, BookingFlowState::Confirmed->value], true)) {
                $updates['booking_intent_status'] = BookingFlowState::ReadyToConfirm->value;
            }

            if (($current['review_sent'] ?? false) !== true) {
                $updates['review_sent'] = true;
            }

            if (($current['booking_confirmed'] ?? false) !== false) {
                $updates['booking_confirmed'] = false;
            }

            return $updates;
        }

        if (in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::Completed], true)) {
            if ($currentStatus !== BookingFlowState::Confirmed->value) {
                $updates['booking_intent_status'] = BookingFlowState::Confirmed->value;
            }

            if (($current['review_sent'] ?? false) !== true) {
                $updates['review_sent'] = true;
            }

            if (($current['booking_confirmed'] ?? false) !== true) {
                $updates['booking_confirmed'] = true;
            }

            return $updates;
        }

        if ($this->hasAnyFilledTrackedSlot($bookingSlots)) {
            $inferredState = $this->inferOpenStateFromSlots($bookingSlots);

            if ($currentStatus !== $inferredState) {
                $updates['booking_intent_status'] = $inferredState;
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function inferOpenStateFromSlots(array $slots): string
    {
        if (($slots['route_status'] ?? null) === 'unsupported') {
            return BookingFlowState::RouteUnavailable->value;
        }

        if ($this->isBlank($slots['pickup_location'] ?? null) || $this->isBlank($slots['destination'] ?? null)) {
            return BookingFlowState::CollectingRoute->value;
        }

        if ($this->isBlank($slots['passenger_name'] ?? null) || $this->isBlank($slots['passenger_count'] ?? null)) {
            return BookingFlowState::CollectingPassenger->value;
        }

        if (
            $this->isBlank($slots['travel_date'] ?? null)
            || $this->isBlank($slots['travel_time'] ?? null)
            || $this->isBlank($slots['payment_method'] ?? null)
        ) {
            return BookingFlowState::CollectingSchedule->value;
        }

        return BookingFlowState::ReadyToConfirm->value;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasAnyFilledTrackedSlot(array $slots): bool
    {
        foreach (self::TRACKED_SLOT_KEYS as $key) {
            if (! $this->isBlank($slots[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
