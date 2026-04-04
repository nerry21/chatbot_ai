<?php

namespace Tests\Unit;

use App\Services\AI\IntentClassifierService;
use App\Services\AI\LlmClientService;
use App\Services\AI\PromptBuilderService;
use App\Services\Support\JsonSchemaValidatorService;
use Mockery;
use Tests\TestCase;

class IntentClassifierServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_normalizes_intent_with_crm_context_low_confidence_and_admin_takeover(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('classifyIntent')
            ->once()
            ->andReturn([
                'intent' => 'tanya_harga',
                'confidence' => 0.22,
                'should_escalate' => false,
                'entities' => ['destination' => 'Duri'],
            ]);

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildIntentPrompt')
            ->once()
            ->withArgs(function (array $input): bool {
                return ($input['message_text'] ?? null) === 'berapa harga ke duri'
                    && ($input['latest_message'] ?? null) === 'berapa harga ke duri'
                    && ($input['conversation_summary'] ?? null) === 'Customer sebelumnya menanyakan rute.'
                    && ($input['admin_takeover'] ?? false) === true
                    && (($input['recent_messages'][0]['direction'] ?? null) === 'inbound');
            })
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new IntentClassifierService(
            $llm,
            $promptBuilder,
            new JsonSchemaValidatorService,
        );

        $result = $service->classify([
            'latest_message' => 'berapa harga ke duri',
            'recent_history' => [
                ['role' => 'customer', 'text' => 'halo'],
                ['role' => 'assistant', 'text' => 'Ada yang bisa kami bantu?'],
            ],
            'conversation_summary' => 'Customer sebelumnya menanyakan rute.',
            'crm_context' => [
                'conversation' => [
                    'needs_human' => false,
                ],
                'business_flags' => [
                    'needs_human_followup' => false,
                ],
            ],
            'admin_takeover' => true,
        ]);

        $this->assertSame('tanya_harga', $result['intent']);
        $this->assertSame(0.22, $result['confidence']);
        $this->assertTrue($result['should_escalate']);
        $this->assertSame(['destination' => 'Duri'], $result['entities']);
        $this->assertSame('llm_with_crm_context', $result['source']);
        $this->assertSame('Low confidence intent detection; Admin takeover active', $result['reasoning_short']);
    }

    public function test_it_forces_should_escalate_when_crm_marks_human_followup(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('classifyIntent')
            ->once()
            ->andReturn([
                'intent' => 'booking',
                'confidence' => 0.88,
                'should_escalate' => false,
                'reasoning_short' => 'Customer ingin lanjut booking.',
            ]);

        $promptBuilder = Mockery::mock(PromptBuilderService::class);
        $promptBuilder->shouldReceive('buildIntentPrompt')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new IntentClassifierService(
            $llm,
            $promptBuilder,
            new JsonSchemaValidatorService,
        );

        $result = $service->classify([
            'message_text' => 'saya mau lanjut booking',
            'crm_context' => [
                'conversation' => [
                    'needs_human' => true,
                ],
            ],
        ]);

        $this->assertSame('booking', $result['intent']);
        $this->assertTrue($result['should_escalate']);
        $this->assertSame('Customer ingin lanjut booking.', $result['reasoning_short']);
    }
}
