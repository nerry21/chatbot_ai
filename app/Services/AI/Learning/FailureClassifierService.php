<?php

namespace App\Services\AI\Learning;

use App\Data\AI\LearningSignalPayload;
use App\Enums\IntentType;
use App\Enums\LearningFailureType;

class FailureClassifierService
{
    /**
     * @return array{
     *     failure_type: LearningFailureType|null,
     *     signals: array<string, mixed>,
     *     should_store_case_memory: bool
     * }
     */
    public function classify(LearningSignalPayload $payload): array
    {
        $understanding = $payload->understandingResult;
        $resolvedContext = $this->arrayValue($payload->contextSnapshot, 'resolved_context');
        $policyGuard = $this->arrayValue($payload->classifierContext, 'policy_guard');
        $hallucinationGuard = $this->arrayValue($payload->classifierContext, 'hallucination_guard');
        $replyGuard = $this->arrayValue($payload->classifierContext, 'reply_guard');
        $entityResult = $this->arrayValue($payload->classifierContext, 'entity_result');

        $failureType = null;

        if (
            $payload->adminTakeoverActive
            || ($payload->chosenAction === 'admin_takeover_suppressed')
            || (($policyGuard['action'] ?? null) === 'blocked_takeover')
        ) {
            $failureType = LearningFailureType::TakeoverConflict;
        } elseif (($hallucinationGuard['blocked'] ?? false) === true) {
            $failureType = LearningFailureType::UnsafeHallucinationAttempt;
        } elseif (
            (($replyGuard['unavailable_repeat_blocked'] ?? false) === true)
            || (($replyGuard['state_repeat_rewritten'] ?? false) === true)
            || ($payload->outboundSent === false && (($replyGuard['close_intent_detected'] ?? false) === false) && trim($payload->finalResponse) !== '')
        ) {
            $failureType = LearningFailureType::RepetitiveReply;
        } elseif (
            (($understanding['uses_previous_context'] ?? false) === true)
            && ! $this->hasResolvedConversationMemory($resolvedContext)
        ) {
            $failureType = LearningFailureType::WrongContextResolution;
        } elseif (
            (($understanding['needs_clarification'] ?? false) === true)
            && trim((string) ($understanding['clarification_question'] ?? '')) === ''
        ) {
            $failureType = LearningFailureType::PoorClarification;
        } elseif ($this->hasMissingRequiredEntity($understanding, $entityResult)) {
            $failureType = LearningFailureType::MissingEntity;
        } elseif ($this->isTooEarlyFallback($payload, $understanding)) {
            $failureType = LearningFailureType::TooEarlyFallback;
        } elseif ($this->looksLikeWrongIntent($understanding, $policyGuard)) {
            $failureType = LearningFailureType::WrongIntent;
        }

        return [
            'failure_type' => $failureType,
            'signals' => [
                'confidence' => round((float) ($understanding['confidence'] ?? 0.0), 4),
                'intent' => (string) ($understanding['intent'] ?? ''),
                'needs_clarification' => (bool) ($understanding['needs_clarification'] ?? false),
                'uses_previous_context' => (bool) ($understanding['uses_previous_context'] ?? false),
                'fallback_used' => $payload->fallbackUsed,
                'handoff_happened' => $payload->handoffHappened,
                'admin_takeover_active' => $payload->adminTakeoverActive,
                'policy_action' => $policyGuard['action'] ?? null,
                'policy_reasons' => $policyGuard['reasons'] ?? [],
                'hallucination_action' => $hallucinationGuard['action'] ?? null,
                'reply_guard' => [
                    'unavailable_repeat_blocked' => (bool) ($replyGuard['unavailable_repeat_blocked'] ?? false),
                    'state_repeat_rewritten' => (bool) ($replyGuard['state_repeat_rewritten'] ?? false),
                    'close_intent_detected' => (bool) ($replyGuard['close_intent_detected'] ?? false),
                ],
            ],
            'should_store_case_memory' => $this->shouldStoreCaseMemory($payload, $failureType),
        ];
    }

    /**
     * @param  array<string, mixed>  $understanding
     */
    private function hasMissingRequiredEntity(array $understanding, array $entityResult): bool
    {
        $intent = (string) ($understanding['intent'] ?? '');
        $needsClarification = (bool) ($understanding['needs_clarification'] ?? false);

        if (! $needsClarification) {
            return false;
        }

        $bookingLikeIntents = [
            IntentType::Booking->value,
            IntentType::BookingConfirm->value,
            IntentType::PriceInquiry->value,
            IntentType::ScheduleInquiry->value,
            IntentType::TanyaHarga->value,
            IntentType::TanyaJam->value,
        ];

        if (! in_array($intent, $bookingLikeIntents, true)) {
            return false;
        }

        $pickup = trim((string) ($entityResult['pickup_location'] ?? ''));
        $destination = trim((string) ($entityResult['destination'] ?? ''));
        $date = trim((string) ($entityResult['departure_date'] ?? ''));
        $time = trim((string) ($entityResult['departure_time'] ?? ''));

        return ($pickup === '' && $destination === '') || ($date === '' && $time === '');
    }

    /**
     * @param  array<string, mixed>  $understanding
     */
    private function isTooEarlyFallback(LearningSignalPayload $payload, array $understanding): bool
    {
        if (! $payload->fallbackUsed || $payload->handoffHappened) {
            return false;
        }

        if (($understanding['needs_clarification'] ?? false) === true) {
            return false;
        }

        return (float) ($understanding['confidence'] ?? 0.0) >= (float) config(
            'chatbot.continuous_improvement.case_memory_min_confidence',
            0.75,
        );
    }

    /**
     * @param  array<string, mixed>  $understanding
     * @param  array<string, mixed>  $policyGuard
     */
    private function looksLikeWrongIntent(array $understanding, array $policyGuard): bool
    {
        $intent = (string) ($understanding['intent'] ?? '');
        $confidence = (float) ($understanding['confidence'] ?? 0.0);

        if (($policyGuard['action'] ?? null) === 'clarify' && $confidence < 0.60) {
            return true;
        }

        return $confidence < (float) config('chatbot.ai_quality.low_confidence_threshold', 0.40)
            && in_array($intent, [
                IntentType::Unknown->value,
                IntentType::OutOfScope->value,
                IntentType::PertanyaanTidakTerjawab->value,
            ], true);
    }

    /**
     * @param  array<string, mixed>  $resolvedContext
     */
    private function hasResolvedConversationMemory(array $resolvedContext): bool
    {
        foreach ([
            'last_origin',
            'last_destination',
            'last_travel_date',
            'last_departure_time',
            'current_topic',
        ] as $key) {
            if (trim((string) ($resolvedContext[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function shouldStoreCaseMemory(
        LearningSignalPayload $payload,
        ?LearningFailureType $failureType,
    ): bool {
        if (! config('chatbot.continuous_improvement.enabled', true)) {
            return false;
        }

        if (! config('chatbot.continuous_improvement.store_case_memory', true)) {
            return false;
        }

        if ($failureType !== null || $payload->fallbackUsed || $payload->handoffHappened || $payload->adminTakeoverActive) {
            return false;
        }

        if (! $payload->outboundSent || trim($payload->finalResponse) === '') {
            return false;
        }

        $confidence = (float) ($payload->understandingResult['confidence'] ?? 0.0);

        return $confidence >= (float) config('chatbot.continuous_improvement.case_memory_min_confidence', 0.75);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
