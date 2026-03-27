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
}
