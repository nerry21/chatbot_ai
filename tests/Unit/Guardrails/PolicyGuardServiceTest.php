<?php

namespace Tests\Unit\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;
use App\Services\Chatbot\Guardrails\PolicyGuardService;
use Tests\TestCase;

class PolicyGuardServiceTest extends TestCase
{
    public function test_it_hydrates_follow_up_entities_from_resolved_context(): void
    {
        $result = app(PolicyGuardService::class)->guard(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::TanyaJam->value,
                'confidence' => 0.89,
                'uses_previous_context' => true,
            ],
            entityResult: [
                'departure_date' => '2026-03-29',
                'departure_time' => '10:00',
            ],
            understandingResult: [
                'uses_previous_context' => true,
            ],
            resolvedContext: [
                'context_dependency_detected' => true,
                'last_destination' => 'Pekanbaru',
            ],
            conversationState: [],
        );

        $this->assertSame('Pekanbaru', $result['entity_result']['destination']);
        $this->assertSame(['destination'], $result['meta']['hydrated_context_fields']);
        $this->assertSame('allow', $result['meta']['action']);
    }

    public function test_it_turns_ambiguous_understanding_into_safe_clarification(): void
    {
        $result = app(PolicyGuardService::class)->guard(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::Unknown->value,
                'confidence' => 0.22,
                'needs_clarification' => false,
            ],
            entityResult: [],
            understandingResult: [],
            resolvedContext: [],
            conversationState: [],
        );

        $this->assertSame('clarify', $result['meta']['action']);
        $this->assertTrue($result['intent_result']['needs_clarification']);
        $this->assertNotEmpty($result['intent_result']['clarification_question']);
    }

    public function test_it_keeps_booking_follow_up_under_low_confidence_inside_booking_context(): void
    {
        $result = app(PolicyGuardService::class)->guard(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::Unknown->value,
                'confidence' => 0.28,
            ],
            entityResult: [],
            understandingResult: [],
            resolvedContext: [
                'current_topic' => 'booking_follow_up',
                'context_dependency_detected' => true,
            ],
            conversationState: [
                'booking_expected_input' => 'travel_date',
            ],
        );

        $this->assertSame(IntentType::Booking->value, $result['intent_result']['intent']);
        $this->assertTrue($result['intent_result']['needs_clarification']);
        $this->assertSame('clarify', $result['meta']['action']);
    }

    public function test_it_blocks_auto_reply_when_admin_takeover_is_active(): void
    {
        $result = app(PolicyGuardService::class)->guard(
            conversation: new Conversation(['handoff_mode' => 'admin', 'handoff_admin_id' => 9]),
            intentResult: [
                'intent' => IntentType::Booking->value,
                'confidence' => 0.94,
            ],
            entityResult: [
                'destination' => 'Pekanbaru',
            ],
            understandingResult: [],
            resolvedContext: [],
            conversationState: [
                'admin_takeover' => true,
            ],
        );

        $this->assertTrue($result['meta']['block_auto_reply']);
        $this->assertSame('blocked_takeover', $result['meta']['action']);
        $this->assertSame(IntentType::HumanHandoff->value, $result['intent_result']['intent']);
        $this->assertTrue($result['intent_result']['handoff_recommended']);
    }
}
