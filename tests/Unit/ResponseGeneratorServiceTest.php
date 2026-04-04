<?php

namespace Tests\Unit;

use App\Services\AI\LlmClientService;
use App\Services\AI\PromptBuilderService;
use App\Services\AI\ResponseGeneratorService;
use App\Services\Support\JsonSchemaValidatorService;
use Mockery;
use Tests\TestCase;

class ResponseGeneratorServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_returns_template_fallback_when_reply_generation_throws(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('generateReply')
            ->once()
            ->andThrow(new \RuntimeException('LLM timeout'));

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildReplyPrompt')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $validator = Mockery::mock(JsonSchemaValidatorService::class);

        $service = new ResponseGeneratorService($llm, $promptBuilder, $validator);

        $result = $service->generate([
            'conversation_id' => 1,
            'message_id' => 1,
            'message_text' => 'jadwal hari ini',
            'intent_result' => [
                'intent' => 'tanya_jam',
                'confidence' => 0.98,
            ],
        ]);

        $this->assertTrue($result['is_fallback']);
        $this->assertSame('Baik, saya bantu info jam keberangkatannya ya.', $result['text']);
    }

    public function test_it_normalizes_structured_reply_with_booking_and_crm_context(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('generateReply')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'reply' => 'Baik, boleh kirim jumlah penumpangnya dulu ya?',
                    'tone' => 'ramah',
                    'should_escalate' => false,
                    'handoff_reason' => null,
                    'next_action' => 'answer_question',
                    'data_requests' => [],
                    'used_crm_facts' => ['booking_missing_fields'],
                    'safety_notes' => [],
                ], JSON_THROW_ON_ERROR),
                'is_fallback' => false,
            ]);

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildReplyPrompt')
            ->once()
            ->withArgs(function (array $context): bool {
                return ($context['message_text'] ?? null) === 'saya mau booking'
                    && (($context['crm_context']['booking']['missing_fields'][0] ?? null) === 'passenger_count')
                    && (($context['intent_result']['intent'] ?? null) === 'booking');
            })
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new ResponseGeneratorService(
            $llm,
            $promptBuilder,
            new JsonSchemaValidatorService,
        );

        $result = $service->generate([
            'conversation_id' => 1,
            'message_id' => 1,
            'message_text' => 'saya mau booking',
            'intent_result' => [
                'intent' => 'booking',
                'confidence' => 0.92,
                'should_escalate' => false,
            ],
            'crm_context' => [
                'conversation' => [
                    'needs_human' => false,
                ],
                'booking' => [
                    'missing_fields' => ['passenger_count'],
                ],
                'business_flags' => [
                    'needs_human_followup' => false,
                ],
                'escalation' => [
                    'has_open_escalation' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['is_fallback']);
        $this->assertSame('Baik, boleh kirim jumlah penumpangnya dulu ya?', $result['text']);
        $this->assertSame('ask_missing_data', $result['next_action']);
        $this->assertSame(['passenger_count'], $result['data_requests']);
        $this->assertSame(['booking_missing_fields'], $result['used_crm_facts']);
        $this->assertFalse($result['should_escalate']);
        $this->assertSame('llm_reply_with_crm_context', $result['meta']['source']);
    }

    public function test_it_applies_sensitive_fallback_when_model_result_is_invalid_and_human_followup_is_required(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('generateReply')
            ->once()
            ->andReturn([
                'text' => '',
                'is_fallback' => false,
            ]);

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildReplyPrompt')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new ResponseGeneratorService(
            $llm,
            $promptBuilder,
            new JsonSchemaValidatorService,
        );

        $result = $service->generate([
            'conversation_id' => 1,
            'message_id' => 1,
            'message_text' => 'tolong admin saja',
            'intent_result' => [
                'intent' => 'human_handoff',
                'confidence' => 0.97,
                'should_escalate' => true,
            ],
            'crm_context' => [
                'conversation' => [
                    'needs_human' => true,
                ],
                'business_flags' => [
                    'needs_human_followup' => true,
                    'admin_takeover_active' => true,
                ],
                'escalation' => [
                    'has_open_escalation' => true,
                ],
            ],
            'admin_takeover' => true,
        ]);

        $this->assertTrue($result['is_fallback']);
        $this->assertTrue($result['should_escalate']);
        $this->assertSame('Sensitive or human-required case', $result['handoff_reason']);
        $this->assertSame('handoff_admin', $result['next_action']);
        $this->assertSame('sensitive_fallback', $result['meta']['source']);
        $this->assertTrue($result['meta']['force_handoff']);
    }
}
