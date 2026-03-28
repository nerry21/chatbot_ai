<?php

namespace Tests\Unit;

use App\Data\AI\GroundedResponseFacts;
use App\Enums\GroundedResponseMode;
use App\Services\AI\GroundedResponseComposerService;
use App\Services\AI\GroundedResponsePromptBuilderService;
use App\Services\AI\LlmClientService;
use Mockery;
use Tests\TestCase;

class GroundedResponseComposerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_returns_grounded_direct_answer_from_llm_output(): void
    {
        $facts = new GroundedResponseFacts(
            conversationId: 10,
            messageId: 20,
            mode: GroundedResponseMode::DirectAnswer,
            latestMessageText: 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?',
            customerName: 'Nerry',
            intentResult: ['intent' => 'tanya_jam', 'confidence' => 0.93],
            entityResult: ['destination' => 'Pekanbaru', 'departure_date' => '2026-03-29', 'departure_time' => '10:00'],
            resolvedContext: ['last_destination' => 'Pekanbaru'],
            conversationSummary: 'Customer menanyakan jadwal ke Pekanbaru.',
            adminTakeover: false,
            officialFacts: [
                'requested_schedule' => [
                    'travel_date' => '2026-03-29',
                    'travel_time' => '10:00',
                    'available' => true,
                ],
                'route' => [
                    'destination' => 'Pekanbaru',
                ],
                'suggested_follow_up' => 'Jika ingin, saya bisa bantu lanjut bookingnya.',
            ],
        );

        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('composeGroundedResponse')
            ->once()
            ->with(Mockery::on(function (array $context): bool {
                return ($context['message_text'] ?? null) === 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?'
                    && (($context['grounded_response_facts']['mode'] ?? null) === 'direct_answer');
            }))
            ->andReturn([
                'text' => 'Waalaikumsalam Bapak/Ibu, untuk keberangkatan besok ke Pekanbaru, jadwal pukul 10.00 tersedia. Jika ingin, saya bisa bantu lanjut bookingnya.',
                'mode' => 'direct_answer',
            ]);

        $promptBuilder = Mockery::mock(GroundedResponsePromptBuilderService::class);
        $promptBuilder->shouldReceive('build')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new GroundedResponseComposerService(
            $llm,
            $promptBuilder,
            app(\App\Services\Support\JsonSchemaValidatorService::class),
        );

        $result = $service->compose($facts);

        $this->assertFalse($result->isFallback);
        $this->assertSame(GroundedResponseMode::DirectAnswer, $result->mode);
        $this->assertStringContainsString('Waalaikumsalam', $result->text);
        $this->assertStringContainsString('10.00 tersedia', $result->text);
    }

    public function test_it_uses_safe_clarification_fallback_when_llm_returns_blank(): void
    {
        $facts = new GroundedResponseFacts(
            conversationId: 11,
            messageId: 21,
            mode: GroundedResponseMode::ClarificationQuestion,
            latestMessageText: 'besok ada?',
            customerName: null,
            intentResult: [
                'intent' => 'booking',
                'confidence' => 0.60,
                'clarification_question' => 'Izin Bapak/Ibu, rute yang ingin dicek yang mana ya?',
            ],
            entityResult: [],
            resolvedContext: [],
            conversationSummary: null,
            adminTakeover: false,
            officialFacts: [
                'suggested_follow_up' => 'Izin Bapak/Ibu, rute yang ingin dicek yang mana ya?',
            ],
        );

        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('composeGroundedResponse')
            ->once()
            ->andReturn([
                'text' => '',
                'mode' => 'clarification_question',
            ]);

        $promptBuilder = Mockery::mock(GroundedResponsePromptBuilderService::class);
        $promptBuilder->shouldReceive('build')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new GroundedResponseComposerService(
            $llm,
            $promptBuilder,
            app(\App\Services\Support\JsonSchemaValidatorService::class),
        );

        $result = $service->compose($facts);

        $this->assertTrue($result->isFallback);
        $this->assertSame(GroundedResponseMode::ClarificationQuestion, $result->mode);
        $this->assertSame('Izin Bapak/Ibu, rute yang ingin dicek yang mana ya?', $result->text);
    }

    public function test_it_uses_safe_handoff_fallback_for_handoff_mode(): void
    {
        $facts = new GroundedResponseFacts(
            conversationId: 12,
            messageId: 22,
            mode: GroundedResponseMode::HandoffMessage,
            latestMessageText: 'tolong admin saja',
            customerName: null,
            intentResult: ['intent' => 'human_handoff', 'confidence' => 0.98],
            entityResult: [],
            resolvedContext: [],
            conversationSummary: null,
            adminTakeover: false,
            officialFacts: [],
        );

        $llm = Mockery::mock(LlmClientService::class);
        $llm->shouldReceive('composeGroundedResponse')
            ->once()
            ->andThrow(new \RuntimeException('simulated failure'));

        $promptBuilder = Mockery::mock(GroundedResponsePromptBuilderService::class);
        $promptBuilder->shouldReceive('build')
            ->once()
            ->andReturn([
                'system' => 'system prompt',
                'user' => 'user prompt',
            ]);

        $service = new GroundedResponseComposerService(
            $llm,
            $promptBuilder,
            app(\App\Services\Support\JsonSchemaValidatorService::class),
        );

        $result = $service->compose($facts);

        $this->assertTrue($result->isFallback);
        $this->assertSame(GroundedResponseMode::HandoffMessage, $result->mode);
        $this->assertStringContainsString('teruskan ke admin', $result->text);
    }
}
