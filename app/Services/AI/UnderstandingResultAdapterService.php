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
        array $llmRuntimeMeta = [],
    ): array {
        $usedLegacyIntentFallback = $this->needsLegacyFallback($understanding) && $legacyIntentResult !== [];
        $usedLegacyEntityFallback = $legacyEntityResult !== [];
        $runtimeMeta = $this->normalizeRuntimeMeta($llmRuntimeMeta);
        $traceId = $this->resolveTraceId($runtimeMeta, $legacyIntentResult, $legacyEntityResult);

        $intent = $this->resolveIntent($understanding, $legacyIntentResult, $usedLegacyIntentFallback);
        $confidence = $usedLegacyIntentFallback
            ? (float) ($legacyIntentResult['confidence'] ?? $understanding->confidence)
            : $understanding->confidence;
        $reasoning = $usedLegacyIntentFallback
            ? (string) ($legacyIntentResult['reasoning_short'] ?? $understanding->reasoningSummary)
            : $understanding->reasoningSummary;

        $intentResult = [
            'trace_id' => $traceId,
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
            'model_used' => $runtimeMeta['model'],
            'provider' => $runtimeMeta['provider'],
            'runtime_status' => $runtimeMeta['status'],
            'degraded_mode' => $runtimeMeta['degraded_mode'],
            'used_fallback_model' => $runtimeMeta['used_fallback_model'],
            'schema_valid' => $runtimeMeta['schema_valid'],
            'cache_hit' => $runtimeMeta['cache_hit'],
            'runtime_health' => $this->resolveRuntimeHealth($runtimeMeta),
        ];

        $entityResult = $this->mergeLegacyEntities(
            $this->buildEntityResult($understanding),
            $legacyEntityResult,
        );

        $usedCrmFacts = $this->buildUsedCrmFacts($understanding);

        return [
            'intent_result' => $intentResult,
            'entity_result' => $entityResult,
            'meta' => [
                'trace_id' => $traceId,
                'llm_primary' => true,
                'understanding_source' => 'llm_first_understanding_with_crm_hints',
                'crm_hints_used' => true,
                'used_legacy_intent_fallback' => $usedLegacyIntentFallback,
                'used_legacy_entity_fallback' => $usedLegacyEntityFallback,
                'legacy_fallback_reason' => $usedLegacyIntentFallback || $usedLegacyEntityFallback
                    ? 'LLM understanding tidak cukup kuat sehingga legacy fallback dipakai sebagai backup.'
                    : null,
                'llm_runtime' => $runtimeMeta,
                'runtime_health' => $this->resolveRuntimeHealth($runtimeMeta),
                'degraded_mode' => (bool) ($runtimeMeta['degraded_mode'] ?? false),
                'schema_valid' => (bool) ($runtimeMeta['schema_valid'] ?? true),
                'used_fallback_model' => (bool) ($runtimeMeta['used_fallback_model'] ?? false),
                'crm_awareness' => [
                    'used_crm_hints' => true,
                    'uses_previous_context' => (bool) $understanding->usesPreviousContext,
                    'handoff_recommended' => (bool) $understanding->handoffRecommended,
                    'needs_clarification' => (bool) $understanding->needsClarification,
                    'used_crm_facts' => $usedCrmFacts,
                ],
                'confidence_explanation' => $reasoning !== ''
                    ? $reasoning
                    : 'Confidence dibentuk dari hasil understanding utama dengan fallback legacy bila perlu.',
                'decision_trace' => [
                    'trace_id' => $traceId,
                    'understanding' => [
                        'stage' => 'understanding_result_adapter',
                        'intent' => $intent,
                        'confidence' => min(1.0, max(0.0, $confidence)),
                        'reasoning_short' => $reasoning !== '' ? $reasoning : null,
                        'sub_intent' => $understanding->subIntent,
                        'needs_clarification' => (bool) $understanding->needsClarification,
                        'clarification_question' => $understanding->clarificationQuestion,
                        'handoff_recommended' => (bool) $understanding->handoffRecommended,
                        'uses_previous_context' => (bool) $understanding->usesPreviousContext,
                        'used_legacy_intent_fallback' => $usedLegacyIntentFallback,
                        'used_legacy_entity_fallback' => $usedLegacyEntityFallback,
                        'understanding_source' => 'llm_first_understanding_with_crm_hints',
                        'crm_hints_used' => true,
                        'provider' => $runtimeMeta['provider'],
                        'model_used' => $runtimeMeta['model'],
                        'runtime_status' => $runtimeMeta['status'],
                        'degraded_mode' => (bool) ($runtimeMeta['degraded_mode'] ?? false),
                        'used_fallback_model' => (bool) ($runtimeMeta['used_fallback_model'] ?? false),
                        'schema_valid' => (bool) ($runtimeMeta['schema_valid'] ?? true),
                        'cache_hit' => (bool) ($runtimeMeta['cache_hit'] ?? false),
                        'latency_ms' => $runtimeMeta['latency_ms'],
                        'http_status' => $runtimeMeta['http_status'],
                        'attempt' => $runtimeMeta['attempt'],
                        'max_attempts' => $runtimeMeta['max_attempts'],
                        'runtime_health' => $this->resolveRuntimeHealth($runtimeMeta),
                    ],
                    'grounding' => [
                        'used_crm_facts' => $usedCrmFacts,
                    ],
                    'outcome' => [
                        'final_decision' => 'understanding_prepared',
                        'reply_action' => $understanding->needsClarification ? 'ask_clarification' : null,
                        'handoff' => (bool) $understanding->handoffRecommended,
                    ],
                ],
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

    /**
     * @return array<int, string>
     */
    private function buildUsedCrmFacts(LlmUnderstandingResult $understanding): array
    {
        $facts = [];

        if ($understanding->usesPreviousContext) {
            $facts[] = 'crm.previous_context';
        }

        if ($understanding->handoffRecommended) {
            $facts[] = 'crm.handoff_signal';
        }

        if ($understanding->needsClarification) {
            $facts[] = 'crm.clarification_signal';
        }

        return array_values(array_unique($facts));
    }

    private function resolveTraceId(array ...$sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                $source['trace_id'] ?? null,
                $source['_llm']['trace_id'] ?? null,
                $source['meta']['trace_id'] ?? null,
                $source['decision_trace']['trace_id'] ?? null,
                $source['job_trace_id'] ?? null,
            ] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return trim((string) $candidate);
                }
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
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

    /**
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private function normalizeRuntimeMeta(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'provider' => null,
                'model' => null,
                'status' => null,
                'degraded_mode' => false,
                'used_fallback_model' => false,
                'schema_valid' => true,
                'cache_hit' => false,
                'latency_ms' => null,
                'http_status' => null,
                'attempt' => null,
                'max_attempts' => null,
                'fallback_reason' => null,
                'error_message' => null,
            ];
        }

        return [
            'provider' => $this->normalizeText($value['provider'] ?? null),
            'model' => $this->normalizeText($value['model'] ?? ($value['primary_model'] ?? null)),
            'status' => $this->normalizeText($value['status'] ?? null),
            'degraded_mode' => (bool) ($value['degraded_mode'] ?? false),
            'used_fallback_model' => (bool) ($value['used_fallback_model'] ?? false),
            'schema_valid' => array_key_exists('schema_valid', $value) ? (bool) $value['schema_valid'] : true,
            'cache_hit' => (bool) ($value['cache_hit'] ?? false),
            'latency_ms' => isset($value['latency_ms']) ? (int) $value['latency_ms'] : null,
            'http_status' => isset($value['http_status']) ? (int) $value['http_status'] : null,
            'attempt' => isset($value['attempt']) ? (int) $value['attempt'] : null,
            'max_attempts' => isset($value['max_attempts']) ? (int) $value['max_attempts'] : null,
            'fallback_reason' => $this->normalizeText($value['fallback_reason'] ?? null),
            'error_message' => $this->normalizeText($value['error_message'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeMeta
     */
    private function resolveRuntimeHealth(array $runtimeMeta): string
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

        if (($runtimeMeta['used_fallback_model'] ?? false) === true) {
            return 'fallback_model';
        }

        if (($runtimeMeta['status'] ?? null) === 'success') {
            return 'healthy';
        }

        return 'unknown';
    }
}
