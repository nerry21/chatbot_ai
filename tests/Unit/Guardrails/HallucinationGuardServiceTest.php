<?php

namespace Tests\Unit\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;
use App\Services\Chatbot\Guardrails\HallucinationGuardService;
use Tests\TestCase;

class HallucinationGuardServiceTest extends TestCase
{
    public function test_it_blocks_sensitive_schedule_reply_when_not_grounded(): void
    {
        $result = app(HallucinationGuardService::class)->guardReply(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::TanyaJam->value,
                'confidence' => 0.91,
            ],
            reply: [
                'text' => 'Jadwal tersedia jam 05.00, 08.00, dan 10.00 WIB.',
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'ai_reply',
                    'action' => 'pass_through',
                ],
            ],
            context: [],
        );

        $this->assertTrue($result['meta']['blocked']);
        $this->assertSame('clarify', $result['meta']['action']);
        $this->assertSame('guard.hallucination', $result['reply']['meta']['source']);
        $this->assertTrue($result['intent_result']['needs_clarification']);
    }

    public function test_it_allows_non_sensitive_general_reply(): void
    {
        $result = app(HallucinationGuardService::class)->guardReply(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::Greeting->value,
                'confidence' => 0.97,
            ],
            reply: [
                'text' => 'Halo Bapak/Ibu, ada yang bisa kami bantu?',
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'ai_reply',
                    'action' => 'pass_through',
                ],
            ],
            context: [],
        );

        $this->assertFalse($result['meta']['blocked']);
        $this->assertSame('allow', $result['meta']['action']);
    }

    public function test_it_handoffs_promo_or_policy_claims_without_grounding(): void
    {
        $result = app(HallucinationGuardService::class)->guardReply(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::Unknown->value,
                'confidence' => 0.61,
            ],
            reply: [
                'text' => 'Saat ini ada promo cashback dan kebijakan refund fleksibel.',
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'ai_reply',
                    'action' => 'pass_through',
                ],
            ],
            context: [],
        );

        $this->assertTrue($result['meta']['blocked']);
        $this->assertSame('handoff', $result['meta']['action']);
        $this->assertSame(IntentType::HumanHandoff->value, $result['intent_result']['intent']);
    }
}
