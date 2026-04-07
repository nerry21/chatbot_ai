<?php

namespace App\Services\AI;

use App\Data\AI\LlmUnderstandingResult;
use App\Enums\IntentType;

class UnderstandingResultAdapterService
{
    /**
     * @param  array<string, mixed>  $legacyIntentResult
     * @param  array<string, mixed>  $legacyEntityResult
     * @param  array<string, mixed>  $llmRuntimeMeta
     * @return array<string, mixed>
     */
    public function adapt(
        LlmUnderstandingResult $understanding,
        array $legacyIntentResult = [],
        array $legacyEntityResult = [],
        array $llmRuntimeMeta = [],
    ): array {
        $normalizedRuntimeMeta = $this->normalizeRuntimeMeta($llmRuntimeMeta);
        $usedLegacyFallback = $legacyIntentResult !== [] && $this->needsLegacyFallback($understanding);

        $finalIntentResult = $this->buildIntentResult(
            understanding: $understanding,
            legacyIntentResult: $legacyIntentResult,
            normalizedRuntimeMeta: $normalizedRuntimeMeta,
            usedLegacyFallback: $usedLegacyFallback,
        );

        $finalEntityResult = $this->buildEntityResult(
            understanding: $understanding,
            legacyEntityResult: $legacyEntityResult,
            usedLegacyFallback: $usedLegacyFallback,
        );

        $usedCrmFacts = $this->buildUsedCrmFacts($understanding);

        $meta = [
            'source' => 'llm_understanding',
            'used_legacy_fallback' => $usedLegacyFallback,
            'llm_runtime' => $normalizedRuntimeMeta,
            'understanding' => [
                'intent' => $understanding->intent,
                'confidence' => $understanding->confidence,
                'uses_previous_context' => $understanding->usesPreviousContext,
                'needs_clarification' => $understanding->needsClarification,
                'handoff_recommended' => $understanding->handoffRecommended,
                'reasoning_summary' => $understanding->reasoningSummary,
            ],
            'legacy' => [
                'intent_result_present' => $legacyIntentResult !== [],
                'entity_result_present' => $legacyEntityResult !== [],
            ],
            'crm_facts' => $usedCrmFacts,
            'adapter_decision' => [
                'used_legacy_fallback' => $usedLegacyFallback,
                'primary_source' => $usedLegacyFallback ? 'legacy_fallback' : 'llm_understanding',
                'runtime_health' => $this->deriveRuntimeHealth($normalizedRuntimeMeta),
            ],
        ];

        return [
            'intent_result' => $finalIntentResult,
            'entity_result' => $finalEntityResult,
            'meta' => $meta,
            'business_domain' => 'travel',
            'raw_understanding' => $this->rawUnderstandingSnapshot($understanding),
            'normalized_understanding' => [
                'intent_result' => $finalIntentResult,
                'entity_result' => $finalEntityResult,
            ],
            'legacy_projection' => [
                'intent_result' => $legacyIntentResult,
                'entity_result' => $legacyEntityResult,
            ],
        ];
    }

    public function needsLegacyFallback(LlmUnderstandingResult $understanding): bool
    {
        if ($understanding->intent === '' || $understanding->intent === 'unknown') {
            return true;
        }

        if (
            $understanding->confidence <= 0.20
            && ! in_array($understanding->intent, [
                'ask_schedule',
                'ask_fare',
                'ask_route',
                'booking_start',
                'schedule_change',
                'pickup_dropoff_question',
                'payment_question',
            ], true)
        ) {
            return true;
        }

        if ($understanding->needsClarification && $understanding->clarificationQuestion === null) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawUnderstandingSnapshot(LlmUnderstandingResult $understanding): array
    {
        return [
            'intent' => $understanding->intent,
            'sub_intent' => $understanding->subIntent,
            'confidence' => $understanding->confidence,
            'uses_previous_context' => $understanding->usesPreviousContext,
            'entities' => $understanding->entities,
            'needs_clarification' => $understanding->needsClarification,
            'clarification_question' => $understanding->clarificationQuestion,
            'handoff_recommended' => $understanding->handoffRecommended,
            'reasoning_summary' => $understanding->reasoningSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyIntentResult
     * @param  array<string, mixed>  $normalizedRuntimeMeta
     * @return array<string, mixed>
     */
    private function buildIntentResult(
        LlmUnderstandingResult $understanding,
        array $legacyIntentResult,
        array $normalizedRuntimeMeta,
        bool $usedLegacyFallback,
    ): array {
        $finalIntent = $this->resolveIntent($understanding, $legacyIntentResult, $usedLegacyFallback);

        $finalConfidence = $usedLegacyFallback
            ? min(1.0, max(0.0, (float) ($legacyIntentResult['confidence'] ?? $understanding->confidence)))
            : min(1.0, max(0.0, $understanding->confidence));

        $reasoningShort = $this->normalizeText(
            $usedLegacyFallback
                ? ($legacyIntentResult['reasoning_short'] ?? $understanding->reasoningSummary)
                : $understanding->reasoningSummary
        ) ?? 'Reasoning summary tidak tersedia.';

        if ($usedLegacyFallback && ! str_contains(strtolower($reasoningShort), 'fallback')) {
            $reasoningShort = 'Legacy fallback used. '.$reasoningShort;
        }

        return [
            'intent' => $finalIntent,
            'confidence' => $finalConfidence,
            'reasoning_short' => $reasoningShort,
            'source' => $usedLegacyFallback ? 'legacy_fallback' : 'llm_understanding',
            'needs_clarification' => $understanding->needsClarification,
            'clarification_question' => $understanding->clarificationQuestion,
            'needs_clarification_reason' => $understanding->needsClarification
                ? 'travel_context_needs_more_detail'
                : null,
            'handoff_recommended' => $understanding->handoffRecommended,
            'uses_previous_context' => $understanding->usesPreviousContext,
            'runtime_health' => $this->deriveRuntimeHealth($normalizedRuntimeMeta),
            'business_domain' => 'travel',
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyEntityResult
     * @return array<string, mixed>
     */
    private function buildEntityResult(
        LlmUnderstandingResult $understanding,
        array $legacyEntityResult,
        bool $usedLegacyFallback,
    ): array {
        $selectedSeats = $this->seatList($understanding->entities->seatNumber);

        $finalEntities = [
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

        if ($legacyEntityResult !== []) {
            if (in_array($understanding->intent, [
                'ask_schedule',
                'ask_fare',
                'ask_route',
                'booking_start',
                'schedule_change',
            ], true)) {
                unset(
                    $legacyEntityResult['pickup_full_address'],
                    $legacyEntityResult['destination_full_address'],
                    $legacyEntityResult['booking_confirmation'],
                    $legacyEntityResult['review_confirmation']
                );
            }
            $finalEntities = $this->mergeLegacyEntities($finalEntities, $legacyEntityResult);
        }

        return [
            ...$finalEntities,
            '_meta' => [
                'source' => $usedLegacyFallback ? 'legacy_fallback' : 'llm_understanding',
                'legacy_entity_result_present' => $legacyEntityResult !== [],
                'entity_key_count' => count(array_filter(
                    $finalEntities,
                    static fn ($value) => $value !== null && $value !== ''
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyIntentResult
     */
    private function resolveIntent(
        LlmUnderstandingResult $understanding,
        array $legacyIntentResult,
        bool $usedLegacyFallback,
    ): string {
        if ($usedLegacyFallback) {
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
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $legacyEntityResult
     * @return array<string, mixed>
     */
    private function mergeLegacyEntities(array $entityResult, array $legacyEntityResult): array
    {
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

    /**
     * @return array<int, string>
     */
    private function buildUsedCrmFacts(LlmUnderstandingResult $understanding): array
    {
        $facts = [];

        if ($understanding->usesPreviousContext) {
            $facts[] = 'used_previous_context';
        }

        if ($understanding->handoffRecommended) {
            $facts[] = 'handoff_recommended';
        }

        if ($understanding->needsClarification) {
            $facts[] = 'needs_clarification';
        }

        return array_values(array_unique(array_filter(
            $facts,
            static fn ($value) => is_string($value) && trim($value) !== ''
        )));
    }

    /**
     * @param  array<string, mixed>  $llmRuntimeMeta
     * @return array<string, mixed>
     */
    private function normalizeRuntimeMeta(array $llmRuntimeMeta): array
    {
        return [
            'trace_id' => $this->normalizeText($llmRuntimeMeta['trace_id'] ?? null),
            'provider' => $this->normalizeText($llmRuntimeMeta['provider'] ?? null),
            'model' => $this->normalizeText($llmRuntimeMeta['model'] ?? ($llmRuntimeMeta['primary_model'] ?? null)),
            'status' => $this->normalizeText($llmRuntimeMeta['status'] ?? null),
            'degraded_mode' => (bool) ($llmRuntimeMeta['degraded_mode'] ?? false),
            'schema_valid' => array_key_exists('schema_valid', $llmRuntimeMeta)
                ? (bool) $llmRuntimeMeta['schema_valid']
                : true,
            'used_fallback_model' => (bool) ($llmRuntimeMeta['used_fallback_model'] ?? false),
            'fallback_reason' => $this->normalizeText($llmRuntimeMeta['fallback_reason'] ?? null),
            'task_key' => $this->normalizeText($llmRuntimeMeta['task_key'] ?? null),
            'task_type' => $this->normalizeText($llmRuntimeMeta['task_type'] ?? null),
            'understanding_mode' => $this->normalizeText($llmRuntimeMeta['understanding_mode'] ?? null),
            'conversation_id' => $llmRuntimeMeta['conversation_id'] ?? null,
            'message_id' => $llmRuntimeMeta['message_id'] ?? null,
            'input_contract' => is_array($llmRuntimeMeta['input_contract'] ?? null)
                ? $llmRuntimeMeta['input_contract']
                : [],
        ];
    }

    private function deriveRuntimeHealth(array $runtimeMeta): string
    {
        if (($runtimeMeta['status'] ?? null) === 'fallback') {
            return 'fallback';
        }

        if (($runtimeMeta['schema_valid'] ?? true) === false) {
            return 'schema_invalid';
        }

        if (($runtimeMeta['degraded_mode'] ?? false) === true) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

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
