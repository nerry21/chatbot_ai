<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationOutboundRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationOutboundRouterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_whatsapp_messages_for_whatsapp_channel(): void
    {
        Queue::fake();

        $message = $this->makeOutboundMessage('whatsapp', '+628123450000');

        app(ConversationOutboundRouterService::class)->dispatch($message, 'trace-wa-001');

        Queue::assertPushed(SendWhatsAppMessageJob::class, function (SendWhatsAppMessageJob $job) use ($message): bool {
            return $job->conversationMessageId === $message->id
                && $job->traceId === 'trace-wa-001';
        });

        $this->assertSame('pending', $message->fresh()?->delivery_status?->value);
    }

    public function test_it_marks_mobile_live_chat_messages_as_sent_without_whatsapp_queue(): void
    {
        Queue::fake();

        $message = $this->makeOutboundMessage('mobile_live_chat', 'mlc:test-customer');

        app(ConversationOutboundRouterService::class)->dispatch($message, 'trace-mobile-001');

        Queue::assertNotPushed(SendWhatsAppMessageJob::class);

        $freshMessage = $message->fresh();
        $this->assertSame('sent', $freshMessage?->delivery_status?->value);
        $this->assertSame('mobile_live_chat', data_get($freshMessage?->raw_payload, 'channel_delivery.channel'));
        $this->assertSame('http_polling', data_get($freshMessage?->raw_payload, 'channel_delivery.transport'));
    }

    private function makeOutboundMessage(string $channel, string $phone): ConversationMessage
    {
        $customer = Customer::create([
            'name' => 'Customer',
            'phone_e164' => $phone,
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Halo dari admin/bot.',
            'raw_payload' => [],
            'sent_at' => now(),
        ]);
    }
}
