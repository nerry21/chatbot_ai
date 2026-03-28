<?php

namespace Tests\Unit;

use App\Enums\GroundedResponseMode;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\GroundedResponseFactsBuilderService;
use Tests\TestCase;

class GroundedResponseFactsBuilderServiceTest extends TestCase
{
    public function test_it_builds_grounded_facts_for_direct_answer_with_official_schedule(): void
    {
        $conversation = new Conversation(['id' => 10, 'handoff_mode' => 'bot']);
        $conversation->setRelation('customer', new Customer(['name' => 'Nerry']));
        $message = new ConversationMessage([
            'id' => 20,
            'message_text' => 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?',
        ]);

        $facts = app(GroundedResponseFactsBuilderService::class)->build(
            conversation: $conversation,
            message: $message,
            intentResult: [
                'intent' => 'tanya_jam',
                'confidence' => 0.94,
                'needs_clarification' => false,
                'handoff_recommended' => false,
            ],
            entityResult: [
                'destination' => 'Pekanbaru',
                'departure_date' => '2026-03-29',
                'departure_time' => '10:00',
            ],
            replyTemplate: [
                'text' => '',
                'meta' => [
                    'source' => 'ai_reply',
                    'action' => 'compose_ai_reply',
                    'requires_composition' => true,
                ],
            ],
            aiContext: [
                'resolved_context' => [
                    'last_destination' => 'Pekanbaru',
                ],
                'conversation_summary' => 'Customer sedang menanyakan jadwal ke Pekanbaru.',
            ],
            bookingDecision: [
                'action' => 'compose_ai_reply',
                'booking_status' => 'draft',
            ],
        );

        $this->assertSame(GroundedResponseMode::DirectAnswer, $facts->mode);
        $this->assertSame('Nerry', $facts->customerName);
        $this->assertTrue($facts->officialFacts['requested_schedule']['available']);
        $this->assertSame('10:00', $facts->officialFacts['requested_schedule']['travel_time']);
        $this->assertSame('Pekanbaru', $facts->officialFacts['route']['destination']);
    }

    public function test_it_switches_to_clarification_mode_when_understanding_requires_it(): void
    {
        $conversation = new Conversation(['id' => 10, 'handoff_mode' => 'bot']);
        $message = new ConversationMessage([
            'id' => 21,
            'message_text' => 'besok ada?',
        ]);

        $facts = app(GroundedResponseFactsBuilderService::class)->build(
            conversation: $conversation,
            message: $message,
            intentResult: [
                'intent' => 'booking',
                'confidence' => 0.55,
                'needs_clarification' => true,
                'clarification_question' => 'Izin Bapak/Ibu, rute yang ingin dicek yang mana ya?',
            ],
            entityResult: [],
            replyTemplate: [
                'text' => '',
                'meta' => [
                    'source' => 'ai_reply',
                    'action' => 'compose_ai_reply',
                    'requires_composition' => true,
                ],
            ],
        );

        $this->assertSame(GroundedResponseMode::ClarificationQuestion, $facts->mode);
        $this->assertSame(
            'Izin Bapak/Ibu, rute yang ingin dicek yang mana ya?',
            $facts->officialFacts['suggested_follow_up'],
        );
    }
}
