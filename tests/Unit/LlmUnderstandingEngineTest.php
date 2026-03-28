<?php

namespace Tests\Unit;

use App\Enums\IntentType;
use App\Services\AI\LlmClientService;
use App\Services\AI\LlmUnderstandingEngine;
use App\Services\AI\UnderstandingOutputParserService;
use App\Services\AI\UnderstandingPromptBuilderService;
use Mockery;
use Tests\TestCase;

class LlmUnderstandingEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_returns_structured_result_from_llm_output(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('understandMessage')
            ->once()
            ->with(Mockery::on(function (array $context): bool {
                return ($context['message_text'] ?? null) === 'besok 2 orang dari Pasir Pengaraian ke Pekanbaru'
                    && in_array(IntentType::Booking->value, $context['allowed_intents'] ?? [], true);
            }))
            ->andReturn([
                'intent' => 'booking',
                'sub_intent' => 'collecting_trip_details',
                'confidence' => 0.93,
                'uses_previous_context' => true,
                'entities' => [
                    'origin' => 'Pasir Pengaraian',
                    'destination' => 'Pekanbaru',
                    'travel_date' => '2026-03-29',
                    'departure_time' => null,
                    'passenger_count' => 2,
                    'passenger_name' => null,
                    'seat_number' => null,
                    'payment_method' => null,
                ],
                'needs_clarification' => true,
                'clarification_question' => 'Untuk jam keberangkatannya mau yang mana ya?',
                'handoff_recommended' => false,
                'reasoning_summary' => 'User ingin booking dan masih perlu jam keberangkatan.',
            ]);

        $promptBuilder = Mockery::mock(UnderstandingPromptBuilderService::class);
        $promptBuilder->shouldReceive('build')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new LlmUnderstandingEngine(
            $llm,
            $promptBuilder,
            app(UnderstandingOutputParserService::class),
        );

        $result = $service->understand(
            latestMessage: 'besok 2 orang dari Pasir Pengaraian ke Pekanbaru',
            recentHistory: [
                ['direction' => 'inbound', 'text' => 'halo'],
            ],
            conversationState: [
                'booking_intent_status' => 'asking_departure_date',
            ],
            knownEntities: [
                'origin' => 'Pasir Pengaraian',
            ],
            allowedIntents: [IntentType::Booking, IntentType::Unknown],
            conversationId: 10,
            messageId: 20,
        );

        $this->assertSame(IntentType::Booking->value, $result->intent);
        $this->assertSame('collecting_trip_details', $result->subIntent);
        $this->assertSame('Pasir Pengaraian', $result->entities->origin);
        $this->assertSame('Pekanbaru', $result->entities->destination);
        $this->assertTrue($result->needsClarification);
        $this->assertSame('Untuk jam keberangkatannya mau yang mana ya?', $result->clarificationQuestion);
    }

    public function test_it_returns_safe_fallback_for_blank_message(): void
    {
        $llm = Mockery::mock(LlmClientService::class);
        $promptBuilder = Mockery::mock(UnderstandingPromptBuilderService::class);

        $service = new LlmUnderstandingEngine(
            $llm,
            $promptBuilder,
            app(UnderstandingOutputParserService::class),
        );

        $result = $service->understand(
            latestMessage: '   ',
            allowedIntents: [IntentType::Booking, IntentType::Unknown],
        );

        $this->assertSame(IntentType::Unknown->value, $result->intent);
        $this->assertTrue($result->needsClarification);
        $this->assertSame('Boleh dijelaskan lagi kebutuhan perjalanannya?', $result->clarificationQuestion);
    }
}
