<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationUserRead;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_latest_inbound_customer_message_as_read(): void
    {
        $service = app(ConversationReadService::class);
        $admin = User::factory()->create([
            'is_chatbot_admin' => true,
        ]);

        [$conversation] = $this->makeConversationWithMessages();

        $this->assertSame(2, $service->unreadCountForConversation($conversation, $admin->id));

        $readState = $service->markAsRead($conversation, $admin->id);

        $this->assertInstanceOf(ConversationUserRead::class, $readState);
        $this->assertNotNull($readState->last_read_message_id);
        $this->assertSame(0, $service->unreadCountForConversation($conversation->fresh(), $admin->id));

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Ada kursi kosong lagi?',
            'raw_payload' => [],
            'sent_at' => now()->addMinute(),
        ]);

        $this->assertSame(1, $service->unreadCountForConversation($conversation->fresh(), $admin->id));
    }

    /**
     * @return array{0: Conversation}
     */
    private function makeConversationWithMessages(): array
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
            'started_at' => now()->subHour(),
            'last_message_at' => now(),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Halo admin',
            'raw_payload' => [],
            'sent_at' => now()->subMinutes(2),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Silakan dibantu.',
            'raw_payload' => [],
            'sent_at' => now()->subMinute(),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Mau tanya jadwal besok.',
            'raw_payload' => [],
            'sent_at' => now(),
        ]);

        return [$conversation];
    }
}
