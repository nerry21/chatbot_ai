<?php

namespace App\Services\AI;

class UnderstandingCrmHintReducerService
{
    /**
     * Reduksi full CRM snapshot menjadi continuity hints ringan
     * untuk tahap understanding awal.
     *
     * Tujuan:
     * - LLM tetap "berpikir dulu" dari pesan + history
     * - CRM tidak bocor penuh ke tahap awal
     * - Continuity tetap terjaga bila ada booking/escalation/admin takeover
     *
     * @param  array<string, mixed>  $crmContext
     * @return array<string, mixed>
     */
    public function reduce(
        array $crmContext = [],
        ?string $conversationSummary = null,
        bool $adminTakeover = false,
    ): array {
        $customer = is_array($crmContext['customer'] ?? null) ? $crmContext['customer'] : [];
        $hubspot = is_array($crmContext['hubspot'] ?? null) ? $crmContext['hubspot'] : [];
        $lead = is_array($crmContext['lead_pipeline'] ?? null) ? $crmContext['lead_pipeline'] : [];
        $conversation = is_array($crmContext['conversation'] ?? null) ? $crmContext['conversation'] : [];
        $booking = is_array($crmContext['booking'] ?? null) ? $crmContext['booking'] : [];
        $escalation = is_array($crmContext['escalation'] ?? null) ? $crmContext['escalation'] : [];
        $businessFlags = is_array($crmContext['business_flags'] ?? null) ? $crmContext['business_flags'] : [];
        $runtime = is_array($crmContext['runtime'] ?? null) ? $crmContext['runtime'] : [];
        $knownEntities = is_array($runtime['known_entities'] ?? null) ? $runtime['known_entities'] : [];
        $conversationState = is_array($runtime['conversation_state'] ?? null) ? $runtime['conversation_state'] : [];
        $customerMemory = is_array($runtime['customer_memory'] ?? null) ? $runtime['customer_memory'] : [];

        $hints = [
            'hint_version' => 1,
            'mode' => 'crm_hints_only_for_understanding',

            'continuity' => $this->clean([
                'has_summary' => $conversationSummary !== null && trim($conversationSummary) !== '',
                'active_intent' => $this->normalizeText(
                    $conversation['current_intent']
                        ?? $conversationState['current_intent']
                        ?? null
                ),
                'booking_in_progress' => $this->isTruthy(
                    $booking['booking_status'] ?? null
                ) || $this->isTruthy(
                    $conversationState['booking_active'] ?? null
                ) || $this->isTruthy(
                    $conversationState['booking_in_progress'] ?? null
                ),
                'expected_input' => $this->normalizeText(
                    $conversationState['booking_expected_input']
                        ?? $conversationState['expected_input']
                        ?? null
                ),
                'waiting_for' => $this->normalizeText($conversationState['waiting_for'] ?? null),
                'admin_takeover' => $adminTakeover || $this->isTruthy(
                    $conversation['admin_takeover'] ?? $businessFlags['admin_takeover_active'] ?? null
                ),
                'needs_human_followup' => $this->isTruthy(
                    $businessFlags['needs_human_followup']
                        ?? $conversation['needs_human']
                        ?? null
                ),
                'has_open_escalation' => $this->isTruthy($escalation['has_open_escalation'] ?? null),
            ]),

            'customer_profile' => $this->clean([
                'name' => $this->normalizeText($customer['name'] ?? null),
                'is_returning' => $this->isTruthy($businessFlags['customer_is_returning'] ?? null),
                'preferred_pickup' => $this->normalizeText($customer['preferred_pickup'] ?? null),
                'preferred_destination' => $this->normalizeText($customer['preferred_destination'] ?? null),
                'interest_topic' => $this->normalizeText(
                    $hubspot['ai_memory']['customer_interest_topic'] ?? null
                ),
            ]),

            'booking_hint' => $this->clean([
                'status' => $this->normalizeText($booking['booking_status'] ?? null),
                'pickup_location' => $this->normalizeText(
                    $booking['pickup_location']
                        ?? $knownEntities['pickup_location']
                        ?? null
                ),
                'destination' => $this->normalizeText(
                    $booking['destination']
                        ?? $knownEntities['destination']
                        ?? null
                ),
                'departure_date' => $this->normalizeText(
                    $booking['departure_date']
                        ?? $knownEntities['departure_date']
                        ?? null
                ),
                'departure_time' => $this->normalizeText(
                    $booking['departure_time']
                        ?? $knownEntities['departure_time']
                        ?? null
                ),
                'passenger_count' => $this->normalizeInteger(
                    $booking['passenger_count']
                        ?? $knownEntities['passenger_count']
                        ?? null
                ),
                'payment_method' => $this->normalizeText(
                    $booking['payment_method']
                        ?? $knownEntities['payment_method']
                        ?? null
                ),
                'missing_fields' => $this->normalizeStringList($booking['missing_fields'] ?? []),
            ]),

            'lead_hint' => $this->clean([
                'stage' => $this->normalizeText($lead['stage'] ?? null),
                'lifecycle_stage' => $this->normalizeText($hubspot['lifecycle_stage'] ?? null),
                'lead_status' => $this->normalizeText($hubspot['lead_status'] ?? null),
            ]),

            'memory_hint' => $this->clean([
                'last_ai_intent' => $this->normalizeText($hubspot['ai_memory']['last_ai_intent'] ?? null),
                'customer_interest_topic' => $this->normalizeText($hubspot['ai_memory']['customer_interest_topic'] ?? null),
                'needs_human_followup' => $this->isTruthy($hubspot['ai_memory']['needs_human_followup'] ?? null),
                'recent_topic' => $this->normalizeText(
                    $customerMemory['recent_topic']
                        ?? $customerMemory['last_topic']
                        ?? null
                ),
            ]),
        ];

        if ($conversationSummary !== null && trim($conversationSummary) !== '') {
            $hints['conversation_summary_hint'] = trim($conversationSummary);
        }

        return $this->clean($hints);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            $normalized = $this->normalizeText($item);

            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'ya', 'iya'], true);
    }

    /**
     * @return mixed
     */
    private function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $cleaned = [];

        foreach ($value as $key => $item) {
            $normalized = $this->clean($item);

            if ($normalized === null) {
                continue;
            }

            if (is_string($normalized) && trim($normalized) === '') {
                continue;
            }

            if (is_array($normalized) && $normalized === []) {
                continue;
            }

            $cleaned[$key] = $normalized;
        }

        return $cleaned;
    }
}
