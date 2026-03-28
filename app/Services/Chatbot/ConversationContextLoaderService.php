<?php

namespace App\Services\Chatbot;

use App\Data\Chatbot\ConversationContextMessage;
use App\Data\Chatbot\ConversationContextPayload;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Booking\BookingConversationStateService;

class ConversationContextLoaderService
{
    private const HISTORY_FETCH_LIMIT = 20;

    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly BookingConversationStateService $bookingStateService,
        private readonly CustomerMemoryService $customerMemoryService,
        private readonly ConversationMemoryResolverService $memoryResolver,
        private readonly ConversationContextSummaryService $summaryService,
    ) {
    }

    public function load(Conversation $conversation, ConversationMessage $message): ConversationContextPayload
    {
        $conversation->loadMissing('customer');

        $historyWindow = $this->historyWindow();
        $historyMessages = $this->loadHistoryMessages($conversation, $message->id);
        $recentMessages = array_values(array_slice($historyMessages, -$historyWindow));
        $omittedMessageCount = max(0, count($historyMessages) - count($recentMessages));

        $activeStates = $this->stateService->allActive($conversation);
        $bookingSnapshot = $this->bookingStateService->load($conversation);
        $draftBooking = $this->findActiveDraft($conversation);
        $knownEntities = $this->buildKnownEntities($bookingSnapshot, $draftBooking);
        $conversationState = $this->buildConversationState(
            conversation: $conversation,
            activeStates: $activeStates,
            bookingSnapshot: $bookingSnapshot,
            draftBooking: $draftBooking,
        );

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
            adminTakeover: $conversation->isAdminTakeover(),
        );
    }

    /**
     * @return array<int, ConversationContextMessage>
     */
    private function loadHistoryMessages(Conversation $conversation, int $excludeMessageId): array
    {
        return $conversation->messages()
            ->where('id', '!=', $excludeMessageId)
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
            'passenger_count' => $bookingSnapshot['passenger_count'] ?? $draftBooking?->passenger_count,
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
            'route_status' => $this->normalizeText($bookingSnapshot['route_status'] ?? null),
            'route_issue' => $this->normalizeText($bookingSnapshot['route_issue'] ?? null),
            'waiting_for' => $this->normalizeText($activeStates['waiting_for'] ?? null),
            'waiting_reason' => $this->normalizeText($activeStates['waiting_reason'] ?? null),
            'review_sent' => (bool) ($bookingSnapshot['review_sent'] ?? false),
            'booking_confirmed' => (bool) ($bookingSnapshot['booking_confirmed'] ?? false),
            'needs_human_escalation' => (bool) (($bookingSnapshot['needs_human_escalation'] ?? false) || $conversation->needs_human),
            'waiting_admin_takeover' => (bool) ($bookingSnapshot['waiting_admin_takeover'] ?? false),
            'active_booking_status' => $draftBooking?->booking_status?->value,
            'admin_takeover' => $conversation->isAdminTakeover(),
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
        return min(10, max(5, (int) config('chatbot.memory.max_recent_messages', 10)));
    }

    private function roleFromSender(?SenderType $senderType): string
    {
        return match ($senderType) {
            SenderType::Customer => 'user',
            SenderType::Agent => 'admin',
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
