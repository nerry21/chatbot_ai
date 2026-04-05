<?php

namespace App\Services\AI;

use App\Data\AI\LlmUnderstandingResult;
use App\Enums\IntentType;

class UnderstandingResultAdapterService
{
    /**
     * @param  array<string, mixed>  $legacyIntentResult
     * @param  array<string, mixed>  $legacyEntityResult
     * @return array{
     *     intent_result: array<string, mixed>,
     *     entity_result: array<string, mixed>,
     *     meta: array{
     *         llm_primary: bool,
     *         understanding_source: string,
     *         crm_hints_used: bool,
     *         used_legacy_intent_fallback: bool,
     *         used_legacy_entity_fallback: bool,
     *         legacy_fallback_reason: string|null
     *     }
     * }
     */
    public function adapt(
        LlmUnderstandingResult $understanding,
        array $legacyIntentResult = [],
        array $legacyEntityResult = [],
    ): array {
        $usedLegacyIntentFallback = $this->needsLegacyFallback($understanding) && $legacyIntentResult !== [];
        $usedLegacyEntityFallback = $legacyEntityResult !== [];

        $intent = $this->resolveIntent($understanding, $legacyIntentResult, $usedLegacyIntentFallback);
        $confidence = $usedLegacyIntentFallback
            ? (float) ($legacyIntentResult['confidence'] ?? $understanding->confidence)
            : $understanding->confidence;
        $reasoning = $usedLegacyIntentFallback
            ? (string) ($legacyIntentResult['reasoning_short'] ?? $understanding->reasoningSummary)
            : $understanding->reasoningSummary;

        $intentResult = [
            'intent' => $intent,
            'confidence' => min(1.0, max(0.0, $confidence)),
            'reasoning_short' => $reasoning !== '' ? $reasoning : 'Understanding LLM-first dengan CRM hints aktif.',
            'sub_intent' => $understanding->subIntent,
            'needs_clarification' => $understanding->needsClarification,
            'clarification_question' => $understanding->clarificationQuestion,
            'handoff_recommended' => $understanding->handoffRecommended,
            'uses_previous_context' => $understanding->usesPreviousContext,
            'llm_primary' => true,
            'understanding_source' => 'llm_first_understanding_with_crm_hints',
            'crm_hints_used' => true,
        ];

        $entityResult = $this->mergeLegacyEntities(
            $this->buildEntityResult($understanding),
            $legacyEntityResult,
        );

        return [
            'intent_result' => $intentResult,
            'entity_result' => $entityResult,
            'meta' => [
                'llm_primary' => true,
                'understanding_source' => 'llm_first_understanding_with_crm_hints',
                'crm_hints_used' => true,
                'used_legacy_intent_fallback' => $usedLegacyIntentFallback,
                'used_legacy_entity_fallback' => $usedLegacyEntityFallback,
                'legacy_fallback_reason' => $usedLegacyIntentFallback || $usedLegacyEntityFallback
                    ? 'LLM understanding tidak cukup kuat sehingga legacy fallback dipakai sebagai backup.'
                    : null,
            ],
        ];
    }

    public function needsLegacyFallback(LlmUnderstandingResult $understanding): bool
    {
        return $understanding->intent === IntentType::Unknown->value
            && $understanding->confidence <= 0.0
            && ! $understanding->handoffRecommended
            && ! $this->hasMeaningfulEntities($understanding)
            && $understanding->needsClarification;
    }

    /**
     * @param  array<string, mixed>  $legacyIntentResult
     */
    private function resolveIntent(
        LlmUnderstandingResult $understanding,
        array $legacyIntentResult,
        bool $usedLegacyIntentFallback,
    ): string {
        if ($usedLegacyIntentFallback) {
            $legacyIntent = trim((string) ($legacyIntentResult['intent'] ?? ''));

            if ($legacyIntent !== '') {
                return $legacyIntent;
            }
        }

        if (
            $understanding->handoffRecommended
            && in_array($understanding->intent, [
                IntentType::Unknown->value,
                IntentType::OutOfScope->value,
                IntentType::Support->value,
                IntentType::PertanyaanTidakTerjawab->value,
            ], true)
        ) {
            return IntentType::HumanHandoff->value;
        }

        return $understanding->intent;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntityResult(LlmUnderstandingResult $understanding): array
    {
        $selectedSeats = $this->seatList($understanding->entities->seatNumber);

        return [
            'customer_name' => $understanding->entities->passengerName,
            'passenger_name' => $understanding->entities->passengerName,
            'pickup_location' => $understanding->entities->origin,
            'destination' => $understanding->entities->destination,
            'departure_date' => $understanding->entities->travelDate,
            'departure_time' => $understanding->entities->departureTime,
            'passenger_count' => $understanding->entities->passengerCount,
            'seat_number' => $understanding->entities->seatNumber,
            'selected_seats' => $selectedSeats,
            'payment_method' => $understanding->entities->paymentMethod,
            'notes' => null,
            'missing_fields' => $this->missingFields($understanding),
        ];
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $legacyEntityResult
     * @return array<string, mixed>
     */
    private function mergeLegacyEntities(array $entityResult, array $legacyEntityResult): array
    {
        if ($legacyEntityResult === []) {
            return $entityResult;
        }

        foreach ([
            'customer_name',
            'passenger_name',
            'pickup_location',
            'destination',
            'departure_date',
            'departure_time',
            'passenger_count',
            'seat_number',
            'payment_method',
            'notes',
        ] as $key) {
            $current = $entityResult[$key] ?? null;
            $fallback = $legacyEntityResult[$key] ?? null;

            if ($this->isBlank($current) && ! $this->isBlank($fallback)) {
                $entityResult[$key] = $fallback;
            }
        }

        if (($entityResult['selected_seats'] ?? []) === []) {
            $fallbackSeats = $legacyEntityResult['selected_seats'] ?? $this->seatList($legacyEntityResult['seat_number'] ?? null);

            if (is_array($fallbackSeats) && $fallbackSeats !== []) {
                $entityResult['selected_seats'] = array_values($fallbackSeats);
                $entityResult['seat_number'] = implode(', ', $fallbackSeats);
            }
        }

        $legacyMissing = is_array($legacyEntityResult['missing_fields'] ?? null)
            ? $legacyEntityResult['missing_fields']
            : [];

        $entityResult['missing_fields'] = array_values(array_unique(array_filter(array_merge(
            is_array($entityResult['missing_fields'] ?? null) ? $entityResult['missing_fields'] : [],
            $legacyMissing,
        ))));

        return $entityResult;
    }

    /**
     * @return array<int, string>
     */
    private function missingFields(LlmUnderstandingResult $understanding): array
    {
        $fields = [
            'pickup_location' => $understanding->entities->origin,
            'destination' => $understanding->entities->destination,
            'departure_date' => $understanding->entities->travelDate,
            'departure_time' => $understanding->entities->departureTime,
            'passenger_count' => $understanding->entities->passengerCount,
        ];

        return array_keys(array_filter($fields, fn (mixed $value): bool => $this->isBlank($value)));
    }

    /**
     * @return array<int, string>
     */
    private function seatList(mixed $seatNumber): array
    {
        if (is_array($seatNumber)) {
            return array_values(array_filter(array_map(
                fn (mixed $seat): ?string => $this->normalizeText($seat),
                $seatNumber,
            )));
        }

        $normalized = $this->normalizeText($seatNumber);
        if ($normalized === null) {
            return [];
        }

        $parts = preg_split('/\s*,\s*|\s*\/\s*|\s*;\s*/u', $normalized) ?: [$normalized];

        return array_values(array_filter(array_map(
            fn (mixed $seat): ?string => $this->normalizeText($seat),
            $parts,
        )));
    }

    private function hasMeaningfulEntities(LlmUnderstandingResult $understanding): bool
    {
        foreach ($understanding->entities->toArray() as $value) {
            if (! $this->isBlank($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function isBlank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }
}
