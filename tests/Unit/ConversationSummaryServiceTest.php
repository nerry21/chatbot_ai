<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\ConversationSummaryService;
use App\Services\AI\LlmClientService;
use App\Services\AI\PromptBuilderService;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ConversationSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_builds_and_merges_business_summary_payload(): void
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'summary' => 'Summary lama.',
            'started_at' => now(),
            'last_message_at' => now(),
        ])->load('customer');

        $baseTime = Carbon::create(2026, 4, 4, 9, 0, 0);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Saya mau cek jadwal ke Duri.',
            'sent_at' => $baseTime,
            'created_at' => $baseTime,
            'updated_at' => $baseTime,
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Baik, saya bantu cek dulu ya.',
            'sent_at' => $baseTime->copy()->addMinutes(1),
            'created_at' => $baseTime->copy()->addMinutes(1),
            'updated_at' => $baseTime->copy()->addMinutes(1),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Dari Pekanbaru besok pagi.',
            'sent_at' => $baseTime->copy()->addMinutes(2),
            'created_at' => $baseTime->copy()->addMinutes(2),
            'updated_at' => $baseTime->copy()->addMinutes(2),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Kalau ada, tolong infokan harganya juga.',
            'sent_at' => $baseTime->copy()->addMinutes(3),
            'created_at' => $baseTime->copy()->addMinutes(3),
            'updated_at' => $baseTime->copy()->addMinutes(3),
        ]);

        $llmClient = Mockery::mock(LlmClientService::class);
        $llmClient->shouldReceive('summarizeConversation')
            ->once()
            ->withArgs(function (array $context): bool {
                $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];
                $transcript = $conversation['recent_transcript'] ?? [];

                return ($conversation['existing_summary'] ?? null) === 'Summary lama.'
                    && in_array('customer: Saya mau cek jadwal ke Duri.', $transcript, true)
                    && in_array('assistant: Baik, saya bantu cek dulu ya.', $transcript, true);
            })
            ->andReturn([
                'summary' => 'Customer menanyakan jadwal dan harga perjalanan Pekanbaru ke Duri untuk besok pagi.',
                'intent' => 'booking_inquiry',
                'sentiment' => 'neutral',
                'next_action' => 'follow up availability',
            ]);

        $service = new ConversationSummaryService(
            $llmClient,
            new PromptBuilderService,
            new JsonSchemaValidatorService,
        );

        $result = $service->summarize($conversation, [
            'recent_messages' => [
                ['direction' => 'inbound', 'text' => 'Saya mau cek jadwal ke Duri.'],
                ['direction' => 'outbound', 'text' => 'Baik, saya bantu cek dulu ya.'],
                ['direction' => 'inbound', 'text' => 'Dari Pekanbaru besok pagi.'],
                ['direction' => 'inbound', 'text' => 'Kalau ada, tolong infokan harganya juga.'],
            ],
            'crm_context' => [
                'conversation' => [
                    'current_intent' => 'booking',
                    'needs_human' => false,
                ],
                'lead_pipeline' => [
                    'stage' => 'engaged',
                ],
                'business_flags' => [
                    'needs_human_followup' => false,
                ],
            ],
            'customer_memory' => [
                'relationship_memory' => [
                    'is_returning_customer' => true,
                    'preferred_pickup' => 'Pekanbaru',
                    'preferred_destination' => 'Duri',
                ],
            ],
        ]);

        $this->assertSame('Customer menanyakan jadwal dan harga perjalanan Pekanbaru ke Duri untuk besok pagi.', $result['summary']);
        $this->assertSame('booking_inquiry', $result['intent']);
        $this->assertSame('neutral', $result['sentiment']);
        $this->assertSame('follow up availability', $result['next_action']);
        $this->assertSame('engaged', $result['lead_stage']);
        $this->assertFalse($result['needs_human_followup']);
        $this->assertSame('returning', $result['customer_type']);
        $this->assertSame('Pekanbaru', $result['preferred_pickup']);
        $this->assertSame('Duri', $result['preferred_destination']);
    }
}
