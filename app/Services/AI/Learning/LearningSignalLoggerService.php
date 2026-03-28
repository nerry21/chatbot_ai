<?php

namespace App\Services\AI\Learning;

use App\Data\AI\LearningSignalPayload;
use App\Models\ChatbotLearningSignal;

class LearningSignalLoggerService
{
    public function __construct(
        private readonly FailureClassifierService $classifier,
        private readonly CaseMemoryService $caseMemoryService,
    ) {
    }

    public function logTurn(LearningSignalPayload $payload): ChatbotLearningSignal
    {
        $classification = $this->classifier->classify($payload);
        $failureType = $classification['failure_type'];

        $signal = ChatbotLearningSignal::updateOrCreate(
            ['inbound_message_id' => $payload->inboundMessageId],
            [
                'conversation_id' => $payload->conversationId,
                'outbound_message_id' => $payload->outboundMessageId,
                'user_message' => $payload->userMessage,
                'context_summary' => $payload->contextSummary,
                'context_snapshot' => $payload->contextSnapshot,
                'understanding_result' => $payload->understandingResult,
                'chosen_action' => $payload->chosenAction,
                'grounded_facts' => $payload->groundedFacts,
                'final_response' => $payload->finalResponse !== '' ? $payload->finalResponse : null,
                'final_response_meta' => $payload->finalResponseMeta,
                'resolution_status' => $this->resolveResolutionStatus($payload),
                'fallback_used' => $payload->fallbackUsed,
                'handoff_happened' => $payload->handoffHappened,
                'admin_takeover_active' => $payload->adminTakeoverActive,
                'outbound_sent' => $payload->outboundSent,
                'failure_type' => $failureType?->value,
                'failure_signals' => $classification['signals'],
            ],
        );

        if (($classification['should_store_case_memory'] ?? false) === true) {
            $this->caseMemoryService->rememberFromLearningSignal($signal);
        }

        return $signal;
    }

    private function resolveResolutionStatus(LearningSignalPayload $payload): string
    {
        if ($payload->adminTakeoverActive && ! $payload->outboundSent && trim($payload->finalResponse) === '') {
            return 'suppressed_takeover';
        }

        if ($payload->handoffHappened) {
            return 'handoff';
        }

        if ($payload->fallbackUsed) {
            return 'fallback';
        }

        if ($payload->outboundSent) {
            return 'answered';
        }

        return 'blocked';
    }
}
