<?php

namespace App\Data\AI;

use App\Enums\IntentType;

final readonly class LlmUnderstandingResult
{
    public function __construct(
        public string $intent,
        public ?string $subIntent,
        public float $confidence,
        public bool $usesPreviousContext,
        public LlmUnderstandingEntities $entities,
        public bool $needsClarification,
        public ?string $clarificationQuestion,
        public bool $handoffRecommended,
        public string $reasoningSummary,
    ) {
    }

    public static function fallback(
        ?string $intent = null,
        ?string $reasoningSummary = null,
    ): self {
        return new self(
            intent: $intent ?? IntentType::Unknown->value,
            subIntent: null,
            confidence: 0.0,
            usesPreviousContext: false,
            entities: LlmUnderstandingEntities::empty(),
            needsClarification: true,
            clarificationQuestion: 'Boleh dijelaskan lagi kebutuhan perjalanannya?',
            handoffRecommended: false,
            reasoningSummary: $reasoningSummary ?? 'Fallback understanding digunakan karena output model tidak valid.',
        );
    }

    /**
     * @return array{
     *     intent: string,
     *     sub_intent: string|null,
     *     confidence: float,
     *     uses_previous_context: bool,
     *     entities: array{
     *         origin: string|null,
     *         destination: string|null,
     *         travel_date: string|null,
     *         departure_time: string|null,
     *         passenger_count: int|null,
     *         passenger_name: string|null,
     *         seat_number: string|null,
     *         payment_method: string|null
     *     },
     *     needs_clarification: bool,
     *     clarification_question: string|null,
     *     handoff_recommended: bool,
     *     reasoning_summary: string
     * }
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'sub_intent' => $this->subIntent,
            'confidence' => $this->confidence,
            'uses_previous_context' => $this->usesPreviousContext,
            'entities' => $this->entities->toArray(),
            'needs_clarification' => $this->needsClarification,
            'clarification_question' => $this->clarificationQuestion,
            'handoff_recommended' => $this->handoffRecommended,
            'reasoning_summary' => $this->reasoningSummary,
        ];
    }
}
