<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\GreetingService;
use App\Services\Chatbot\HumanEscalationService;
use App\Support\WaLog;

class BookingFlowStateMachine
{
    /**
     * @var array<int, string>
     */
    private const CORE_REQUIRED_SLOTS = [
        'pickup_location',
        'destination',
        'passenger_name',
        'passenger_count',
    ];

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
        private readonly ?BookingReplyNaturalizerService $replyNaturalizer = null,
    ) {}

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult
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
        array $replyResult,
    ): array {
        $booking = $this->bookingAssistant->findExistingDraft($conversation);
        $slots = $this->stateService->hydrateFromBooking($conversation, $booking);
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

        $this->logExtraction($conversation, $messageText, $expectedInput, $updates, $signals);

        if ($signals['greeting_detected']) {
            $this->stateService->putMany($conversation, [
                'greeting_detected' => true,
                'salam_type' => $signals['salam_type'],
            ], 'greeting_signal');
        }

        if ($signals['human_keyword']) {
            return $this->escalateToHuman($conversation, $customer, $intentResult, $signals, $messageText, $booking);
        }

        $hasBookingContext = $this->hasBookingContext(
            conversation: $conversation,
            booking: $booking,
            intentResult: $intentResult,
            slots: $slots,
            updates: $updates,
            signals: $signals,
        );

        if ($signals['greeting_only'] && ! $hasBookingContext) {
            $opening = $this->greetingService->buildOpeningGreeting($conversation, $messageText, $slots)
                ?? $timeGreeting['opening'];

            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::Idle,
                null,
                'greeting_only',
            );

            return $this->decision(
                booking: $booking,
                action: 'greeting',
                reply: $this->reply(
                    text: $opening,
                    meta: ['source' => 'booking_engine', 'action' => 'greeting'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Greeting),
            );
        }

        if ($booking === null && $updates === []) {
            if ($this->isRouteListInquiry($signals, $messageText)) {
                return $this->decision(
                    booking: null,
                    action: 'route_list',
                    reply: $this->reply(
                        text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->routeListReply()),
                        meta: ['source' => 'booking_engine', 'action' => 'route_list'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::LocationInquiry),
                );
            }

            if ($signals['schedule_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'schedule_inquiry',
                    reply: $this->reply(
                        text: $this->withGreetingContext(
                            $signals,
                            $messageText,
                            $this->naturalizer()->scheduleLine()."\n\nKalau ingin saya cek lebih lanjut, silakan kirim titik jemput dan tujuan perjalanannya ya.",
                        ),
                        meta: ['source' => 'booking_engine', 'action' => 'schedule_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::ScheduleInquiry),
                );
            }

            if ($signals['price_keyword']) {
                return $this->decision(
                    booking: null,
                    action: 'price_inquiry',
                    reply: $this->reply(
                        text: $this->withGreetingContext(
                            $signals,
                            $messageText,
                            'Baik, untuk cek harga saya perlu titik jemput dan tujuan dulu ya.',
                        ),
                        meta: ['source' => 'booking_engine', 'action' => 'price_inquiry'],
                    ),
                    intentResult: $this->overrideIntent($intentResult, IntentType::PriceInquiry),
                );
            }
        }

        if (! $hasBookingContext) {
            if ($signals['close_intent']) {
                return $this->closeConversation($conversation, $booking, $intentResult, $signals, $messageText);
            }

            return $this->decision(
                booking: null,
                action: 'pass_through',
                reply: $this->passThroughReply($replyResult, $signals, $messageText, $slots),
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
        $correctionLines = [];
        $capturedSlotUpdates = [];

        if ($updates !== []) {
            $changes = $this->stateService->putMany($conversation, array_merge($updates, [
                'needs_human_escalation' => false,
                'admin_takeover' => false,
            ]), 'slot_extraction');

            $trackedSlotChanges = $this->stateService->trackedSlotChanges($changes);
            $correctionLines = $this->naturalizer()->correctionLinesFromChanges($trackedSlotChanges['overwritten']);
            $capturedSlotUpdates = array_map(
                fn (array $change): mixed => $change['new'],
                $trackedSlotChanges['created'],
            );

            $slots = $this->stateService->load($conversation);
        }

        $routeEvaluation = $this->evaluateRoute($slots);

        $this->stateService->putMany($conversation, [
            'route_status' => $routeEvaluation['status'],
            'route_issue' => $routeEvaluation['focus_slot'],
            'fare_amount' => $routeEvaluation['fare_amount'],
        ], 'route_evaluation');
        $slots = $this->stateService->load($conversation);
        $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');
        $currentState = $this->determineFlowState($slots);

        if (($slots['review_sent'] ?? false) === true && ($signals['affirmation'] ?? false) === true && $updates === []) {
            $this->confirmationService->confirm($booking);
            $this->stateService->putMany($conversation, [
                'booking_confirmed' => true,
                'review_sent' => true,
            ], 'booking_confirmation');
            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::Confirmed,
                null,
                'booking_confirmation',
                ['reason' => 'customer_affirmed_review'],
            );
            $this->humanEscalationService->forwardBooking($conversation, $customer, $booking->fresh());

            WaLog::info('[BookingFlow] booking confirmed', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return $this->decision(
                booking: $booking->fresh(),
                action: 'confirmed',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->confirmed()),
                    meta: ['source' => 'booking_engine', 'action' => 'confirmed'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::BookingConfirm),
            );
        }

        if (($slots['review_sent'] ?? false) === true && ($signals['rejection'] ?? false) === true && $updates === []) {
            $this->stateService->putMany($conversation, [
                'review_sent' => false,
                'booking_confirmed' => false,
            ], 'booking_rejection');
            $slots = $this->stateService->load($conversation);
            $currentState = $this->determineFlowState($slots);
            $this->stateService->transitionFlowState(
                $conversation,
                $currentState,
                $currentState === BookingFlowState::RouteUnavailable
                    ? ($slots['route_issue'] ?? 'pickup_location')
                    : null,
                'booking_rejection',
                ['reason' => 'customer_rejected_review'],
            );

            return $this->decision(
                booking: $booking,
                action: 'ask_correction',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->askCorrection()),
                    meta: ['source' => 'booking_engine', 'action' => 'ask_correction'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($this->shouldCloseConversation($signals, $slots, $updates)) {
            return $this->closeConversation($conversation, $booking, $intentResult, $signals, $messageText);
        }

        if (($signals['acknowledgement'] ?? false) === true && $updates === [] && $currentState->isCollecting()) {
            $pendingPrompt = $this->pendingPrompt($slots, $currentState);

            if ($pendingPrompt !== null) {
                return $this->decision(
                    booking: $booking,
                    action: 'acknowledge_pending',
                    reply: $this->reply(
                        text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->inProgressAcknowledgement($pendingPrompt)),
                        meta: ['source' => 'booking_engine', 'action' => 'acknowledge_pending'],
                    ),
                    intentResult: $intentResult,
                );
            }
        }

        if ($updates === [] && $this->shouldUseStateFallback($signals, $currentState, $slots)) {
            $pendingPrompt = $currentState->isCollecting()
                ? $this->pendingPrompt($slots, $currentState)
                : null;

            return $this->decision(
                booking: $booking,
                action: 'state_fallback',
                reply: $this->reply(
                    text: $this->withGreetingContext(
                        $signals,
                        $messageText,
                        $this->naturalizer()->fallbackForState(
                            state: $currentState->value,
                            slots: $slots,
                            pendingPrompt: $pendingPrompt,
                            signals: $signals,
                            routeIssue: $routeEvaluation['focus_slot'] ?? $slots['route_issue'] ?? null,
                            routeSuggestions: $routeEvaluation['suggestions'],
                        ),
                    ),
                    meta: ['source' => 'booking_engine', 'action' => 'state_fallback'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($currentState === BookingFlowState::RouteUnavailable) {
            $focusSlot = $routeEvaluation['focus_slot'] ?? $slots['route_issue'] ?? 'pickup_location';
            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::RouteUnavailable,
                $focusSlot,
                'unsupported_route',
                ['route_status' => $routeEvaluation['status']],
            );

            $replyText = $this->naturalizer()->naturalizeUnsupportedRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                unsupportedReply: $this->naturalizer()->unsupportedRouteReply(
                    pickup: $slots['pickup_location'] ?? null,
                    destination: $slots['destination'] ?? null,
                    suggestions: $routeEvaluation['suggestions'],
                    focusSlot: $focusSlot,
                ),
            );

            return $this->decision(
                booking: $booking,
                action: 'unsupported_route',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => 'unsupported_route',
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::LocationInquiry),
            );
        }

        $coreMissing = $this->missingSlots($slots, self::CORE_REQUIRED_SLOTS);

        if ($currentState === BookingFlowState::CollectingRoute || $currentState === BookingFlowState::CollectingPassenger) {
            $source = $currentState === BookingFlowState::CollectingRoute
                ? 'collecting_route'
                : 'collecting_passenger';
            $this->stateService->transitionFlowState(
                $conversation,
                $currentState,
                $coreMissing[0] ?? 'pickup_location',
                $source,
                ['missing_slots' => $coreMissing],
            );

            $facts = $this->contextFacts($signals, $slots);
            $replyText = $this->naturalizer()->naturalizeRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                prompt: $this->naturalizer()->askBasicDetails($coreMissing, $slots),
                routeLine: $facts['route_line'],
                priceLine: $facts['price_line'],
                scheduleLine: $facts['schedule_line'],
            );

            return $this->decision(
                booking: $booking,
                action: $currentState === BookingFlowState::CollectingRoute
                    ? 'collect_route'
                    : 'collect_passenger',
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => $currentState === BookingFlowState::CollectingRoute
                            ? 'collect_route'
                            : 'collect_passenger',
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ($currentState === BookingFlowState::CollectingSchedule) {
            $schedulePrompt = $this->schedulePrompt($slots, $signals);

            $this->stateService->transitionFlowState(
                $conversation,
                BookingFlowState::CollectingSchedule,
                $schedulePrompt['expected_input'],
                'collecting_schedule',
                ['missing_slot' => $schedulePrompt['expected_input']],
            );

            $facts = $this->contextFacts($signals, $slots);
            $replyText = $this->naturalizer()->naturalizeRuleReply(
                capturedUpdates: $capturedSlotUpdates,
                correctionLines: $correctionLines,
                prompt: $schedulePrompt['prompt'],
                routeLine: $facts['route_line'],
                priceLine: $facts['price_line'],
                scheduleLine: $facts['schedule_line'],
            );

            return $this->decision(
                booking: $booking,
                action: $schedulePrompt['action'],
                reply: $this->reply(
                    text: $this->withGreetingContext($signals, $messageText, $replyText),
                    meta: [
                        'source' => 'booking_engine',
                        'action' => $schedulePrompt['action'],
                        'has_booking_update' => $updates !== [],
                    ],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        $this->confirmationService->requestConfirmation($booking);
        $this->stateService->putMany($conversation, [
            'review_sent' => true,
            'booking_confirmed' => false,
        ], 'ready_to_confirm');
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::ReadyToConfirm,
            null,
            'ready_to_confirm',
            ['reason' => 'all_required_slots_collected'],
        );

        return $this->decision(
            booking: $booking->fresh(),
            action: 'ask_confirmation',
            reply: $this->reply(
                text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->reviewSummary($booking->fresh())),
                meta: ['source' => 'booking_engine', 'action' => 'ask_confirmation'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
        );
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function passThroughReply(array $replyResult, array $signals, string $messageText, array $slots): array
    {
        $text = trim((string) ($replyResult['text'] ?? ''));

        if ($text === '' || ($replyResult['is_fallback'] ?? false) === true) {
            $text = $this->naturalizer()->fallbackForState(
                state: (string) ($slots['booking_intent_status'] ?? BookingFlowState::Idle->value),
                slots: $slots,
                signals: $signals,
            );
        }

        return $this->reply(
            text: $this->withGreetingContext($signals, $messageText, $text),
            meta: ['source' => 'ai_reply', 'action' => 'pass_through'],
            isFallback: (bool) ($replyResult['is_fallback'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function escalateToHuman(
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
        ], 'human_handoff');
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::Closed,
            null,
            'human_handoff',
            ['reason' => 'customer_requested_admin'],
        );

        $this->humanEscalationService->escalateQuestion(
            conversation: $conversation,
            customer: $customer,
            reason: 'Permintaan admin manusia dari customer.',
        );

        return $this->decision(
            booking: $booking,
            action: 'human_handoff',
            reply: $this->reply(
                text: $this->withGreetingContext(
                    $signals,
                    $messageText,
                    'Baik, saya bantu teruskan ke admin ya. Mohon tunggu sebentar.',
                ),
                meta: ['source' => 'booking_engine', 'action' => 'human_handoff'],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::HumanHandoff),
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function closeConversation(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $signals,
        string $messageText,
    ): array {
        $this->stateService->transitionFlowState(
            $conversation,
            BookingFlowState::Closed,
            null,
            'conversation_closed',
            ['reason' => 'customer_close_intent'],
        );

        return $this->decision(
            booking: $booking,
            action: 'close_conversation',
            reply: $this->reply(
                text: $this->withGreetingContext($signals, $messageText, $this->naturalizer()->closing()),
                meta: ['source' => 'booking_engine', 'action' => 'close_conversation', 'close_conversation' => true],
            ),
            intentResult: $this->overrideIntent($intentResult, IntentType::Farewell),
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function withGreetingContext(array $signals, string $messageText, string $text): string
    {
        if (($signals['salam_type'] ?? null) !== 'islamic') {
            return $text;
        }

        return $this->greetingService->prependIslamicGreeting($messageText, $text);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @return array{route_line: string|null, price_line: string|null, schedule_line: string|null}
     */
    private function contextFacts(array $signals, array $slots): array
    {
        return [
            'route_line' => ($slots['route_status'] ?? null) === 'supported'
                ? $this->naturalizer()->routeAvailableLine(
                    $slots['pickup_location'] ?? null,
                    $slots['destination'] ?? null,
                )
                : null,
            'price_line' => ($signals['price_keyword'] ?? false) === true
                ? $this->naturalizer()->priceLine(
                    pickup: $slots['pickup_location'] ?? null,
                    destination: $slots['destination'] ?? null,
                    passengerCount: $slots['passenger_count'] ?? null,
                )
                : null,
            'schedule_line' => ($signals['schedule_keyword'] ?? false) === true
                ? $this->naturalizer()->scheduleLine()
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<int, string>
     */
    private function missingSlots(array $slots, array $requiredSlots): array
    {
        return array_values(array_filter(
            $requiredSlots,
            fn (string $slot) => blank($slots[$slot] ?? null),
        ));
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     */
    private function shouldCloseConversation(array $signals, array $slots, array $updates): bool
    {
        if (($signals['close_intent'] ?? false) !== true || $updates !== []) {
            return false;
        }

        if (($signals['affirmation'] ?? false) === true && ($slots['review_sent'] ?? false) === true) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     */
    private function shouldUseStateFallback(array $signals, BookingFlowState $state, array $slots): bool
    {
        if (($signals['close_intent'] ?? false) === true) {
            return false;
        }

        if (($signals['affirmation'] ?? false) === true || ($signals['rejection'] ?? false) === true) {
            return false;
        }

        if (($signals['human_keyword'] ?? false) === true) {
            return false;
        }

        if ($state === BookingFlowState::ReadyToConfirm) {
            return ($slots['review_sent'] ?? false) === true;
        }

        return in_array($state, [
            BookingFlowState::CollectingRoute,
            BookingFlowState::CollectingPassenger,
            BookingFlowState::CollectingSchedule,
            BookingFlowState::RouteUnavailable,
            BookingFlowState::Confirmed,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function pendingPrompt(array $slots, BookingFlowState $state): ?string
    {
        if ($state === BookingFlowState::RouteUnavailable) {
            $focusSlot = $slots['route_issue'] ?? 'pickup_location';

            return $this->naturalizer()->askBasicDetails([$focusSlot], $slots);
        }

        $coreMissing = $this->missingSlots($slots, self::CORE_REQUIRED_SLOTS);

        if ($coreMissing !== []) {
            return $this->naturalizer()->askBasicDetails($coreMissing, $slots);
        }

        if (($slots['travel_date'] ?? null) === null) {
            return $this->naturalizer()->askTravelDate();
        }

        if (($slots['travel_time'] ?? null) === null) {
            return $this->naturalizer()->askTravelTime();
        }

        if (($slots['payment_method'] ?? null) === null) {
            return $this->naturalizer()->askPaymentMethod();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $signals
     * @return array{action: string, expected_input: string, prompt: string}
     */
    private function schedulePrompt(array $slots, array $signals): array
    {
        if (($slots['travel_date'] ?? null) === null) {
            return [
                'action' => 'ask_travel_date',
                'expected_input' => 'travel_date',
                'prompt' => $this->naturalizer()->askTravelDate(),
            ];
        }

        if (($slots['travel_time'] ?? null) === null) {
            return [
                'action' => 'ask_travel_time',
                'expected_input' => 'travel_time',
                'prompt' => $this->naturalizer()->askTravelTime((bool) ($signals['time_ambiguous'] ?? false)),
            ];
        }

        return [
            'action' => 'ask_payment_method',
            'expected_input' => 'payment_method',
            'prompt' => $this->naturalizer()->askPaymentMethod(),
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function determineFlowState(array $slots): BookingFlowState
    {
        if (($slots['booking_confirmed'] ?? false) === true) {
            return BookingFlowState::Confirmed;
        }

        if (($slots['route_status'] ?? null) === 'unsupported') {
            return BookingFlowState::RouteUnavailable;
        }

        if (blank($slots['pickup_location'] ?? null) || blank($slots['destination'] ?? null)) {
            return BookingFlowState::CollectingRoute;
        }

        if (blank($slots['passenger_name'] ?? null) || blank($slots['passenger_count'] ?? null)) {
            return BookingFlowState::CollectingPassenger;
        }

        if (
            blank($slots['travel_date'] ?? null)
            || blank($slots['travel_time'] ?? null)
            || blank($slots['payment_method'] ?? null)
        ) {
            return BookingFlowState::CollectingSchedule;
        }

        return BookingFlowState::ReadyToConfirm;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array{
     *     status: string|null,
     *     focus_slot: string|null,
     *     suggestions: array<int, string>,
     *     fare_amount: int|null
     * }
     */
    private function evaluateRoute(array $slots): array
    {
        $pickup = $slots['pickup_location'] ?? null;
        $destination = $slots['destination'] ?? null;

        if (blank($pickup) || blank($destination)) {
            return [
                'status' => null,
                'focus_slot' => null,
                'suggestions' => [],
                'fare_amount' => null,
            ];
        }

        $fareAmount = $this->fareCalculator->calculate(
            $pickup,
            $destination,
            (int) ($slots['passenger_count'] ?? 1),
        );

        if ($fareAmount !== null) {
            return [
                'status' => 'supported',
                'focus_slot' => null,
                'suggestions' => [],
                'fare_amount' => $fareAmount,
            ];
        }

        $pickupKnown = $this->routeValidator->isKnownLocation($pickup);
        $destinationKnown = $this->routeValidator->isKnownLocation($destination);

        if ($destinationKnown && ! $pickupKnown) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'pickup_location',
                'suggestions' => array_slice($this->routeValidator->supportedPickupsForDestination($destination), 0, 6),
                'fare_amount' => null,
            ];
        }

        if ($pickupKnown && ! $destinationKnown) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'destination',
                'suggestions' => array_slice($this->routeValidator->supportedDestinations($pickup), 0, 6),
                'fare_amount' => null,
            ];
        }

        $destinationSuggestions = array_slice($this->routeValidator->supportedDestinations($pickup), 0, 6);

        if ($destinationSuggestions !== []) {
            return [
                'status' => 'unsupported',
                'focus_slot' => 'destination',
                'suggestions' => $destinationSuggestions,
                'fare_amount' => null,
            ];
        }

        return [
            'status' => 'unsupported',
            'focus_slot' => 'pickup_location',
            'suggestions' => array_slice($this->routeValidator->supportedPickupsForDestination($destination), 0, 6),
            'fare_amount' => null,
        ];
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
        $currentState = BookingFlowState::from(
            $this->stateService->normalizeFlowState((string) ($slots['booking_intent_status'] ?? null), $slots),
        );

        if ($currentState === BookingFlowState::Closed) {
            $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

            return $updates !== []
                || $intent?->isBookingRelated() === true
                || in_array($intent, [IntentType::LocationInquiry, IntentType::PriceInquiry, IntentType::ScheduleInquiry], true)
                || ($signals['booking_keyword'] ?? false)
                || ($signals['schedule_keyword'] ?? false)
                || ($signals['price_keyword'] ?? false)
                || ($signals['route_keyword'] ?? false)
                || ($signals['affirmation'] ?? false)
                || ($signals['rejection'] ?? false);
        }

        if ($booking !== null) {
            return true;
        }

        if ($currentState !== BookingFlowState::Idle) {
            return true;
        }

        foreach ([
            'pickup_location',
            'destination',
            'passenger_name',
            'passenger_count',
            'travel_date',
            'travel_time',
            'payment_method',
        ] as $slot) {
            if (filled($slots[$slot] ?? null)) {
                return true;
            }
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

        if ($intent !== null && $intent->isBookingRelated()) {
            return true;
        }

        if (in_array($intent, [IntentType::LocationInquiry, IntentType::PriceInquiry, IntentType::ScheduleInquiry], true)) {
            return true;
        }

        if (
            ($signals['booking_keyword'] ?? false)
            || ($signals['schedule_keyword'] ?? false)
            || ($signals['price_keyword'] ?? false)
            || ($signals['route_keyword'] ?? false)
        ) {
            return true;
        }

        if ($updates !== []) {
            return true;
        }

        if (filled($conversation->summary)) {
            return true;
        }

        return filled($conversation->current_intent)
            && ! in_array($conversation->current_intent, ['greeting', 'farewell', 'unknown'], true);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isRouteListInquiry(array $signals, string $messageText): bool
    {
        if (($signals['route_keyword'] ?? false) !== true) {
            return false;
        }

        return (bool) preg_match('/\b(lokasi jemput|titik jemput|rute|trayek|tujuan tersedia|antar ke mana)\b/u', mb_strtolower($messageText, 'UTF-8'));
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

    /**
     * @param  array<string, mixed>  $updates
     * @param  array<string, mixed>  $signals
     */
    private function logExtraction(
        Conversation $conversation,
        string $messageText,
        ?string $expectedInput,
        array $updates,
        array $signals,
    ): void {
        WaLog::debug('[BookingFlow] extractor result', [
            'conversation_id' => $conversation->id,
            'expected_input' => $expectedInput,
            'message_preview' => mb_substr($messageText, 0, 120),
            'updates' => $updates,
            'signals' => [
                'booking_keyword' => $signals['booking_keyword'] ?? false,
                'schedule_keyword' => $signals['schedule_keyword'] ?? false,
                'price_keyword' => $signals['price_keyword'] ?? false,
                'route_keyword' => $signals['route_keyword'] ?? false,
                'affirmation' => $signals['affirmation'] ?? false,
                'rejection' => $signals['rejection'] ?? false,
                'close_intent' => $signals['close_intent'] ?? false,
                'time_ambiguous' => $signals['time_ambiguous'] ?? false,
            ],
        ]);
    }

    private function naturalizer(): BookingReplyNaturalizerService
    {
        return $this->replyNaturalizer
            ?? new BookingReplyNaturalizerService($this->fareCalculator, $this->routeValidator);
    }
}
