<?php

namespace Tests\Unit;

use App\Services\AI\IntentClassifierService;
use App\Services\AI\ResponseGeneratorService;
use App\Services\AI\ResponseValidationService;
use App\Services\AI\RuleEngineService;
use App\Services\Booking\BookingConfirmationService;
use App\Services\Booking\RouteValidationService;
use App\Services\Chatbot\ReplyOrchestratorService;
use Mockery;
use Tests\TestCase;

class ReplyOrchestratorServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_orchestrates_intent_reply_rules_and_validation(): void
    {
        $intentService = Mockery::mock(IntentClassifierService::class);
        $intentService->shouldReceive('classify')
            ->once()
            ->andReturn([
                'intent' => 'booking',
                'confidence' => 0.9,
                'should_escalate' => false,
            ]);

        $replyService = Mockery::mock(ResponseGeneratorService::class);
        $replyService->shouldReceive('generate')
            ->once()
            ->withArgs(function (array $context, array $intentResult): bool {
                return ($context['message_text'] ?? null) === 'saya mau booking'
                    && ($intentResult['intent'] ?? null) === 'booking';
            })
            ->andReturn([
                'reply' => 'Baik, saya bantu bookingnya.',
                'text' => 'Baik, saya bantu bookingnya.',
                'next_action' => 'answer_question',
            ]);

        $ruleEngine = Mockery::mock(RuleEngineService::class);
        $ruleEngine->shouldReceive('evaluateOperationalRules')
            ->once()
            ->andReturn([
                'rule_hits' => ['booking_missing_fields'],
                'actions' => [
                    'force_handoff' => false,
                    'force_safe_fallback' => false,
                    'force_ask_missing_data' => true,
                    'block_claims' => [],
                ],
            ]);
        $ruleEngine->shouldReceive('buildSafeFallbackFromRules')
            ->once()
            ->andReturn([
                'reply' => 'Baik, mohon lengkapi pickup_location dulu.',
                'next_action' => 'ask_missing_data',
                'should_escalate' => false,
                'meta' => [
                    'source' => 'rule_engine_missing_data_fallback',
                ],
            ]);

        $responseValidation = Mockery::mock(ResponseValidationService::class);
        $responseValidation->shouldReceive('validateAndFinalize')
            ->once()
            ->andReturn([
                'reply' => 'Baik, mohon lengkapi pickup_location dulu.',
                'text' => 'Baik, mohon lengkapi pickup_location dulu.',
                'next_action' => 'ask_missing_data',
                'should_escalate' => false,
                'handoff_reason' => null,
                'meta' => [
                    'source' => 'rule_engine_missing_data_fallback',
                    'decision_source' => 'rule_engine_missing_data_fallback',
                ],
            ]);

        $service = new ReplyOrchestratorService(
            $intentService,
            $replyService,
            $ruleEngine,
            $responseValidation,
            Mockery::mock(BookingConfirmationService::class),
            Mockery::mock(RouteValidationService::class),
        );

        $result = $service->orchestrate([
            'message_text' => 'saya mau booking',
        ]);

        $this->assertSame('booking', $result['intent_result']['intent']);
        $this->assertSame(['booking_missing_fields'], $result['rule_evaluation']['rule_hits']);
        $this->assertSame('ask_missing_data', $result['reply_result']['next_action']);

        $audit = $service->buildAuditSnapshot($result);

        $this->assertSame('booking', $audit['intent']);
        $this->assertSame(0.9, $audit['intent_confidence']);
        $this->assertFalse($audit['should_escalate']);
        $this->assertSame('ask_missing_data', $audit['next_action']);
        $this->assertSame(['booking_missing_fields'], $audit['rule_hits']);
        $this->assertSame('rule_engine_missing_data_fallback', $audit['reply_source']);
    }

    public function test_it_uses_provided_intent_and_reply_when_present(): void
    {
        $intentService = Mockery::mock(IntentClassifierService::class);
        $intentService->shouldNotReceive('classify');

        $replyService = Mockery::mock(ResponseGeneratorService::class);
        $replyService->shouldNotReceive('generate');

        $ruleEngine = Mockery::mock(RuleEngineService::class);
        $ruleEngine->shouldReceive('evaluateOperationalRules')
            ->once()
            ->andReturn([
                'rule_hits' => [],
                'actions' => [
                    'force_handoff' => false,
                    'force_safe_fallback' => false,
                    'force_ask_missing_data' => false,
                    'block_claims' => [],
                ],
            ]);
        $ruleEngine->shouldNotReceive('buildSafeFallbackFromRules');

        $responseValidation = Mockery::mock(ResponseValidationService::class);
        $responseValidation->shouldReceive('validateAndFinalize')
            ->once()
            ->andReturn([
                'reply' => 'Baik, saya bantu cek.',
                'text' => 'Baik, saya bantu cek.',
                'next_action' => 'answer_question',
                'should_escalate' => false,
                'handoff_reason' => null,
                'meta' => [
                    'source' => 'llm_reply_with_crm_context',
                ],
            ]);

        $service = new ReplyOrchestratorService(
            $intentService,
            $replyService,
            $ruleEngine,
            $responseValidation,
            Mockery::mock(BookingConfirmationService::class),
            Mockery::mock(RouteValidationService::class),
        );

        $result = $service->orchestrate([
            'intent_result' => ['intent' => 'tanya_harga', 'confidence' => 0.8],
            'reply_result' => ['reply' => 'Baik, saya bantu cek.'],
        ]);

        $this->assertSame('tanya_harga', $result['intent_result']['intent']);
        $this->assertSame('Baik, saya bantu cek.', $result['reply_result']['reply']);
    }
}
