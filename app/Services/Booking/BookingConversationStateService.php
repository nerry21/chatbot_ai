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
     * @var array<int, string>
     */
    private const EXPECTED_INPUTS = [
        'passenger_count',
        'travel_date',
        'travel_time',
        'selected_seats',
        'pickup_location',
        'pickup_full_address',
        'destination',
        'passenger_name',
        'contact_number',
    ];

    /**
     * @var array<int, string>
     */
    private const TRACKED_SLOT_KEYS = [
        'passenger_count',
        'travel_date',
        'travel_time',
        'selected_seats',
        'pickup_location',
        'pickup_full_address',
        'destination',
        'passenger_name',
        'passenger_names',
        'contact_number',
    ];

    /**
     * @var array<int, string>
     */
    private const SNAPSHOT_KEYS = [
        'pickup_location',
        'pickup_full_address',
        'destination',
        'passenger_name',
        'passenger_names',
        'passenger_count',
        'travel_date',
        'travel_time',
        'selected_seats',
        'seat_choices_available',
        'contact_number',
        'contact_same_as_sender',
        'booking_intent_status',
        'route_status',
        'route_issue',
        'fare_amount',
        'review_sent',
        'booking_confirmed',
        'needs_human_escalation',
        'admin_takeover',
        'waiting_admin_takeover',
        'needs_manual_confirmation',
    ];

    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly RouteValidationService $routeValidator,
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
            'passenger_names' => [],
            'passenger_count' => null,
            'travel_date' => null,
            'travel_time' => null,
            'payment_method' => null,
            'pickup_full_address' => null,
            'selected_seats' => [],
            'seat_choices_available' => [],
            'contact_number' => null,
            'contact_same_as_sender' => null,
            'route_status' => null,
            'route_issue' => null,
            'fare_amount' => null,
            'admin_takeover' => false,
            'needs_human_escalation' => false,
            'waiting_admin_takeover' => false,
            'needs_manual_confirmation' => false,
            'review_sent' => false,
            'booking_confirmed' => false,

            // Legacy mirrors retained for backward compatibility.
            'pickup_point' => null,
            'destination_point' => null,
            'waiting_for' => null,
            'waiting_reason' => null,
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
        $slots['passenger_names'] = $this->normalizeNames($slots['passenger_names'] ?? []);
        $slots['selected_seats'] = $this->normalizeStringArray($slots['selected_seats'] ?? []);
        $slots['seat_choices_available'] = $this->normalizeStringArray($slots['seat_choices_available'] ?? []);

        if ($slots['passenger_name'] === null && $slots['passenger_names'] !== []) {
            $slots['passenger_name'] = $this->primaryPassengerName($slots['passenger_names']);
        }

        if ($slots['passenger_names'] === [] && filled($slots['passenger_name'])) {
            $slots['passenger_names'] = [$slots['passenger_name']];
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
            array_map(fn (array $change): mixed => $change['new'], $changes),
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
            BookingFlowState::AskingPassengerCount->value => BookingFlowState::AskingPassengerCount->value,
            BookingFlowState::AskingDepartureDate->value => BookingFlowState::AskingDepartureDate->value,
            BookingFlowState::AskingDepartureTime->value => BookingFlowState::AskingDepartureTime->value,
            BookingFlowState::ShowingAvailableSeats->value => BookingFlowState::ShowingAvailableSeats->value,
            BookingFlowState::AskingPickupPoint->value => BookingFlowState::AskingPickupPoint->value,
            BookingFlowState::AskingPickupAddress->value => BookingFlowState::AskingPickupAddress->value,
            BookingFlowState::AskingDropoffPoint->value => BookingFlowState::AskingDropoffPoint->value,
            BookingFlowState::AskingPassengerNames->value => BookingFlowState::AskingPassengerNames->value,
            BookingFlowState::AskingContactConfirmation->value => BookingFlowState::AskingContactConfirmation->value,
            BookingFlowState::ShowingReview->value => BookingFlowState::ShowingReview->value,
            BookingFlowState::AwaitingFinalConfirmation->value,
            BookingFlowState::ReadyToConfirm->value,
            BookingStatus::AwaitingConfirmation->value => BookingFlowState::AwaitingFinalConfirmation->value,
            BookingFlowState::WaitingAdminTakeover->value => BookingFlowState::WaitingAdminTakeover->value,
            BookingFlowState::Completed->value,
            BookingFlowState::Confirmed->value => BookingFlowState::Completed->value,
            BookingFlowState::CollectingRoute->value,
            BookingFlowState::CollectingPassenger->value,
            BookingFlowState::CollectingSchedule->value,
            'collecting' => $this->inferOpenStateFromSlots($slots),
            BookingFlowState::RouteUnavailable->value => $this->unsupportedState($slots),
            BookingFlowState::Closed->value,
            'needs_human' => ($slots['waiting_admin_takeover'] ?? false) === true
                ? BookingFlowState::WaitingAdminTakeover->value
                : (($slots['booking_confirmed'] ?? false) === true
                    ? BookingFlowState::Completed->value
                    : BookingFlowState::Idle->value),
            default => $this->hasAnyFilledTrackedSlot($slots)
                ? $this->inferOpenStateFromSlots($slots)
                : BookingFlowState::Idle->value,
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
        $passengerNames = $this->normalizeNames($slots['passenger_names'] ?? []);

        if ($passengerNames === [] && filled($slots['passenger_name'] ?? null)) {
            $passengerNames = [(string) $slots['passenger_name']];
        }

        $booking->pickup_location = $slots['pickup_location'];
        $booking->pickup_full_address = $slots['pickup_full_address'] ?? $booking->pickup_full_address;
        $booking->destination = $slots['destination'];
        $booking->trip_key = $this->routeValidator->tripKey(
            $booking->pickup_location,
            $booking->destination,
        );
        $booking->departure_date = $slots['travel_date'];
        $booking->departure_time = $slots['travel_time'];
        $booking->passenger_count = $slots['passenger_count'];
        $booking->selected_seats = $this->normalizeStringArray($slots['selected_seats'] ?? []);
        $booking->passenger_names = $passengerNames !== [] ? $passengerNames : null;
        $booking->passenger_name = $passengerNames !== []
            ? $this->primaryPassengerName($passengerNames)
            : ($slots['passenger_name'] ?? null);
        $booking->payment_method = $slots['payment_method'] ?? $booking->payment_method;
        $booking->price_estimate = $slots['fare_amount'];
        $booking->contact_number = $slots['contact_number']
            ?: ($booking->contact_number ?: ($senderPhone !== '' ? $senderPhone : null));
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
     * @return array<string, mixed>
     */
    public function snapshot(Conversation $conversation): array
    {
        return $this->snapshotFromSlots($this->load($conversation), $this->expectedInput($conversation));
    }

    /**
     * Recompute a safe flow checkpoint when persisted state becomes inconsistent.
     * This keeps the bot moving instead of getting stuck on a stale or invalid state.
     *
     * @return array<string, mixed>
     */
    public function repairCorruptedState(Conversation $conversation): array
    {
        if (! (bool) config('chatbot.guards.repair_corrupted_state', true)) {
            return $this->load($conversation);
        }

        $slots = $this->load($conversation);
        $expectedInput = $this->expectedInput($conversation);
        $updates = [];
        $reasons = [];
        $nextRequiredInput = $this->nextRequiredInput($slots);

        if (($slots['review_sent'] ?? false) === true && $nextRequiredInput !== null) {
            $updates['review_sent'] = false;
            $updates['booking_confirmed'] = false;
            $reasons[] = 'review_sent_before_slots_complete';
        }

        if (($slots['booking_confirmed'] ?? false) === true && $nextRequiredInput !== null) {
            $updates['booking_confirmed'] = false;
            $reasons[] = 'booking_confirmed_before_slots_complete';
        }

        if (($slots['route_status'] ?? null) === 'unsupported' && ! in_array((string) ($slots['route_issue'] ?? ''), ['pickup_location', 'destination'], true)) {
            $updates['route_issue'] = 'destination';
            $reasons[] = 'route_issue_invalid';
        }

        if (($slots['fare_amount'] ?? null) !== null && ! is_numeric($slots['fare_amount'])) {
            $updates['fare_amount'] = null;
            $reasons[] = 'fare_amount_invalid';
        }

        $normalizedExpectedInput = is_string($expectedInput) && in_array(trim($expectedInput), self::EXPECTED_INPUTS, true)
            ? trim($expectedInput)
            : null;

        if ($expectedInput !== $normalizedExpectedInput) {
            $reasons[] = 'expected_input_invalid';
        }

        if ($updates !== []) {
            $this->putMany($conversation, $updates, 'corrupted_state_repair');
            $slots = $this->load($conversation);
        }

        $nextRequiredInput = $this->nextRequiredInput($slots);
        $targetExpectedInput = $this->targetExpectedInput($slots, $nextRequiredInput);
        $targetState = $this->targetStateForSnapshot($slots, $nextRequiredInput);
        $currentState = $this->normalizeFlowState((string) ($slots['booking_intent_status'] ?? null), $slots);

        if ($currentState !== $targetState || $normalizedExpectedInput !== $targetExpectedInput) {
            $this->transitionFlowState(
                $conversation,
                $targetState,
                $targetExpectedInput,
                'corrupted_state_repair',
                ['reasons' => $reasons],
            );
            $reasons[] = 'state_realigned';
        }

        if ($reasons !== []) {
            WaLog::warning('[BookingState] repaired inconsistent conversation state', [
                'conversation_id' => $conversation->id,
                'reasons' => $reasons,
                'snapshot' => $this->snapshot($conversation),
            ]);
        }

        return $this->load($conversation);
    }

    /**
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
     * @param  array<string, mixed>  $slots
     */
    public function nextRequiredInput(array $slots): ?string
    {
        if (($slots['route_status'] ?? null) === 'unsupported') {
            return (string) ($slots['route_issue'] ?? 'destination');
        }

        if ($this->isBlank($slots['passenger_count'] ?? null)) {
            return 'passenger_count';
        }

        if ($this->isBlank($slots['travel_date'] ?? null)) {
            return 'travel_date';
        }

        if ($this->isBlank($slots['travel_time'] ?? null)) {
            return 'travel_time';
        }

        $passengerCount = max(1, (int) ($slots['passenger_count'] ?? 1));
        $selectedSeats = $this->normalizeStringArray($slots['selected_seats'] ?? []);

        if (count($selectedSeats) < min($passengerCount, count(config('chatbot.jet.seat_labels', [])))) {
            return 'selected_seats';
        }

        if ($this->isBlank($slots['pickup_location'] ?? null)) {
            return 'pickup_location';
        }

        if ($this->isBlank($slots['pickup_full_address'] ?? null)) {
            return 'pickup_full_address';
        }

        if ($this->isBlank($slots['destination'] ?? null)) {
            return 'destination';
        }

        if ($this->missingPassengerNames($slots) > 0) {
            return 'passenger_name';
        }

        if ($this->isBlank($slots['contact_number'] ?? null)) {
            return 'contact_number';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    public function missingPassengerNames(array $slots): int
    {
        $required = max(1, (int) ($slots['passenger_count'] ?? 1));
        $names = $this->normalizeNames($slots['passenger_names'] ?? []);

        if ($names === [] && filled($slots['passenger_name'] ?? null)) {
            $names = [(string) $slots['passenger_name']];
        }

        return max(0, $required - count($names));
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

        if (array_key_exists('passenger_names', $updates)) {
            $updates['passenger_names'] = $this->normalizeNames((array) $updates['passenger_names']);
            $updates['passenger_name'] = $this->primaryPassengerName($updates['passenger_names']);
        }

        if (array_key_exists('selected_seats', $updates)) {
            $updates['selected_seats'] = $this->normalizeStringArray((array) $updates['selected_seats']);
        }

        if (array_key_exists('seat_choices_available', $updates)) {
            $updates['seat_choices_available'] = $this->normalizeStringArray((array) $updates['seat_choices_available']);
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
            $updates['waiting_admin_takeover'] = $updates['waiting_admin_takeover'] ?? false;
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
            array_map(fn (array $change): mixed => $change['new'], $changes),
        );

        WaLog::debug('[BookingState] conversation state updated', [
            'conversation_id' => $conversation->id,
            'source' => $source,
            'changes' => $loggableChanges,
            'tracked_slot_changes' => [
                'created' => array_keys($trackedSlotChanges['created']),
                'overwritten' => array_keys($trackedSlotChanges['overwritten']),
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
            'pickup_full_address' => $booking->pickup_full_address,
            'destination' => $booking->destination,
            'passenger_name' => $booking->passenger_name ?? $this->primaryPassengerName($booking->passenger_names),
            'passenger_names' => $this->normalizeNames($booking->passenger_names ?? []),
            'passenger_count' => $booking->passenger_count,
            'travel_date' => $booking->departure_date?->format('Y-m-d'),
            'travel_time' => $booking->departure_time,
            'selected_seats' => $this->normalizeStringArray($booking->selected_seats ?? []),
            'fare_amount' => $booking->price_estimate !== null
                ? (int) round((float) $booking->price_estimate)
                : null,
            'contact_number' => $booking->contact_number,
            'contact_same_as_sender' => $booking->contact_same_as_sender,
            'needs_manual_confirmation' => (int) ($booking->passenger_count ?? 0) === (int) config('chatbot.jet.passenger.manual_confirm_max', 6),
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

        if (in_array($currentStatus, [
            BookingFlowState::WaitingAdminTakeover->value,
            BookingFlowState::Completed->value,
        ], true)) {
            return $updates;
        }

        if ($booking->booking_status === BookingStatus::AwaitingConfirmation) {
            $updates['booking_intent_status'] = BookingFlowState::AwaitingFinalConfirmation->value;
            $updates['review_sent'] = true;
            $updates['booking_confirmed'] = false;

            return $updates;
        }

        if (in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::Completed], true)) {
            $updates['booking_intent_status'] = BookingFlowState::Completed->value;
            $updates['review_sent'] = true;
            $updates['booking_confirmed'] = true;

            return $updates;
        }

        if ($this->hasAnyFilledTrackedSlot($bookingSlots)) {
            $updates['booking_intent_status'] = $this->inferOpenStateFromSlots($bookingSlots);
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function inferOpenStateFromSlots(array $slots): string
    {
        if (($slots['waiting_admin_takeover'] ?? false) === true) {
            return BookingFlowState::WaitingAdminTakeover->value;
        }

        if (($slots['booking_confirmed'] ?? false) === true) {
            return BookingFlowState::Completed->value;
        }

        if (($slots['route_status'] ?? null) === 'unsupported') {
            return $this->unsupportedState($slots);
        }

        return $this->stateForInput($this->nextRequiredInput($slots), $slots);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function stateForInput(?string $input, array $slots = []): string
    {
        return match ($input) {
            'passenger_count' => BookingFlowState::AskingPassengerCount->value,
            'travel_date' => BookingFlowState::AskingDepartureDate->value,
            'travel_time' => BookingFlowState::AskingDepartureTime->value,
            'selected_seats' => BookingFlowState::ShowingAvailableSeats->value,
            'pickup_location' => BookingFlowState::AskingPickupPoint->value,
            'pickup_full_address' => BookingFlowState::AskingPickupAddress->value,
            'destination' => BookingFlowState::AskingDropoffPoint->value,
            'passenger_name' => BookingFlowState::AskingPassengerNames->value,
            'contact_number' => BookingFlowState::AskingContactConfirmation->value,
            null => ($slots['review_sent'] ?? false) === true
                ? BookingFlowState::AwaitingFinalConfirmation->value
                : BookingFlowState::ShowingReview->value,
            default => BookingFlowState::Idle->value,
        };
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function unsupportedState(array $slots): string
    {
        return ($slots['route_issue'] ?? 'destination') === 'pickup_location'
            ? BookingFlowState::AskingPickupPoint->value
            : BookingFlowState::AskingDropoffPoint->value;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function targetStateForSnapshot(array $slots, ?string $nextRequiredInput): string
    {
        if (
            ! $this->hasAnyFilledTrackedSlot($slots)
            && ($slots['review_sent'] ?? false) !== true
            && ($slots['booking_confirmed'] ?? false) !== true
            && ($slots['waiting_admin_takeover'] ?? false) !== true
        ) {
            return BookingFlowState::Idle->value;
        }

        if (($slots['waiting_admin_takeover'] ?? false) === true) {
            return BookingFlowState::WaitingAdminTakeover->value;
        }

        if (($slots['booking_confirmed'] ?? false) === true && $nextRequiredInput === null) {
            return BookingFlowState::Completed->value;
        }

        if (($slots['review_sent'] ?? false) === true && $nextRequiredInput === null) {
            return BookingFlowState::AwaitingFinalConfirmation->value;
        }

        return $this->stateForInput($nextRequiredInput, $slots);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function targetExpectedInput(array $slots, ?string $nextRequiredInput): ?string
    {
        if (
            ! $this->hasAnyFilledTrackedSlot($slots)
            && ($slots['review_sent'] ?? false) !== true
            && ($slots['booking_confirmed'] ?? false) !== true
            && ($slots['waiting_admin_takeover'] ?? false) !== true
        ) {
            return null;
        }

        if (($slots['waiting_admin_takeover'] ?? false) === true) {
            return null;
        }

        if (($slots['booking_confirmed'] ?? false) === true && $nextRequiredInput === null) {
            return null;
        }

        if (($slots['review_sent'] ?? false) === true && $nextRequiredInput === null) {
            return null;
        }

        return $nextRequiredInput;
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

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeStringArray(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        )));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeNames(array $values): array
    {
        return array_values(array_filter(array_map(
            function (mixed $value): ?string {
                if (! is_string($value) || trim($value) === '') {
                    return null;
                }

                return mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
            },
            $values,
        )));
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
