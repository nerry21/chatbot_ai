<?php

namespace App\Services\Chatbot;

use App\Data\Chatbot\ConversationContextMessage;
use App\Data\Chatbot\ConversationContextPayload;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Booking\BookingConversationStateService;
use App\Services\CRM\CRMContextService;
use App\Support\WaLog;

class ConversationContextLoaderService
{
    private const HISTORY_FETCH_LIMIT = 12;

    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly BookingConversationStateService $bookingStateService,
        private readonly CustomerMemoryService $customerMemoryService,
        private readonly ConversationMemoryResolverService $memoryResolver,
        private readonly ConversationContextSummaryService $summaryService,
        private readonly CRMContextService $crmContextService,
    ) {}

    public function load(Conversation $conversation, ConversationMessage $message): ConversationContextPayload
    {
        $conversation->loadMissing('customer');

        $jobTraceId = $this->buildJobTraceId($conversation, $message);

        $historyWindow = $this->historyWindow();
        $historyMessages = $this->loadHistoryMessages($conversation, $message->id);
        $recentMessages = array_values(array_slice($historyMessages, -$historyWindow));
        $omittedMessageCount = max(0, count($historyMessages) - count($recentMessages));

        $activeStates = $this->stateService->allActive($conversation);
        $bookingSnapshot = $this->bookingStateService->load($conversation);
        $draftBooking = $this->findActiveDraft($conversation);

        $knownEntities = $this->buildKnownEntities(
            bookingSnapshot: $bookingSnapshot,
            draftBooking: $draftBooking,
        );

        $conversationState = $this->buildConversationState(
            conversation: $conversation,
            activeStates: $activeStates,
            bookingSnapshot: $bookingSnapshot,
            draftBooking: $draftBooking,
        );

        $crmContext = $conversation->customer !== null
            ? $this->crmContextService->build(
                customer: $conversation->customer,
                conversation: $conversation,
                booking: $draftBooking,
            )
            : [];

        $latestMessageText = trim((string) ($message->message_text ?? ''));
        $resolvedContext = $this->memoryResolver->resolve(
            conversation: $conversation,
            latestMessageText: $latestMessageText,
            historyMessages: $historyMessages,
            conversationState: $conversationState,
            knownEntities: $knownEntities,
        );

        $conversationSummary = $this->summaryService->summarize(
            storedSummary: $conversation->summary,
            resolvedContext: $resolvedContext,
            omittedMessageCount: $omittedMessageCount,
            adminTakeover: $conversation->isAdminTakeover(),
        );

        $crmHints = $this->buildCrmHints(
            $this->crmContextForUnderstanding($crmContext)
        );

        WaLog::debug('[ConversationContextLoader] Context built', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'job_trace_id' => $jobTraceId,
            'recent_message_count' => count($recentMessages),
            'omitted_message_count' => $omittedMessageCount,
            'known_entity_keys' => array_keys($knownEntities),
            'conversation_state_keys' => array_keys($conversationState),
            'crm_hint_keys' => array_keys($crmHints),
            'admin_takeover' => $conversation->isAdminTakeover(),
        ]);

        return new ConversationContextPayload(
            conversationId: $conversation->id,
            messageId: $message->id,
            latestMessageText: $latestMessageText,
            recentMessages: $recentMessages,
            conversationState: $conversationState,
            knownEntities: $knownEntities,
            resolvedContext: $resolvedContext,
            conversationSummary: $conversationSummary,
            customerMemory: $conversation->customer !== null
                ? $this->customerMemoryService->buildMemory($conversation->customer)
                : [],
            crmContext: $crmContext,
            adminTakeover: $conversation->isAdminTakeover(),
            crmHints: array_merge($crmHints, [
                '_meta' => [
                    'source' => 'ConversationContextLoaderService',
                    'job_trace_id' => $jobTraceId,
                    'recent_message_count' => count($recentMessages),
                    'omitted_message_count' => $omittedMessageCount,
                ],
            ]),
            jobTraceId: $jobTraceId,
        );
    }

    /**
     * @return array<int, ConversationContextMessage>
     */
    private function loadHistoryMessages(Conversation $conversation, int $excludeMessageId): array
    {
        $historyMaxAgeMinutes = (int) config('chatbot.memory.history_max_age_minutes', 180);
        $cutoff = now()->subMinutes(max(30, $historyMaxAgeMinutes));

        return $conversation->messages()
            ->where('id', '!=', $excludeMessageId)
            ->whereNotNull('message_text')
            ->where('sent_at', '>=', $cutoff)
            ->orderByDesc('sent_at')
            ->limit(self::HISTORY_FETCH_LIMIT)
            ->get(['direction', 'sender_type', 'message_text', 'sent_at'])
            ->reverse()
            ->map(function (ConversationMessage $message): ?ConversationContextMessage {
                $text = trim((string) ($message->message_text ?? ''));

                if ($text === '') {
                    return null;
                }

                return new ConversationContextMessage(
                    role: $this->roleFromSender($message->sender_type),
                    direction: $message->direction->value,
                    text: $text,
                    sentAt: $message->sent_at?->toDateTimeString(),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $bookingSnapshot
     * @return array<string, mixed>
     */
    private function buildKnownEntities(array $bookingSnapshot, ?BookingRequest $draftBooking): array
    {
        $passengerName = $this->normalizeText($bookingSnapshot['passenger_name'] ?? null);

        if ($passengerName === null && $draftBooking !== null) {
            $passengerName = $draftBooking->passenger_name ?? ($draftBooking->passengerNamesList()[0] ?? null);
        }

        $selectedSeats = $bookingSnapshot['selected_seats'] ?? ($draftBooking?->selected_seats ?? []);
        $seatNumber = is_array($selectedSeats) && $selectedSeats !== []
            ? implode(', ', array_values(array_filter(array_map(
                fn (mixed $seat): ?string => $this->normalizeText($seat),
                $selectedSeats,
            ))))
            : null;

        return array_filter([
            'origin' => $this->normalizeText($bookingSnapshot['pickup_location'] ?? $draftBooking?->pickup_location),
            'destination' => $this->normalizeText($bookingSnapshot['destination'] ?? $draftBooking?->destination),
            'travel_date' => $this->normalizeText(
                $bookingSnapshot['travel_date']
                    ?? $draftBooking?->departure_date?->toDateString(),
            ),
            'departure_time' => $this->normalizeText($bookingSnapshot['travel_time'] ?? $draftBooking?->departure_time),
            'passenger_count' => $this->normalizePositiveInt($bookingSnapshot['passenger_count'] ?? $draftBooking?->passenger_count),
            'passenger_name' => $this->normalizeText($passengerName),
            'seat_number' => $seatNumber !== '' ? $seatNumber : null,
            'payment_method' => $this->normalizeText($bookingSnapshot['payment_method'] ?? $draftBooking?->payment_method),
        ], static fn (mixed $value): bool => match (true) {
            is_int($value) => $value > 0,
            is_string($value) => $value !== '',
            default => $value !== null,
        });
    }

    /**
     * @param  array<string, mixed>  $activeStates
     * @param  array<string, mixed>  $bookingSnapshot
     * @return array<string, mixed>
     */
    private function buildConversationState(
        Conversation $conversation,
        array $activeStates,
        array $bookingSnapshot,
        ?BookingRequest $draftBooking,
    ): array {
        return array_filter([
            'conversation_status' => $conversation->status->value,
            'current_intent' => $this->normalizeText($conversation->current_intent),

            'booking_intent_status' => $this->normalizeText($bookingSnapshot['booking_intent_status'] ?? null),
            'booking_expected_input' => $this->normalizeText(
                $activeStates[BookingConversationStateService::EXPECTED_INPUT_KEY]
                    ?? $activeStates['booking_expected_input']
                    ?? null,
            ),
            'active_booking_status' => $draftBooking?->booking_status?->value,

            'route_status' => $this->normalizeText($bookingSnapshot['route_status'] ?? null),
            'route_issue' => $this->normalizeText($bookingSnapshot['route_issue'] ?? null),

            'waiting_for' => $this->normalizeText($activeStates['waiting_for'] ?? null),
            'waiting_reason' => $this->normalizeText($activeStates['waiting_reason'] ?? null),

            'review_sent' => (bool) ($bookingSnapshot['review_sent'] ?? false),
            'booking_confirmed' => (bool) ($bookingSnapshot['booking_confirmed'] ?? false),
            'needs_human_escalation' => (bool) (($bookingSnapshot['needs_human_escalation'] ?? false) || $conversation->needs_human),
            'waiting_admin_takeover' => (bool) ($bookingSnapshot['waiting_admin_takeover'] ?? false),
            'admin_takeover' => $conversation->isAdminTakeover(),

            'understanding_state' => $this->deriveUnderstandingState(
                conversation: $conversation,
                activeStates: $activeStates,
                bookingSnapshot: $bookingSnapshot,
                draftBooking: $draftBooking,
            ),
        ], static fn (mixed $value, string $key): bool => match ($key) {
            'admin_takeover', 'review_sent', 'booking_confirmed', 'needs_human_escalation', 'waiting_admin_takeover' => true,
            default => $value !== null && $value !== '',
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function findActiveDraft(Conversation $conversation): ?BookingRequest
    {
        return BookingRequest::query()
            ->forConversation($conversation->id)
            ->active()
            ->latest()
            ->first();
    }

    private function historyWindow(): int
    {
        return min(6, max(3, (int) config('chatbot.memory.max_recent_messages', 6)));
    }

    /**
     * @param  array<string, mixed>  $crmContext
     * @return array<string, mixed>
     */
    private function buildCrmHints(array $crmContext): array
    {
        $businessFlags = is_array($crmContext['business_flags'] ?? null)
            ? $crmContext['business_flags']
            : [];
        $leadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];
        $escalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];
        $conversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];
        $booking = is_array($crmContext['booking'] ?? null)
            ? $crmContext['booking']
            : [];

        return array_filter([
            'bot_paused' => (bool) ($businessFlags['bot_paused'] ?? false),
            'admin_takeover_active' => (bool) ($businessFlags['admin_takeover_active'] ?? false),
            'has_open_escalation' => (bool) ($escalation['has_open_escalation'] ?? false),

            // continuity hints only
            'current_stage' => $this->normalizeText($leadPipeline['current_stage'] ?? null),
            'last_intent' => $this->normalizeText($conversation['last_ai_intent'] ?? null),
            'booking_status' => $this->normalizeText($booking['status'] ?? null),

            // keep summary short and optional
            'last_summary' => $this->normalizeShortText($conversation['last_ai_summary'] ?? null, 180),

            'needs_human_followup' => (bool) ($conversation['needs_human_followup'] ?? false),
        ], static fn (mixed $value): bool => match (true) {
            is_bool($value) => $value === true,
            is_string($value) => $value !== '',
            default => $value !== null,
        });
    }

    /**
     * @param  array<string, mixed>  $crmContext
     * @return array<string, mixed>
     */
    private function crmContextForUnderstanding(array $crmContext): array
    {
        if ($crmContext === []) {
            return [];
        }

        return array_filter([
            'business_flags' => is_array($crmContext['business_flags'] ?? null)
                ? $crmContext['business_flags']
                : [],
            'lead_pipeline' => is_array($crmContext['lead_pipeline'] ?? null)
                ? $crmContext['lead_pipeline']
                : [],
            'conversation' => is_array($crmContext['conversation'] ?? null)
                ? $crmContext['conversation']
                : [],
            'booking' => is_array($crmContext['booking'] ?? null)
                ? $crmContext['booking']
                : [],
            'escalation' => is_array($crmContext['escalation'] ?? null)
                ? $crmContext['escalation']
                : [],
        ], static fn (mixed $value): bool => is_array($value) && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $activeStates
     * @param  array<string, mixed>  $bookingSnapshot
     */
    private function deriveUnderstandingState(
        Conversation $conversation,
        array $activeStates,
        array $bookingSnapshot,
        ?BookingRequest $draftBooking,
    ): ?string {
        if ($conversation->isAdminTakeover()) {
            return 'admin_takeover';
        }

        if ((bool) (($bookingSnapshot['needs_human_escalation'] ?? false) || $conversation->needs_human)) {
            return 'needs_human';
        }

        if ((bool) ($bookingSnapshot['waiting_admin_takeover'] ?? false)) {
            return 'waiting_admin';
        }

        $expectedInput = $this->normalizeText(
            $activeStates[BookingConversationStateService::EXPECTED_INPUT_KEY]
                ?? $activeStates['booking_expected_input']
                ?? null,
        );

        if ($expectedInput !== null) {
            return 'awaiting_'.$expectedInput;
        }

        if ($draftBooking !== null && $draftBooking->booking_status !== null) {
            return 'booking_'.$draftBooking->booking_status->value;
        }

        return $this->normalizeText($conversation->current_intent) !== null
            ? 'intent_'.$this->normalizeText($conversation->current_intent)
            : 'general';
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $intValue = (int) $value;

            return $intValue > 0 ? $intValue : null;
        }

        return null;
    }

    private function normalizeShortText(mixed $value, int $limit = 180): ?string
    {
        $text = $this->normalizeText($value);

        if ($text === null) {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }

    private function buildJobTraceId(Conversation $conversation, ConversationMessage $message): string
    {
        return implode('-', array_filter([
            'trace',
            'c'.$conversation->id,
            'm'.$message->id,
            now()->format('YmdHis'),
            substr(md5((string) microtime(true)), 0, 8),
        ]));
    }

    private function roleFromSender(?SenderType $senderType): string
    {
        return match ($senderType) {
            SenderType::Customer => 'user',
            SenderType::Admin, SenderType::Agent => 'admin',
            default => 'bot',
        };
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
