<?php

namespace Tests\Unit\Services\Chatbot;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Models\Escalation;
use App\Services\Chatbot\LlmAgentTierRouter;
use App\Services\CRM\JetCrmContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class LlmAgentTierRouterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: Conversation, 2: ConversationMessage}
     */
    private function makeFixture(?string $messageText = null): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => ConversationChannel::WhatsApp,
            'status' => ConversationStatus::Active,
            'started_at' => now(),
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => $messageText ?? 'Halo, saya mau tanya tarif',
        ]);

        return [$customer, $conversation, $message];
    }

    private function bindCrmContextStub(array $context = []): void
    {
        $this->mock(JetCrmContextService::class, function (MockInterface $mock) use ($context) {
            $mock->shouldReceive('resolveForCustomer')->andReturn($context);
        });
    }

    private function addInboundMessages(Conversation $conversation, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Inbound,
                'sender_type' => SenderType::Customer,
                'message_type' => 'text',
                'message_text' => "Pesan extra #{$i}",
            ]);
        }
    }

    private function makeRouter(): LlmAgentTierRouter
    {
        return app(LlmAgentTierRouter::class);
    }

    public function test_returns_tier1_when_no_triggers_match(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertSame(0, $result['score']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_vip_alone_returns_tier1(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key' => 'vip_indicator',
            'value' => 'true',
            'value_type' => 'bool',
            'confidence' => 0.9,
            'source' => 'manual',
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertSame(2, $result['score']);
        $this->assertContains('vip', $result['reasons']);
    }

    public function test_negative_sentiment_alone_returns_tier2(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([
            'ai_memory' => ['ai_sentiment' => 'negative'],
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame(3, $result['score']);
        $this->assertContains('sentiment_negative', $result['reasons']);
    }

    public function test_urgent_sentiment_alone_returns_tier2(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([
            'ai_memory' => ['ai_sentiment' => 'urgent'],
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame(3, $result['score']);
        $this->assertContains('sentiment_negative', $result['reasons']);
    }

    public function test_pending_escalation_alone_returns_tier2(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        Escalation::create([
            'conversation_id' => $conversation->id,
            'reason' => 'refund',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame(3, $result['score']);
        $this->assertContains('escalation_pending', $result['reasons']);
    }

    public function test_old_escalation_does_not_trigger(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        $escalation = Escalation::create([
            'conversation_id' => $conversation->id,
            'reason' => 'refund',
            'priority' => 'normal',
            'status' => 'open',
        ]);
        // Force created_at >24h ago
        $escalation->created_at = now()->subHours(48);
        $escalation->save();

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertNotContains('escalation_pending', $result['reasons']);
    }

    public function test_resolved_escalation_does_not_trigger(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        Escalation::create([
            'conversation_id' => $conversation->id,
            'reason' => 'refund',
            'priority' => 'normal',
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertNotContains('escalation_pending', $result['reasons']);
    }

    public function test_vip_plus_multi_turn_returns_tier2(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key' => 'vip_indicator',
            'value' => 'true',
            'value_type' => 'bool',
            'confidence' => 0.9,
            'source' => 'manual',
        ]);

        // Current message is already 1 inbound; add 5 more to total 6
        $this->addInboundMessages($conversation, 5);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame(3, $result['score']);
        $this->assertContains('vip', $result['reasons']);
        $this->assertContains('multi_turn', $result['reasons']);
    }

    public function test_complaint_keyword_alone_returns_tier1(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture('Saya kecewa banget kak');
        $this->bindCrmContextStub([]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertSame(2, $result['score']);
        $this->assertContains('complaint_keyword', $result['reasons']);
    }

    public function test_complaint_keyword_plus_vip_returns_tier2(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture('Saya kecewa, mohon refund segera');
        $this->bindCrmContextStub([]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key' => 'vip_indicator',
            'value' => 'true',
            'value_type' => 'bool',
            'confidence' => 0.9,
            'source' => 'manual',
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame(4, $result['score']);
        $this->assertContains('complaint_keyword', $result['reasons']);
        $this->assertContains('vip', $result['reasons']);
    }

    public function test_multi_turn_below_threshold_returns_tier1(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        // Current message is already 1 inbound; add 4 more to total 5 (below threshold of 6)
        $this->addInboundMessages($conversation, 4);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertNotContains('multi_turn', $result['reasons']);
    }

    public function test_multi_turn_at_threshold_activates_trigger(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        // Current message is already 1 inbound; add 5 more to total 6
        $this->addInboundMessages($conversation, 5);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertContains('multi_turn', $result['reasons']);
    }

    public function test_fallback_to_tier1_on_exception(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();

        $this->mock(JetCrmContextService::class, function (MockInterface $mock) {
            $mock->shouldReceive('resolveForCustomer')
                ->andThrow(new \RuntimeException('boom'));
        });

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertSame(0, $result['score']);
        $this->assertContains('fallback_error', $result['reasons']);
    }

    public function test_tier1_resolves_to_default_model(): void
    {
        config(['chatbot.agent.model' => 'gpt-default-mini']);
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier1', $result['tier']);
        $this->assertSame('gpt-default-mini', $result['model']);
    }

    public function test_tier2_resolves_to_configured_model(): void
    {
        config(['chatbot.agent.tier2_model' => 'gpt-test']);
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([
            'ai_memory' => ['ai_sentiment' => 'negative'],
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertSame('tier2', $result['tier']);
        $this->assertSame('gpt-test', $result['model']);
    }

    public function test_complaint_keyword_case_insensitive(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture('SAYA KECEWA SEKALI');
        $this->bindCrmContextStub([]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertContains('complaint_keyword', $result['reasons']);
    }

    public function test_low_confidence_vip_does_not_trigger(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();
        $this->bindCrmContextStub([]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key' => 'vip_indicator',
            'value' => 'true',
            'value_type' => 'bool',
            'confidence' => 0.4, // below 0.5
            'source' => 'inferred',
        ]);

        $result = $this->makeRouter()->decide($message, $conversation, $customer);

        $this->assertNotContains('vip', $result['reasons']);
    }
}
