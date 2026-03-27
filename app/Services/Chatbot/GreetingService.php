<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Services\Booking\TimeGreetingService;
use App\Support\WaLog;

class GreetingService
{
    /**
     * @var array<int, string>
     */
    private const STRONG_CONTEXT_KEYS = [
        'booking_intent_status',
        'booking_expected_input',
        'waiting_for',
        'waiting_reason',
        'waiting_admin_takeover',
        'pickup_location',
        'pickup_full_address',
        'destination',
        'passenger_name',
        'passenger_names',
        'passenger_count',
        'travel_date',
        'travel_time',
        'selected_seats',
        'contact_number',
        'route_status',
        'review_sent',
    ];

    public function __construct(
        private readonly TimeGreetingService $timeGreetingService,
        private readonly GreetingDetectorService $greetingDetector,
        private readonly GreetingFormatterService $greetingFormatter,
    ) {}

    /**
     * @return array{
     *     has_islamic_greeting: bool,
     *     has_general_greeting: bool,
     *     greeting_only: bool,
     *     time_greeting: array{key: string, label: string}
     * }
     */
    public function inspect(string $messageText): array
    {
        return array_merge(
            $this->greetingDetector->inspect($messageText),
            ['time_greeting' => $this->timeGreetingService->resolve()],
        );
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    public function shouldUseOpeningGreeting(Conversation $conversation, array $activeStates = []): bool
    {
        $inboundCount = $conversation->messages()->inbound()->count();

        if ($inboundCount !== 1) {
            return false;
        }

        return ! $this->hasStrongContext($conversation, $activeStates);
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    public function buildGreetingReply(
        Conversation $conversation,
        string $messageText,
        array $activeStates = [],
    ): ?string {
        $inspection = $this->inspect($messageText);

        if (! $inspection['has_general_greeting']) {
            return null;
        }

        $seed = $conversation->id.'|greeting_follow_up|'.$messageText;

        return $this->buildOpeningGreeting($conversation, $messageText, $activeStates)
            ?? $this->greetingFormatter->followUp($inspection['has_islamic_greeting'], $seed);
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    public function buildOpeningGreeting(
        Conversation $conversation,
        string $messageText,
        array $activeStates = [],
    ): ?string {
        $inspection = $this->inspect($messageText);

        if (! $inspection['has_general_greeting']) {
            return null;
        }

        if (! $this->shouldUseOpeningGreeting($conversation, $activeStates)) {
            WaLog::debug('[Greeting] Opening greeting suppressed for this conversation state', [
                'conversation_id' => $conversation->id,
                'current_intent' => $conversation->current_intent,
            ]);

            return null;
        }

        return $this->greetingFormatter->opening(
            $inspection['time_greeting']['label'],
            $inspection['has_islamic_greeting'],
            $conversation->id.'|greeting_opening|'.$messageText,
        );
    }

    public function prependIslamicGreeting(string $messageText, string $replyText): string
    {
        if (! $this->greetingDetector->hasIslamicGreeting($messageText)) {
            return $replyText;
        }

        return $this->greetingFormatter->prependIslamicGreeting($replyText);
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    private function hasStrongContext(Conversation $conversation, array $activeStates): bool
    {
        if (filled($conversation->summary)) {
            return true;
        }

        if (
            filled($conversation->current_intent)
            && ! in_array($conversation->current_intent, ['greeting', 'salam_islam', 'farewell', 'close_intent', 'unknown'], true)
        ) {
            return true;
        }

        foreach (self::STRONG_CONTEXT_KEYS as $key) {
            $value = $activeStates[$key] ?? null;

            if ($key === 'booking_intent_status' && in_array($value, [null, '', 'idle'], true)) {
                continue;
            }

            if ($this->hasMeaningfulValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== false;
    }
}
