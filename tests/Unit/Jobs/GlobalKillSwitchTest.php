<?php

namespace Tests\Unit\Jobs;

use App\Enums\AuditActionType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\LlmAgentOrchestratorService;
use App\Services\Chatbot\LlmAgentRateLimiter;
use App\Services\Chatbot\TravelWhatsAppPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GlobalKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_processing_when_global_kill_switch_disabled(): void
    {
        config(['chatbot.global_auto_reply_enabled' => false]);

        [$conversation, $message] = $this->seedInboundMessage();

        $pipelineMock = Mockery::mock(TravelWhatsAppPipelineService::class);
        $pipelineMock->shouldNotReceive('handleIfApplicable');
        $this->app->instance(TravelWhatsAppPipelineService::class, $pipelineMock);

        $job = new ProcessIncomingWhatsAppMessage(
            messageId: $message->id,
            conversationId: $conversation->id,
        );

        $this->app->call([$job, 'handle']);

        $audit = AuditLog::query()
            ->where('conversation_id', $conversation->id)
            ->where('action_type', AuditActionType::BotReplySkippedTakeover->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Expected audit log entry for kill switch skip.');
        $this->assertSame('global_kill_switch', $audit->context['reason'] ?? null);
        $this->assertSame($message->id, $audit->context['message_id'] ?? null);

        $outboundCount = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->count();

        $this->assertSame(0, $outboundCount, 'No outbound message should be created when kill switch is active.');
    }

    public function test_processes_normally_when_global_kill_switch_enabled(): void
    {
        config(['chatbot.global_auto_reply_enabled' => true]);

        [$conversation, $message] = $this->seedInboundMessage();

        $pipelineMock = Mockery::mock(TravelWhatsAppPipelineService::class);
        $pipelineMock->shouldReceive('handleIfApplicable')
            ->once()
            ->andReturn(true);
        $this->app->instance(TravelWhatsAppPipelineService::class, $pipelineMock);

        $job = new ProcessIncomingWhatsAppMessage(
            messageId: $message->id,
            conversationId: $conversation->id,
        );

        $this->app->call([$job, 'handle']);

        $killSwitchAuditCount = AuditLog::query()
            ->where('conversation_id', $conversation->id)
            ->where('action_type', AuditActionType::BotReplySkippedTakeover->value)
            ->count();

        $this->assertSame(0, $killSwitchAuditCount, 'No skip audit should be recorded when kill switch is enabled.');
    }

    public function test_rate_limit_hit_falls_back_to_rule_based(): void
    {
        config([
            'chatbot.global_auto_reply_enabled' => true,
            'chatbot.agent.enabled' => true,
        ]);

        [$conversation, $message] = $this->seedInboundMessage();

        $rateLimiterMock = Mockery::mock(LlmAgentRateLimiter::class);
        $rateLimiterMock->shouldReceive('shouldAllow')->once()->andReturn(false);
        $this->app->instance(LlmAgentRateLimiter::class, $rateLimiterMock);

        $orchestratorMock = Mockery::mock(LlmAgentOrchestratorService::class);
        $orchestratorMock->shouldNotReceive('handle');
        $this->app->instance(LlmAgentOrchestratorService::class, $orchestratorMock);

        $pipelineMock = Mockery::mock(TravelWhatsAppPipelineService::class);
        $pipelineMock->shouldReceive('handleIfApplicable')->once()->andReturn(true);
        $this->app->instance(TravelWhatsAppPipelineService::class, $pipelineMock);

        $job = new ProcessIncomingWhatsAppMessage(
            messageId: $message->id,
            conversationId: $conversation->id,
        );

        $this->app->call([$job, 'handle']);

        $this->addToAssertionCount(1);
    }

    public function test_rate_limit_allows_normal_flow(): void
    {
        config([
            'chatbot.global_auto_reply_enabled' => true,
            'chatbot.agent.enabled' => true,
        ]);

        [$conversation, $message] = $this->seedInboundMessage();

        $rateLimiterMock = Mockery::mock(LlmAgentRateLimiter::class);
        $rateLimiterMock->shouldReceive('shouldAllow')->once()->andReturn(true);
        $this->app->instance(LlmAgentRateLimiter::class, $rateLimiterMock);

        $orchestratorMock = Mockery::mock(LlmAgentOrchestratorService::class);
        $orchestratorMock->shouldReceive('handle')->once()->andReturn([
            'reply_text' => '',
            'tools_called' => [],
            'iterations' => 1,
            'should_handoff' => false,
            'meta' => [],
        ]);
        $this->app->instance(LlmAgentOrchestratorService::class, $orchestratorMock);

        $pipelineMock = Mockery::mock(TravelWhatsAppPipelineService::class);
        $pipelineMock->shouldNotReceive('handleIfApplicable');
        $this->app->instance(TravelWhatsAppPipelineService::class, $pipelineMock);

        $job = new ProcessIncomingWhatsAppMessage(
            messageId: $message->id,
            conversationId: $conversation->id,
        );

        $this->app->call([$job, 'handle']);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array{0: Conversation, 1: ConversationMessage}
     */
    private function seedInboundMessage(): array
    {
        $customer = Customer::create([
            'name' => 'Kill Switch Customer',
            'phone_e164' => '+628123450999',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subMinutes(5),
            'last_message_at' => now(),
            'source_app' => 'whatsapp',
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'halo, mau cek harga ujung batu pekanbaru',
            'raw_payload' => [],
            'sent_at' => now(),
        ]);

        return [$conversation, $message];
    }
}
