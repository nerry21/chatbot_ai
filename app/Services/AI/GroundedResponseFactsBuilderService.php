<?php

namespace App\Services\AI;

use App\Data\AI\GroundedResponseFacts;
use App\Enums\GroundedResponseMode;
use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;

class GroundedResponseFactsBuilderService
{
    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly FareCalculatorService $fareCalculator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyTemplate
     * @param  array<string, mixed>  $aiContext
     * @param  array<string, mixed>|null  $bookingDecision
     */
    public function build(
        Conversation $conversation,
        ConversationMessage $message,
        array $intentResult,
        array $entityResult,
        array $replyTemplate,
        array $aiContext = [],
        ?array $bookingDecision = null,
        ?BookingRequest $booking = null,
    ): GroundedResponseFacts {
        $officialFacts = $this->officialFacts(
            intentResult: $intentResult,
            entityResult: $entityResult,
            replyTemplate: $replyTemplate,
            aiContext: $aiContext,
            bookingDecision: $bookingDecision,
            booking: $booking,
        );

        return new GroundedResponseFacts(
            conversationId: (int) $conversation->id,
            messageId: (int) $message->id,
            mode: $this->resolveMode($intentResult, $replyTemplate, $officialFacts, $bookingDecision),
            latestMessageText: trim((string) ($message->message_text ?? '')),
            customerName: $conversation->customer?->name,
            intentResult: $intentResult,
            entityResult: $entityResult,
            resolvedContext: is_array($aiContext['resolved_context'] ?? null) ? $aiContext['resolved_context'] : [],
            conversationSummary: is_string($aiContext['conversation_summary'] ?? null)
                ? $aiContext['conversation_summary']
                : null,
            adminTakeover: $conversation->isAdminTakeover() || (($aiContext['admin_takeover'] ?? false) === true),
            officialFacts: $officialFacts,
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $replyTemplate
     * @param  array<string, mixed>  $officialFacts
     * @param  array<string, mixed>|null  $bookingDecision
     */
    private function resolveMode(
        array $intentResult,
        array $replyTemplate,
        array $officialFacts,
        ?array $bookingDecision,
    ): GroundedResponseMode {
        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return GroundedResponseMode::HandoffMessage;
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return GroundedResponseMode::ClarificationQuestion;
        }

        $action = (string) ($replyTemplate['meta']['action'] ?? ($bookingDecision['action'] ?? ''));

        if ($action !== '' && (str_starts_with($action, 'collect_') || in_array($action, ['ask_confirmation', 'acknowledge_pending'], true))) {
            return GroundedResponseMode::BookingContinuation;
        }

        if (
            in_array($action, [
                'unsupported_route',
                'unavailable',
                'close_conversation',
                'close_pending',
            ], true)
            || (($officialFacts['route']['supported'] ?? true) === false)
            || (($officialFacts['requested_schedule']['available'] ?? true) === false)
        ) {
            return GroundedResponseMode::PoliteRefusal;
        }

        if (in_array((string) ($intentResult['intent'] ?? ''), [
            IntentType::HumanHandoff->value,
            IntentType::Support->value,
            IntentType::PertanyaanTidakTerjawab->value,
        ], true)) {
            return GroundedResponseMode::HandoffMessage;
        }

        return GroundedResponseMode::DirectAnswer;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyTemplate
     * @param  array<string, mixed>  $aiContext
     * @param  array<string, mixed>|null  $bookingDecision
     * @return array<string, mixed>
     */
    private function officialFacts(
        array $intentResult,
        array $entityResult,
        array $replyTemplate,
        array $aiContext,
        ?array $bookingDecision,
        ?BookingRequest $booking,
    ): array {
        $activeStates = is_array($aiContext['active_states'] ?? null) ? $aiContext['active_states'] : [];
        $pickup = $this->normalizeLocation($entityResult['pickup_location'] ?? $activeStates['pickup_location'] ?? $booking?->pickup_location);
        $destination = $this->normalizeLocation($entityResult['destination'] ?? $activeStates['destination'] ?? $booking?->destination);
        $travelDate = $this->normalizeText($entityResult['departure_date'] ?? $activeStates['travel_date'] ?? $booking?->departure_date?->toDateString());
        $travelTime = $this->normalizeTime($entityResult['departure_time'] ?? $activeStates['travel_time'] ?? $booking?->departure_time);
        $passengerCount = $this->normalizeInt($entityResult['passenger_count'] ?? $activeStates['passenger_count'] ?? $booking?->passenger_count);
        $selectedSeats = $this->normalizeStringArray($entityResult['selected_seats'] ?? $activeStates['selected_seats'] ?? $booking?->selected_seats ?? []);
        $availableSeatChoices = $this->normalizeStringArray($activeStates['seat_choices_available'] ?? []);
        $fareBreakdown = $this->fareCalculator->fareBreakdown($pickup, $destination, $passengerCount);
        $requestedSchedule = $this->requestedScheduleFacts($travelDate, $travelTime);
        $routeFacts = $this->routeFacts($pickup, $destination);
        $missingFields = $this->normalizeStringArray($entityResult['missing_fields'] ?? []);
        $faqResult = is_array($aiContext['faq_result'] ?? null) ? $aiContext['faq_result'] : [];
        $knowledgeHits = is_array($aiContext['knowledge_hits'] ?? null) ? $aiContext['knowledge_hits'] : [];

        return array_filter([
            'mode_hint' => $replyTemplate['meta']['action'] ?? ($bookingDecision['action'] ?? null),
            'intent' => $intentResult['intent'] ?? null,
            'official_schedule_slots' => $this->officialScheduleSlots(),
            'requested_schedule' => $requestedSchedule,
            'route' => $routeFacts,
            'pricing' => $fareBreakdown !== null ? [
                'pickup' => $fareBreakdown['pickup'],
                'destination' => $fareBreakdown['destination'],
                'unit_fare' => $fareBreakdown['unit_fare'],
                'unit_fare_formatted' => $this->fareCalculator->formatRupiah($fareBreakdown['unit_fare']),
                'passenger_count' => $fareBreakdown['passenger_count'],
                'total_fare' => $fareBreakdown['total_fare'],
                'total_fare_formatted' => $this->fareCalculator->formatRupiah($fareBreakdown['total_fare']),
            ] : null,
            'seat_availability' => [
                'selected_seats' => $selectedSeats,
                'available_choices' => $availableSeatChoices,
                'available_count' => $availableSeatChoices !== [] ? count($availableSeatChoices) : null,
            ],
            'booking_context' => [
                'booking_action' => $bookingDecision['action'] ?? null,
                'booking_status' => $bookingDecision['booking_status'] ?? $booking?->booking_status?->value,
                'expected_input' => $activeStates['booking_expected_input'] ?? null,
                'missing_fields' => $missingFields,
                'passenger_count' => $passengerCount,
                'payment_method' => $this->normalizeText($entityResult['payment_method'] ?? $activeStates['payment_method'] ?? $booking?->payment_method),
            ],
            'verified_answer' => ($faqResult['matched'] ?? false) && ! empty($faqResult['answer'])
                ? [
                    'source' => 'faq',
                    'text' => (string) $faqResult['answer'],
                ]
                : $this->knowledgeFacts($knowledgeHits),
            'suggested_follow_up' => $this->suggestedFollowUp($intentResult, $entityResult, $routeFacts, $requestedSchedule, $activeStates),
            'existing_reply_hint' => $this->normalizeText($replyTemplate['text'] ?? null),
        ], static fn (mixed $value): bool => ! self::isEmptyValue($value));
    }

    /**
     * @return array<int, array{id: string, label: string, time: string}>
     */
    private function officialScheduleSlots(): array
    {
        return array_values(array_map(
            static fn (array $slot): array => [
                'id' => (string) ($slot['id'] ?? ($slot['time'] ?? '')),
                'label' => (string) ($slot['label'] ?? ($slot['time'] ?? '')),
                'time' => (string) ($slot['time'] ?? ''),
            ],
            (array) config('chatbot.jet.departure_slots', []),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestedScheduleFacts(?string $travelDate, ?string $travelTime): array
    {
        $availableTimes = array_column($this->officialScheduleSlots(), 'time');
        $available = $travelTime !== null
            ? in_array($travelTime, $availableTimes, true)
            : null;

        return array_filter([
            'travel_date' => $travelDate,
            'travel_time' => $travelTime,
            'available' => $available,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function routeFacts(?string $pickup, ?string $destination): array
    {
        $supported = ($pickup !== null && $destination !== null)
            ? $this->routeValidator->isSupported($pickup, $destination)
            : null;

        return array_filter([
            'pickup_location' => $pickup,
            'destination' => $destination,
            'supported' => $supported,
            'supported_destinations' => $pickup !== null
                ? $this->routeValidator->supportedDestinations($pickup)
                : [],
            'supported_pickups' => $destination !== null
                ? $this->routeValidator->supportedPickupsForDestination($destination)
                : [],
        ], static fn (mixed $value): bool => ! self::isEmptyValue($value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $knowledgeHits
     * @return array<string, mixed>|null
     */
    private function knowledgeFacts(array $knowledgeHits): ?array
    {
        if ($knowledgeHits === []) {
            return null;
        }

        $topHit = $knowledgeHits[0];

        return array_filter([
            'source' => 'knowledge',
            'title' => $this->normalizeText($topHit['title'] ?? null),
            'excerpt' => $this->normalizeText($topHit['excerpt'] ?? $topHit['content_preview'] ?? null),
        ], static fn (mixed $value): bool => ! self::isEmptyValue($value));
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $routeFacts
     * @param  array<string, mixed>  $requestedSchedule
     * @param  array<string, mixed>  $activeStates
     */
    private function suggestedFollowUp(
        array $intentResult,
        array $entityResult,
        array $routeFacts,
        array $requestedSchedule,
        array $activeStates,
    ): ?string {
        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return 'Mohon tunggu, admin akan membantu mengecek lebih lanjut.';
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return $this->normalizeText($intentResult['clarification_question'] ?? null);
        }

        if (($routeFacts['supported'] ?? null) === false) {
            return 'Silakan kirim rute lain yang ingin dicek.';
        }

        if (($requestedSchedule['available'] ?? null) === false) {
            return 'Silakan pilih jam keberangkatan lain yang tersedia.';
        }

        $expectedInput = $this->normalizeText($activeStates['booking_expected_input'] ?? null);
        if ($expectedInput !== null) {
            return 'Jika berkenan, saya bisa bantu lanjut ke langkah booking berikutnya.';
        }

        if (! self::isEmptyValue($entityResult['destination'] ?? null)) {
            return 'Jika ingin, saya bisa bantu lanjut bookingnya.';
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeStringArray(array $values): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizeText($value),
            $values,
        )));
    }

    private function normalizeLocation(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return $this->routeValidator->normalizeLocation((string) $value);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if (preg_match('/^(?<hour>[01]?\d|2[0-3])[:.](?<minute>[0-5]\d)$/', $normalized, $matches) !== 1) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $matches['hour'], (int) $matches['minute']);
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }

    private static function isEmptyValue(mixed $value): bool
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
