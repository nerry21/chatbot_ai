<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Booking\Guardrails\ActionEligibilityValidatorService;
use App\Services\Chatbot\GreetingService;
use App\Services\Chatbot\HumanEscalationService;
use App\Services\Chatbot\IntentDetectionService;
use App\Support\WaLog;
use Illuminate\Support\Carbon;
use RuntimeException;

class BookingFlowStateMachine
{
    public function __construct(
        private readonly BookingAssistantService $bookingAssistant,
        private readonly BookingConversationStateService $stateService,
        private readonly BookingSlotExtractorService $slotExtractor,
        private readonly RouteValidationService $routeValidator,
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly BookingConfirmationService $confirmationService,
        private readonly TimeGreetingService $timeGreetingService,
        private readonly GreetingService $greetingService,
        private readonly HumanEscalationService $humanEscalationService,
        private readonly IntentDetectionService $intentDetectionService,
        private readonly BookingInteractiveMessageService $interactiveService,
        private readonly BookingReplyNaturalizerService $replyNaturalizer,
        private readonly ActionEligibilityValidatorService $actionEligibilityValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult Optional precomposed reply for backward compatibility.
     * @return array{
     *     handled: bool,
     *     booking: BookingRequest|null,
     *     booking_decision: array<string, mixed>|null,
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>
     * }
     */
    public function handle(
        Conversation $conversation,
        Customer $customer,
        ConversationMessage $message,
        array $intentResult,
        array $entityResult,
        array $replyResult = [],
    ): array {
        $booking = $this->bookingAssistant->findExistingDraft($conversation);
        $slots = $this->stateService->hydrateFromBooking($conversation, $booking);
        $slots = $this->stateService->repairCorruptedState($conversation);
        $expectedInput = $this->stateService->expectedInput($conversation);
        $messageText = trim((string) ($message->message_text ?? ''));
        $timeGreeting = $this->timeGreetingService->resolve();

        $this->stateService->putMany($conversation, [
            'time_greeting' => $timeGreeting['label'],
            'admin_takeover' => $conversation->isAdminTakeover(),
        ], 'conversation_context');

        $extracted = $this->slotExtractor->extract(
            messageText: $messageText,
            currentSlots: $slots,
            entityResult: $entityResult,
            expectedInput: $expectedInput,
            senderPhone: $customer->phone_e164 ?? '',
        );

        $updates = $extracted['updates'];
        $signals = $extracted['signals'];
        $rawIntentValue = (string) ($intentResult['intent'] ?? '');
        $intentResult = $this->intentDetectionService->detect(
            rawIntentResult: $intentResult,
            messageText: $messageText,
            signals: $signals,
            slots: $slots,
            updates: $updates,
            replyResult: $replyResult,
        );

        WaLog::info('[BookingFlow] intent parsed', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'raw_intent' => $rawIntentValue !== '' ? $rawIntentValue : null,
            'resolved_intent' => $intentResult['intent'] ?? null,
            'llm_needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
            'llm_handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
            'expected_input' => $expectedInput,
            'slot_update_keys' => array_keys($updates),
            'signal_flags' => array_keys(array_filter($signals, fn (mixed $value): bool => $value === true)),
        ]);

        if ($signals['greeting_detected']) {
            $this->stateService->putMany($conversation, [
                'greeting_detected' => true,
                'salam_type' => $signals['salam_type'],
            ], 'greeting_signal');
        }

        if ($signals['human_keyword']) {
            return $this->escalateUnknownQuestion($conversation, $customer, $intentResult, $signals, $messageText, $booking);
        }

        $eligibility = $this->actionEligibilityValidator->validate(
            conversation: $conversation,
            intentResult: $intentResult,
            slots: array_merge($slots, [
                'booking_expected_input' => $expectedInput,
            ]),
            updates: $updates,
            booking: $booking,
        );
        $intentResult = $eligibility['intent_result'];

        if (is_array($eligibility['reply'] ?? null)) {
            WaLog::info('[BookingFlow] action eligibility guard applied', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'validator_action' => $eligibility['meta']['action'] ?? null,
                'reasons' => $eligibility['meta']['reasons'] ?? [],
                'resolved_intent' => $intentResult['intent'] ?? null,
            ]);

            return $this->decision(
                booking: $booking,
                action: (string) ($eligibility['meta']['action'] ?? 'guarded'),
                reply: $eligibility['reply'],
                intentResult: $intentResult,
            );
        }

        $hasBookingContext = $this->hasBookingContext($conversation, $booking, $intentResult, $slots, $updates, $signals);

        if ($signals['greeting_only'] && ! $hasBookingContext) {
            $opening = $this->greetingService->buildGreetingReply($conversation, $messageText, $slots)
                ?? 'Ada yang bisa kami bantu, Bapak/Ibu?';

            $this->stateService->transitionFlowState($conversation, BookingFlowState::Idle, null, 'greeting_only');

            return $this->decision(
                booking: $booking,
                action: 'greeting',
                reply: $this->reply($opening, ['source' => 'booking_engine', 'action' => 'greeting']),
                intentResult: $this->overrideIntent(
                    $intentResult,
                    ($signals['salam_type'] ?? null) === 'islamic' ? IntentType::SalamIslam : IntentType::Greeting,
                ),
            );
        }

        if (($slots['waiting_admin_takeover'] ?? false) === true && $conversation->isAdminTakeover() && $updates === []) {
            return $this->decision(
                booking: $booking,
                action: 'waiting_admin_takeover',
                reply: $this->reply(
                    $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->waitingAdminTakeover()),
                    ['source' => 'booking_engine', 'action' => 'waiting_admin_takeover'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::PertanyaanTidakTerjawab),
            );
        }

        if (! $hasBookingContext) {
            if ($this->isRouteListInquiry($signals, $messageText)) {
                return $this->decision(
                    booking: null,
                    action: 'route_list',
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->routeListReply()),
                        ['source' => 'booking_engine', 'action' => 'route_list'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::TanyaRute),
                );
            }

            if ($signals['today_schedule_keyword']) {
                $dateLabel = Carbon::now($this->timeGreetingService->timezone())->translatedFormat('d F Y');

                return $this->decision(
                    booking: null,
                    action: 'schedule_today',
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->scheduleTodayReply($dateLabel)),
                        ['source' => 'booking_engine', 'action' => 'schedule_today'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::TanyaKeberangkatanHariIni),
                );
            }

            if ($signals['schedule_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'schedule_inquiry',
                    reply: $this->reply(
                        $this->withGreetingContext(
                            $signals,
                            $messageText,
                            $this->replyNaturalizer->compose([
                                $this->replyNaturalizer->scheduleLine(),
                                'Izin Bapak/Ibu, jika ingin lanjut booking silakan kirim jumlah penumpang atau tanggal keberangkatannya ya.',
                            ]),
                        ),
                        ['source' => 'booking_engine', 'action' => 'schedule_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::TanyaJam),
                );
            }

            if ($signals['price_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'price_inquiry',
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->priceNeedRouteReply()),
                        ['source' => 'booking_engine', 'action' => 'price_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::TanyaHarga),
                );
            }

            if ($signals['close_intent']) {
                return $this->decision(
                    booking: null,
                    action: 'close_conversation',
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->closing(
                            $this->replySeed($conversation, 'close_conversation'),
                        )),
                        ['source' => 'booking_engine', 'action' => 'close_conversation', 'close_conversation' => true],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::CloseIntent),
                );
            }

            if ($this->shouldEscalateWithoutAutoReply($intentResult, $replyResult)) {
                return $this->escalateUnknownQuestion($conversation, $customer, $intentResult, $signals, $messageText, null);
            }

            if (trim((string) ($replyResult['text'] ?? '')) !== '') {
                return $this->decision(
                    booking: null,
                    action: 'pass_through',
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, (string) ($replyResult['text'] ?? '')),
                        ['source' => 'ai_reply', 'action' => 'pass_through'],
                        (bool) ($replyResult['is_fallback'] ?? false),
                    ),
                    intentResult: $intentResult,
                );
            }

            return $this->decision(
                booking: null,
                action: 'compose_ai_reply',
                reply: $this->reply(
                    '',
                    [
                        'source' => 'ai_reply',
                        'action' => 'compose_ai_reply',
                        'requires_composition' => true,
                    ],
                ),
                intentResult: $intentResult,
            );
        }

        $booking = $booking ?? $this->bookingAssistant->findOrCreateDraft($conversation);

        return $this->advanceBookingFlow(
            conversation: $conversation,
            customer: $customer,
            booking: $booking,
            intentResult: $intentResult,
            signals: $signals,
            slots: $slots,
            updates: $updates,
            messageText: $messageText,
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function advanceBookingFlow(
        Conversation $conversation,
        Customer $customer,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        array $slots,
        array $updates,
        string $messageText,
    ): array {
        $expectedInput = $this->stateService->expectedInput($conversation);
        $mergedUpdates = $this->mergeIncrementalUpdates($slots, $updates, $expectedInput);

        if (($mergedUpdates['passenger_count'] ?? null) !== null && (int) $mergedUpdates['passenger_count'] > (int) config('chatbot.jet.passenger.manual_confirm_max', 6)) {
            return $this->escalateUnknownQuestion($conversation, $customer, $intentResult, $signals, $messageText, $booking);
        }

        $changes = $mergedUpdates !== []
            ? $this->stateService->putMany($conversation, array_merge($mergedUpdates, [
                'needs_human_escalation' => false,
                'admin_takeover' => false,
                'waiting_admin_takeover' => false,
                'needs_manual_confirmation' => (int) ($mergedUpdates['passenger_count'] ?? $slots['passenger_count'] ?? 0) === (int) config('chatbot.jet.passenger.manual_confirm_max', 6),
            ]), 'slot_extraction')
            : [];

        $tracked = $this->stateService->trackedSlotChanges($changes);
        $correctionLines = $this->replyNaturalizer->correctionLinesFromChanges($tracked['overwritten']);
        $capturedUpdates = array_map(fn (array $change): mixed => $change['new'], $tracked['created']);

        $slots = $this->stateService->load($conversation);
        $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');

        if ($this->shouldResetSeatSelection($changes, $mergedUpdates, $slots)) {
            $this->seatAvailability->releaseSeats($booking);
            $this->stateService->putMany($conversation, [
                'selected_seats' => [],
                'seat_choices_available' => [],
            ], 'schedule_changed_reset_seat');
            $slots = $this->stateService->load($conversation);
            $booking = $this->stateService->syncBooking($booking->fresh(), $slots, $customer->phone_e164 ?? '');
        }

        if ($seatDecision = $this->handleSeatReservation($conversation, $booking, $intentResult, $signals, $messageText, $slots, $changes)) {
            return $seatDecision;
        }

        $route = $this->evaluateRoute($slots);
        $this->stateService->putMany($conversation, [
            'route_status' => $route['status'],
            'route_issue' => $route['focus_slot'],
            'fare_amount' => $route['fare_amount'],
        ], 'route_evaluation');
        $slots = $this->stateService->load($conversation);
        $booking = $this->stateService->syncBooking($booking->fresh(), $slots, $customer->phone_e164 ?? '');
        $booking = $this->seatAvailability->syncDraftReservationContext($booking->fresh());

        if (($slots['review_sent'] ?? false) === true && ($signals['affirmation'] ?? false) === true && $mergedUpdates === []) {
            $this->confirmationService->confirm($booking);
            $this->stateService->putMany($conversation, [
                'booking_confirmed' => true,
                'review_sent' => true,
            ], 'booking_confirmation');
            $this->stateService->transitionFlowState($conversation, BookingFlowState::Completed, null, 'booking_confirmation');
            $this->humanEscalationService->forwardBooking($conversation, $customer, $booking->fresh());

            return $this->decision(
                booking: $booking->fresh(),
                action: 'confirmed',
                reply: $this->reply(
                    $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->confirmed()),
                    ['source' => 'booking_engine', 'action' => 'confirmed'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::KonfirmasiBooking),
            );
        }

        if (($slots['review_sent'] ?? false) === true && (($signals['rejection'] ?? false) === true || ($signals['change_request'] ?? false) === true) && $mergedUpdates === []) {
            $this->stateService->putMany($conversation, [
                'review_sent' => false,
                'booking_confirmed' => false,
            ], 'booking_rejection');

            return $this->decision(
                booking: $booking,
                action: 'ask_correction',
                reply: $this->reply(
                    $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->askCorrection()),
                    ['source' => 'booking_engine', 'action' => 'ask_correction'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::UbahDataBooking),
            );
        }

        $nextInput = $this->stateService->nextRequiredInput($slots);

        if (($signals['gratitude'] ?? false) === true && $mergedUpdates === [] && $nextInput !== null) {
            return $this->decision(
                booking: $booking,
                action: 'close_pending',
                reply: $this->reply(
                    $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->closing(
                        $this->replySeed($conversation, 'close_pending:'.$nextInput),
                    )),
                    ['source' => 'booking_engine', 'action' => 'close_pending', 'close_conversation' => true],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::CloseIntent),
            );
        }

        if (($signals['acknowledgement'] ?? false) === true && $mergedUpdates === [] && $nextInput !== null) {
            return $this->decision(
                booking: $booking,
                action: 'acknowledge_pending',
                reply: $this->reply(
                    $this->withGreetingContext(
                        $signals,
                        $messageText,
                        $this->replyNaturalizer->shortAcknowledgement(
                            expectedInput: $nextInput,
                            seed: $this->replySeed($conversation, 'acknowledge_pending:'.$nextInput),
                        ),
                    ),
                    ['source' => 'booking_engine', 'action' => 'acknowledge_pending'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($route['status'] === 'unsupported') {
            $focusSlot = $route['focus_slot'] ?? 'destination';
            $this->stateService->transitionFlowState(
                $conversation,
                $focusSlot === 'pickup_location'
                    ? BookingFlowState::AskingPickupPoint
                    : BookingFlowState::AskingDropoffPoint,
                $focusSlot,
                'unsupported_route',
            );

            return $this->decision(
                booking: $booking,
                action: 'unsupported_route',
                reply: $this->reply(
                    $this->withGreetingContext(
                        $signals,
                        $messageText,
                        $this->replyNaturalizer->compose([
                            ...$correctionLines,
                            $this->replyNaturalizer->captureSummary($capturedUpdates),
                            $this->replyNaturalizer->unsupportedRouteReply(
                                $slots['pickup_location'] ?? null,
                                $slots['destination'] ?? null,
                                $route['suggestions'],
                                $focusSlot,
                            ),
                        ]),
                    ),
                    ['source' => 'booking_engine', 'action' => 'unsupported_route', 'has_booking_update' => $mergedUpdates !== []],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::TanyaRute),
            );
        }

        if ($nextInput !== null) {
            return $this->replyForNextStep($conversation, $booking, $intentResult, $signals, $messageText, $slots, $mergedUpdates, $capturedUpdates, $correctionLines, $route, $nextInput);
        }

        if ($route['fare_amount'] === null) {
            return $this->escalateUnknownQuestion($conversation, $customer, $intentResult, $signals, $messageText, $booking);
        }

        return $this->replyForReview($conversation, $booking, $intentResult, $signals, $messageText);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function escalateUnknownQuestion(
        Conversation $conversation,
        Customer $customer,
        array $intentResult,
        array $signals,
        string $messageText,
        ?BookingRequest $booking,
    ): array {
        $this->stateService->putMany($conversation, [
            'admin_takeover' => true,
            'needs_human_escalation' => true,
            'waiting_admin_takeover' => true,
        ], 'human_fallback');
        $this->stateService->transitionFlowState($conversation, BookingFlowState::WaitingAdminTakeover, null, 'human_fallback');

        $this->humanEscalationService->escalateQuestion(
            conversation: $conversation,
            customer: $customer,
            reason: 'Pertanyaan customer perlu bantuan admin.',
        );

        return $this->decision(
            booking: $booking,
            action: 'human_fallback',
            reply: $this->reply(
                $this->withGreetingContext($signals, $messageText, $this->replyNaturalizer->fallbackQuestionToAdmin()),
                ['source' => 'booking_engine', 'action' => 'human_fallback'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::PertanyaanTidakTerjawab),
        );
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeIncrementalUpdates(array $slots, array $updates, ?string $expectedInput): array
    {
        if ($expectedInput === 'passenger_name' && isset($updates['passenger_names'])) {
            $existing = is_array($slots['passenger_names'] ?? null) ? $slots['passenger_names'] : [];
            $required = max(1, (int) ($slots['passenger_count'] ?? 1));

            if ($existing !== [] && count($updates['passenger_names']) < $required) {
                $updates['passenger_names'] = array_values(array_unique(array_merge($existing, $updates['passenger_names'])));
                $updates['passenger_name'] = $updates['passenger_names'][0] ?? null;
            }
        }

        if ($expectedInput === 'selected_seats' && isset($updates['selected_seats'])) {
            $existing = is_array($slots['selected_seats'] ?? null) ? $slots['selected_seats'] : [];
            $required = max(1, (int) ($slots['passenger_count'] ?? 1));
            $updates['selected_seats'] = array_slice(array_values(array_unique(array_merge($existing, $updates['selected_seats']))), 0, $required);
        }

        return $updates;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $slots
     */
    private function shouldResetSeatSelection(array $changes, array $updates, array $slots): bool
    {
        if (($slots['selected_seats'] ?? []) === []) {
            return false;
        }

        if (array_key_exists('selected_seats', $updates)) {
            return false;
        }

        return isset($changes['travel_date']) || isset($changes['travel_time']) || isset($changes['passenger_count']);
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{status: string|null, focus_slot: string|null, suggestions: array<int, string>, fare_amount: int|null}
     */
    private function evaluateRoute(array $slots): array
    {
        $pickup = $slots['pickup_location'] ?? null;
        $destination = $slots['destination'] ?? null;

        if (blank($pickup) || blank($destination)) {
            return ['status' => null, 'focus_slot' => null, 'suggestions' => [], 'fare_amount' => null];
        }

        $fareAmount = $this->fareCalculator->calculate($pickup, $destination, (int) ($slots['passenger_count'] ?? 1));

        if ($fareAmount !== null) {
            return ['status' => 'supported', 'focus_slot' => null, 'suggestions' => [], 'fare_amount' => $fareAmount];
        }

        $pickupKnown = $this->routeValidator->isKnownLocation($pickup);
        $destinationKnown = $this->routeValidator->isKnownLocation($destination);

        if ($pickupKnown && ! $destinationKnown) {
            return ['status' => 'unsupported', 'focus_slot' => 'destination', 'suggestions' => array_slice($this->routeValidator->supportedDestinations($pickup), 0, 8), 'fare_amount' => null];
        }

        if ($destinationKnown && ! $pickupKnown) {
            return ['status' => 'unsupported', 'focus_slot' => 'pickup_location', 'suggestions' => array_slice($this->routeValidator->supportedPickupsForDestination($destination), 0, 8), 'fare_amount' => null];
        }

        return ['status' => 'unsupported', 'focus_slot' => 'destination', 'suggestions' => array_slice($this->routeValidator->supportedDestinations($pickup), 0, 8), 'fare_amount' => null];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, mixed>|null
     */
    private function handleSeatReservation(
        Conversation $conversation,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        string $messageText,
        array $slots,
        array $changes,
    ): ?array {
        if (! isset($changes['selected_seats'])) {
            return null;
        }

        try {
            $reserved = $this->seatAvailability->reserveSeats($booking, (array) ($slots['selected_seats'] ?? []));
            $this->stateService->putMany($conversation, ['selected_seats' => $reserved], 'seat_reservation');

            return null;
        } catch (RuntimeException $e) {
            $existing = is_array($booking->selected_seats ?? null) ? $booking->selected_seats : [];
            $this->stateService->putMany($conversation, ['selected_seats' => $existing], 'seat_reservation_failed');
            $remaining = max(1, (int) ($slots['passenger_count'] ?? 1) - count($existing));
            $availability = $this->seatAvailability->availabilitySnapshot($booking->fresh(), $remaining);
            $available = $availability['available_seats'];
            $this->stateService->putMany($conversation, ['seat_choices_available' => $available], 'seat_reservation_failed');

            if (($availability['available_count'] ?? 0) < $remaining) {
                $alternativePrompt = $this->alternativeTravelTimePrompt(
                    ($availability['available_count'] ?? 0) === 0
                        ? $this->replyNaturalizer->seatUnavailableAtTime(
                            (string) $slots['travel_time'],
                            $availability['alternative_slots'] ?? [],
                        )
                        : $this->replyNaturalizer->seatCapacityInsufficient(
                            (string) $slots['travel_time'],
                            max(1, (int) ($slots['passenger_count'] ?? 1)),
                            $available,
                            $availability['alternative_slots'] ?? [],
                        ),
                    $availability['alternative_slots'] ?? [],
                );

                $this->stateService->transitionFlowState(
                    $conversation,
                    $alternativePrompt['state'] ?? BookingFlowState::AskingDepartureTime,
                    $alternativePrompt['next_input'] ?? 'travel_time',
                    'seat_reservation_failed',
                );

                return $this->decision(
                    booking: $booking->fresh(),
                    action: (string) ($alternativePrompt['action'] ?? 'collect_travel_time'),
                    reply: $this->reply(
                        $this->withGreetingContext($signals, $messageText, (string) $alternativePrompt['prompt']),
                        ['source' => 'booking_engine', 'action' => (string) ($alternativePrompt['action'] ?? 'collect_travel_time'), 'has_booking_update' => true],
                        false,
                        $alternativePrompt['message_type'] ?? 'text',
                        $alternativePrompt['outbound_payload'] ?? [],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
                );
            }

            $prompt = $this->replyNaturalizer->compose([
                $this->replyNaturalizer->availableSeatsLine((string) $slots['travel_time'], $available),
                $this->replyNaturalizer->seatSelectionInvalid($remaining, $e->getMessage()),
            ]);
            $this->stateService->transitionFlowState($conversation, BookingFlowState::ShowingAvailableSeats, 'selected_seats', 'seat_reservation_failed');

            return $this->decision(
                booking: $booking->fresh(),
                action: 'collect_selected_seats',
                reply: $this->reply(
                    $this->withGreetingContext($signals, $messageText, $prompt),
                    ['source' => 'booking_engine', 'action' => 'collect_selected_seats', 'has_booking_update' => true],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $mergedUpdates
     * @param  array<string, mixed>  $capturedUpdates
     * @param  array<int, string>  $correctionLines
     * @param  array{status: string|null, focus_slot: string|null, suggestions: array<int, string>, fare_amount: int|null}  $route
     * @return array<string, mixed>
     */
    private function replyForNextStep(
        Conversation $conversation,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        string $messageText,
        array $slots,
        array $mergedUpdates,
        array $capturedUpdates,
        array $correctionLines,
        array $route,
        string $nextInput,
    ): array {
        $prompt = $this->promptForInput($conversation, $booking, $nextInput, $slots);
        $resolvedNextInput = is_string($prompt['next_input'] ?? null) && trim((string) $prompt['next_input']) !== ''
            ? trim((string) $prompt['next_input'])
            : $nextInput;
        $manualNotice = ($slots['needs_manual_confirmation'] ?? false) === true && array_key_exists('passenger_count', $capturedUpdates)
            ? $this->replyNaturalizer->manualConfirmationNotice()
            : null;

        $text = $this->replyNaturalizer->compose([
            ...$correctionLines,
            $this->replyNaturalizer->captureSummary($capturedUpdates),
            $manualNotice,
            ($route['status'] ?? null) === 'supported' && in_array($resolvedNextInput, ['destination', 'passenger_name', 'contact_number'], true)
                ? $this->replyNaturalizer->routeAvailableLine($slots['pickup_location'] ?? null, $slots['destination'] ?? null)
                : null,
            ($route['status'] ?? null) === 'supported' && in_array($resolvedNextInput, ['passenger_name', 'contact_number'], true)
                ? $this->replyNaturalizer->priceLine($slots['pickup_location'] ?? null, $slots['destination'] ?? null, $slots['passenger_count'] ?? null)
                : null,
            $prompt['lead'] ?? null,
            $prompt['prompt'],
        ]);

        $state = $prompt['state'] ?? $this->stateForExpectedInput($resolvedNextInput);
        $action = is_string($prompt['action'] ?? null) && trim((string) $prompt['action']) !== ''
            ? trim((string) $prompt['action'])
            : 'collect_'.$resolvedNextInput;

        $this->stateService->transitionFlowState($conversation, $state, $resolvedNextInput, 'collecting_step');

        return $this->decision(
            booking: $booking,
            action: $action,
            reply: $this->reply(
                $this->withGreetingContext($signals, $messageText, $text),
                ['source' => 'booking_engine', 'action' => $action, 'has_booking_update' => $mergedUpdates !== []],
                false,
                $prompt['message_type'] ?? 'text',
                $prompt['outbound_payload'] ?? [],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function replyForReview(
        Conversation $conversation,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        string $messageText,
    ): array {
        $this->confirmationService->requestConfirmation($booking);
        $this->stateService->putMany($conversation, [
            'review_sent' => true,
            'booking_confirmed' => false,
        ], 'ready_to_confirm');
        $this->stateService->transitionFlowState($conversation, BookingFlowState::AwaitingFinalConfirmation, null, 'ready_to_confirm');

        $buttons = $this->interactiveService->buttonMessage(
            'Mohon izin Bapak/Ibu, apakah data perjalanan ini sudah benar?',
            [
                ['id' => 'booking_confirm', 'title' => 'Benar'],
                ['id' => 'booking_change', 'title' => 'Ubah Data'],
            ],
        );

        return $this->decision(
            booking: $booking->fresh(),
            action: 'ask_confirmation',
            reply: $this->reply(
                $this->withGreetingContext(
                    $signals,
                    $messageText,
                    $this->replyNaturalizer->reviewSummary(
                        $booking->fresh(),
                        $this->replySeed($conversation, 'booking_review'),
                    ),
                ),
                ['source' => 'booking_engine', 'action' => 'ask_confirmation'],
                false,
                $buttons['message_type'],
                $buttons['outbound_payload'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
        );
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{
     *     prompt: string,
     *     lead?: string,
     *     message_type?: string,
     *     outbound_payload?: array<string, mixed>,
     *     next_input?: string,
     *     state?: BookingFlowState|string,
     *     action?: string
     * }
     */
    private function promptForInput(Conversation $conversation, BookingRequest $booking, string $nextInput, array $slots): array
    {
        return match ($nextInput) {
            'passenger_count' => $this->promptPassengerCount($conversation),
            'travel_date' => $this->promptTravelDate($conversation),
            'travel_time' => $this->promptTravelTime($conversation),
            'selected_seats' => $this->promptSeatSelection($conversation, $booking, $slots),
            'pickup_location' => $this->promptPickupLocation($conversation),
            'pickup_full_address' => ['prompt' => $this->replyNaturalizer->askPickupAddress(
                $slots['pickup_location'] ?? null,
                $this->replySeed($conversation, 'pickup_full_address'),
            )],
            'destination' => $this->promptDestination($conversation, $slots),
            'passenger_name' => ['prompt' => $this->replyNaturalizer->askPassengerName(
                (int) ($slots['passenger_count'] ?? 1),
                $this->stateService->missingPassengerNames($slots),
                $this->replySeed($conversation, 'passenger_name'),
            )],
            'contact_number' => $this->promptContactNumber($conversation),
            default => ['prompt' => $this->replyNaturalizer->fallbackForState($slots['booking_intent_status'] ?? BookingFlowState::Idle->value)],
        };
    }

    /**
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptPassengerCount(Conversation $conversation): array
    {
        $interactive = $this->interactiveService->listMessage(
            $this->replyNaturalizer->askPassengerCount($this->replySeed($conversation, 'passenger_count')),
            'Pilih',
            [[
                'title' => 'Jumlah penumpang',
                'rows' => collect(range(1, 6))->map(fn (int $count): array => [
                    'id' => 'passenger_count_'.$count,
                    'title' => $count.' penumpang',
                    'description' => $count === 6 ? 'Perlu konfirmasi admin' : null,
                ])->all(),
            ]],
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    /**
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptTravelDate(Conversation $conversation): array
    {
        $interactive = $this->interactiveService->departureTimeMenu(
            $this->replyNaturalizer->askTravelDate($this->replySeed($conversation, 'travel_date')),
            (array) config('chatbot.jet.departure_slots', []),
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    /**
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptTravelTime(Conversation $conversation): array
    {
        $interactive = $this->interactiveService->departureTimeMenu(
            $this->replyNaturalizer->askTravelTime(),
            (array) config('chatbot.jet.departure_slots', []),
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{
     *     prompt: string,
     *     lead?: string,
     *     message_type: string,
     *     outbound_payload: array<string, mixed>,
     *     next_input?: string,
     *     state?: BookingFlowState|string,
     *     action?: string
     * }
     */
    private function promptSeatSelection(Conversation $conversation, BookingRequest $booking, array $slots): array
    {
        $selected = is_array($slots['selected_seats'] ?? null) ? $slots['selected_seats'] : [];
        $remaining = max(1, (int) ($slots['passenger_count'] ?? 1) - count($selected));
        $availability = $this->seatAvailability->availabilitySnapshot($booking, $remaining);
        $available = $availability['available_seats'];
        $this->stateService->putMany($conversation, ['seat_choices_available' => $available], 'seat_choices_available');

        if (($availability['available_count'] ?? 0) === 0) {
            return $this->alternativeTravelTimePrompt(
                $this->replyNaturalizer->seatUnavailableAtTime(
                    (string) $slots['travel_time'],
                    $availability['alternative_slots'] ?? [],
                ),
                $availability['alternative_slots'] ?? [],
            );
        }

        if (($availability['available_count'] ?? 0) < $remaining) {
            return $this->alternativeTravelTimePrompt(
                $this->replyNaturalizer->seatCapacityInsufficient(
                    (string) $slots['travel_time'],
                    max(1, (int) ($slots['passenger_count'] ?? 1)),
                    $available,
                    $availability['alternative_slots'] ?? [],
                ),
                $availability['alternative_slots'] ?? [],
            );
        }

        $rows = array_map(fn (string $seat): array => ['id' => 'seat_'.md5($seat), 'title' => $seat], $available);
        $interactive = count($rows) <= 3
            ? $this->interactiveService->buttonMessage($this->replyNaturalizer->askSeatSelection($remaining, $available, $selected), array_map(fn (array $row): array => ['id' => $row['id'], 'title' => $row['title']], $rows))
            : $this->interactiveService->listMessage($this->replyNaturalizer->askSeatSelection($remaining, $available, $selected), 'Pilih Seat', [['title' => 'Seat tersedia', 'rows' => $rows]]);

        return [
            'lead' => $this->replyNaturalizer->availableSeatsLine((string) $slots['travel_time'], $available),
            'prompt' => $interactive['text'],
            'message_type' => $interactive['message_type'],
            'outbound_payload' => $interactive['outbound_payload'],
        ];
    }

    /**
     * @param  array<int, array{id?: string, label?: string, time?: string, available_count?: int}>  $alternativeSlots
     * @return array{
     *     prompt: string,
     *     message_type: string,
     *     outbound_payload: array<string, mixed>,
     *     next_input: string,
     *     state: BookingFlowState,
     *     action: string
     * }
     */
    private function alternativeTravelTimePrompt(string $body, array $alternativeSlots): array
    {
        if ($alternativeSlots === []) {
            return [
                'prompt' => $body,
                'message_type' => 'text',
                'outbound_payload' => [],
                'next_input' => 'travel_time',
                'state' => BookingFlowState::AskingDepartureTime,
                'action' => 'collect_travel_time',
            ];
        }

        $interactive = $this->interactiveService->departureTimeMenu($body, array_map(
            fn (array $slot): array => [
                'id' => (string) ($slot['id'] ?? ($slot['time'] ?? '')),
                'label' => (string) ($slot['label'] ?? ($slot['time'] ?? '')),
                'time' => (string) ($slot['time'] ?? ''),
            ],
            $alternativeSlots,
        ));

        return [
            'prompt' => $interactive['text'],
            'message_type' => $interactive['message_type'],
            'outbound_payload' => $interactive['outbound_payload'],
            'next_input' => 'travel_time',
            'state' => BookingFlowState::AskingDepartureTime,
            'action' => 'collect_travel_time',
        ];
    }

    /**
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptPickupLocation(Conversation $conversation): array
    {
        $interactive = $this->interactiveService->pickupLocationMenu(
            $this->replyNaturalizer->askPickupLocation($this->replySeed($conversation, 'pickup_location')),
            $this->routeValidator->menuLocations(),
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptDestination(Conversation $conversation, array $slots): array
    {
        $options = $this->routeValidator->supportedDestinations($slots['pickup_location'] ?? null);
        if ($options === []) {
            $options = $this->routeValidator->menuLocations();
        }

        $interactive = $this->interactiveService->dropoffLocationMenu(
            $this->replyNaturalizer->askDestination($slots['pickup_location'] ?? null),
            $options,
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    /**
     * @return array{prompt: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function promptContactNumber(Conversation $conversation): array
    {
        $interactive = $this->interactiveService->buttonMessage(
            $this->replyNaturalizer->askContactNumber(),
            [
                ['id' => 'contact_same', 'title' => 'Sama'],
                ['id' => 'contact_diff', 'title' => 'Berbeda'],
            ],
        );

        return ['prompt' => $interactive['text'], 'message_type' => $interactive['message_type'], 'outbound_payload' => $interactive['outbound_payload']];
    }

    private function stateForExpectedInput(string $expectedInput): BookingFlowState
    {
        return match ($expectedInput) {
            'passenger_count' => BookingFlowState::AskingPassengerCount,
            'travel_date' => BookingFlowState::AskingDepartureDate,
            'travel_time' => BookingFlowState::AskingDepartureTime,
            'selected_seats' => BookingFlowState::ShowingAvailableSeats,
            'pickup_location' => BookingFlowState::AskingPickupPoint,
            'pickup_full_address' => BookingFlowState::AskingPickupAddress,
            'destination' => BookingFlowState::AskingDropoffPoint,
            'passenger_name' => BookingFlowState::AskingPassengerNames,
            'contact_number' => BookingFlowState::AskingContactConfirmation,
            default => BookingFlowState::Idle,
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function withGreetingContext(array $signals, string $messageText, string $text): string
    {
        return ($signals['salam_type'] ?? null) === 'islamic'
            ? $this->greetingService->prependIslamicGreeting($messageText, $text)
            : $text;
    }

    private function replySeed(Conversation $conversation, string $context): string
    {
        $latestInboundId = $conversation->latestInboundMessage()?->id ?? 'na';
        $expectedInput = $this->stateService->expectedInput($conversation) ?? 'none';

        return implode('|', [
            (string) $conversation->id,
            (string) $latestInboundId,
            $expectedInput,
            $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $signals
     */
    private function hasBookingContext(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $slots,
        array $updates,
        array $signals,
    ): bool {
        if ($booking !== null) {
            return true;
        }

        $flowState = $this->stateService->normalizeFlowState(
            (string) ($slots['booking_intent_status'] ?? BookingFlowState::Idle->value),
            $slots,
        );
        $preserveHistoricalSlots = ! in_array($flowState, [
            BookingFlowState::Completed->value,
            BookingFlowState::WaitingAdminTakeover->value,
        ], true);

        if ($preserveHistoricalSlots) {
            foreach (['pickup_location', 'pickup_full_address', 'destination', 'passenger_name', 'passenger_count', 'travel_date', 'travel_time', 'selected_seats', 'contact_number'] as $slot) {
                if (filled($slots[$slot] ?? null)) {
                    return true;
                }
            }
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

        return in_array($intent, [
                IntentType::Booking,
                IntentType::BookingCancel,
                IntentType::BookingConfirm,
                IntentType::KonfirmasiBooking,
                IntentType::UbahDataBooking,
                IntentType::Confirmation,
                IntentType::Rejection,
            ], true)
            || ($signals['booking_keyword'] ?? false)
            || $this->hasBookingCandidateUpdates($updates)
            || (
                $preserveHistoricalSlots
                && (($slots['booking_intent_status'] ?? BookingFlowState::Idle->value) !== BookingFlowState::Idle->value)
            );
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function hasBookingCandidateUpdates(array $updates): bool
    {
        $keys = array_keys($updates);

        if (array_intersect($keys, [
            'passenger_count',
            'selected_seats',
            'pickup_full_address',
            'passenger_name',
            'passenger_names',
            'contact_number',
        ]) !== []) {
            return true;
        }

        if (
            in_array('travel_date', $keys, true)
            && in_array('travel_time', $keys, true)
        ) {
            return true;
        }

        return in_array('pickup_location', $keys, true)
            && in_array('destination', $keys, true);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $replyResult
     */
    private function shouldEscalateWithoutAutoReply(array $intentResult, array $replyResult): bool
    {
        if (($replyResult['is_fallback'] ?? false) === true) {
            return true;
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return true;
        }

        return in_array((string) ($intentResult['intent'] ?? ''), [
            IntentType::Unknown->value,
            IntentType::OutOfScope->value,
            IntentType::Support->value,
            IntentType::HumanHandoff->value,
            IntentType::PertanyaanTidakTerjawab->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isRouteListInquiry(array $signals, string $messageText): bool
    {
        return ($signals['route_keyword'] ?? false)
            && (bool) preg_match('/\b(lokasi jemput|titik jemput|rute|trayek|tujuan tersedia|antar ke mana)\b/u', mb_strtolower($messageText, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function overrideIntent(array $intentResult, IntentType $intent): array
    {
        $intentResult['intent'] = $intent->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0), 0.95);

        return $intentResult;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $outboundPayload
     * @return array<string, mixed>
     */
    private function reply(
        string $text,
        array $meta,
        bool $isFallback = false,
        string $messageType = 'text',
        array $outboundPayload = [],
    ): array {
        return [
            'text' => $text,
            'is_fallback' => $isFallback,
            'message_type' => $messageType,
            'outbound_payload' => $outboundPayload,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function decision(?BookingRequest $booking, string $action, array $reply, array $intentResult): array
    {
        return [
            'handled' => true,
            'booking' => $booking,
            'booking_decision' => [
                'action' => $action,
                'booking_status' => $booking?->booking_status?->value ?? BookingStatus::Draft->value,
            ],
            'reply' => $reply,
            'intent_result' => $intentResult,
        ];
    }
}
