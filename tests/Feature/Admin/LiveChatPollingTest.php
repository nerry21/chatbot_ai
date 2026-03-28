<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveChatPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_list_returns_unread_conversations_and_supports_keyword_search(): void
    {
        $admin = User::factory()->create([
            'is_chatbot_admin' => true,
        ]);

        [$conversation, $customer] = $this->makeConversation(
            name: 'Budi Santoso',
            phone: '+628123450001',
            messageText: 'Saya mau tanya jadwal jam 10 ke Pekanbaru',
        );

        $this->makeConversation(
            name: 'Siti',
            phone: '+628123450002',
            messageText: 'Halo admin',
        );

        $this->actingAs($admin);

        $response = $this->getJson(route('admin.chatbot.live-chats.poll.list', [
            'scope' => 'unread',
            'search' => 'jam 10',
            'selected_conversation_id' => $conversation->id,
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'html',
                'meta' => ['refreshed_at', 'unread_total'],
            ]);

        $this->assertStringContainsString($customer->name, (string) $response->json('html'));
        $this->assertStringNotContainsString('Siti', (string) $response->json('html'));
        $this->assertSame(1, (int) $response->json('meta.unread_total'));

        $threadResponse = $this->getJson(route('admin.chatbot.live-chats.poll.conversation', [
            'conversation' => $conversation,
            'scope' => 'unread',
            'search' => 'jam 10',
        ]));

        $threadResponse->assertOk()
            ->assertJsonStructure([
                'thread_html',
                'insight_html',
                'meta' => ['refreshed_at', 'selected_conversation_id'],
            ]);

        $this->assertStringContainsString('jadwal jam 10', (string) $threadResponse->json('thread_html'));
        $this->assertStringContainsString($customer->name, (string) $threadResponse->json('insight_html'));
        $this->assertSame($conversation->id, (int) $threadResponse->json('meta.selected_conversation_id'));
        $this->assertDatabaseHas('conversation_user_reads', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
        ]);

        $markReadResponse = $this->postJson(route('admin.chatbot.live-chats.mark-read', $conversation));
        $markReadResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'conversation_id' => $conversation->id,
                'unread_count' => 0,
            ]);

        $unreadAfterRead = $this->getJson(route('admin.chatbot.live-chats.poll.list', [
            'scope' => 'unread',
            'selected_conversation_id' => $conversation->id,
        ]));

        $unreadAfterRead->assertOk();
        $this->assertStringNotContainsString($customer->name, (string) $unreadAfterRead->json('html'));
        $this->assertStringContainsString('Siti', (string) $unreadAfterRead->json('html'));
    }

    public function test_booking_in_progress_filter_returns_active_booking_threads(): void
    {
        $admin = User::factory()->create([
            'is_chatbot_admin' => true,
        ]);

        [$conversation, $customer] = $this->makeConversation(
            name: 'Nia',
            phone: '+628123450003',
            messageText: 'Mau booking 2 kursi',
        );

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'booking_status' => BookingStatus::Draft,
        ]);

        $this->actingAs($admin);

        $response = $this->getJson(route('admin.chatbot.live-chats.poll.list', [
            'scope' => 'booking_in_progress',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('Nia', (string) $response->json('html'));
    }

    /**
     * @return array{0: Conversation, 1: Customer}
     */
    private function makeConversation(string $name, string $phone, string $messageText): array
    {
        $customer = Customer::create([
            'name' => $name,
            'phone_e164' => $phone,
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
            'message_text' => $messageText,
            'raw_payload' => [],
            'sent_at' => now(),
        ]);

        return [$conversation, $customer];
    }
}
