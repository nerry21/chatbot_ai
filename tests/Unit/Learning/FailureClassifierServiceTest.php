<?php

namespace Tests\Unit\Learning;

use App\Data\AI\LearningSignalPayload;
use App\Enums\LearningFailureType;
use App\Services\AI\Learning\FailureClassifierService;
use Tests\TestCase;

class FailureClassifierServiceTest extends TestCase
{
    public function test_it_classifies_hallucination_block_as_unsafe_attempt(): void
    {
        $result = app(FailureClassifierService::class)->classify(
            new LearningSignalPayload(
                conversationId: 10,
                inboundMessageId: 20,
                userMessage: 'besok jam 10 ke pekanbaru ada promo?',
                contextSummary: 'User bertanya jadwal dan promo.',
                contextSnapshot: [
                    'resolved_context' => [
                        'last_destination' => 'Pekanbaru',
                    ],
                ],
                understandingResult: [
                    'intent' => 'tanya_jam',
                    'confidence' => 0.91,
                    'needs_clarification' => false,
                    'uses_previous_context' => true,
                ],
                chosenAction: 'compose_ai_reply',
                groundedFacts: null,
                finalResponse: 'Izin Bapak/Ibu, untuk detail promo kami bantu teruskan ke admin ya.',
                finalResponseMeta: [
                    'source' => 'guard.hallucination',
                    'action' => 'handoff_sensitive_request',
                ],
                fallbackUsed: false,
                handoffHappened: true,
                adminTakeoverActive: false,
                outboundSent: true,
                outboundMessageId: 30,
                classifierContext: [
                    'hallucination_guard' => [
                        'blocked' => true,
                        'action' => 'handoff',
                    ],
                    'policy_guard' => [
                        'action' => 'allow',
                    ],
                    'reply_guard' => [],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                    ],
                ],
            ),
        );

        $this->assertSame(LearningFailureType::UnsafeHallucinationAttempt, $result['failure_type']);
        $this->assertFalse($result['should_store_case_memory']);
    }

    public function test_it_marks_clean_high_confidence_turn_as_case_memory_eligible(): void
    {
        $result = app(FailureClassifierService::class)->classify(
            new LearningSignalPayload(
                conversationId: 11,
                inboundMessageId: 21,
                userMessage: 'besok jam 10 ke Pekanbaru ada?',
                contextSummary: 'User cek jadwal ke Pekanbaru.',
                contextSnapshot: [
                    'resolved_context' => [
                        'last_destination' => 'Pekanbaru',
                    ],
                ],
                understandingResult: [
                    'intent' => 'tanya_jam',
                    'confidence' => 0.93,
                    'needs_clarification' => false,
                    'uses_previous_context' => true,
                ],
                chosenAction: 'check_schedule',
                groundedFacts: [
                    'requested_schedule' => [
                        'available' => true,
                    ],
                ],
                finalResponse: 'Untuk keberangkatan besok ke Pekanbaru, jadwal pukul 10.00 tersedia.',
                finalResponseMeta: [
                    'source' => 'grounded_response_composer',
                ],
                fallbackUsed: false,
                handoffHappened: false,
                adminTakeoverActive: false,
                outboundSent: true,
                outboundMessageId: 31,
                classifierContext: [
                    'policy_guard' => [
                        'action' => 'allow',
                    ],
                    'hallucination_guard' => [
                        'blocked' => false,
                        'action' => 'allow',
                    ],
                    'reply_guard' => [],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                        'departure_date' => '2026-03-29',
                        'departure_time' => '10:00',
                    ],
                ],
            ),
        );

        $this->assertNull($result['failure_type']);
        $this->assertTrue($result['should_store_case_memory']);
    }
}
