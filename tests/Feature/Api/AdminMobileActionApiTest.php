<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminMobileActionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_mobile_can_send_whatsapp_message_and_receive_refreshed_payload(): void
    {
        Queue::fake();

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation('whatsapp');

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.messages.store', ['conversation' => $conversation]),
            ['message' => 'Baik, admin bantu cek kursi sekarang.'],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'send_message')
            ->assertJsonPath('data.action_result.transport', 'whatsapp')
            ->assertJsonPath('data.action_result.duplicate', false)
            ->assertJsonPath('data.action_result.message.message_text', 'Baik, admin bantu cek kursi sekarang.')
            ->assertJsonPath('data.updated_conversation.operational_mode', 'human_takeover')
            ->assertJsonPath('data.updated_conversation.assigned_admin.id', $admin->id)
            ->assertJsonPath('data.composer_state.is_takeover_active', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'action',
                    'action_result',
                    'updated_conversation',
                    'composer_state',
                    'refreshed_thread_snippet' => [
                        'messages',
                        'thread_groups',
                    ],
                    'insight_pane',
                ],
            ]);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'message_text' => 'Baik, admin bantu cek kursi sekarang.',
        ]);

        $this->assertSame($admin->id, $conversation->fresh()->assigned_admin_id);
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    }

    public function test_admin_mobile_can_send_mobile_live_chat_message_without_whatsapp_queue(): void
    {
        Queue::fake();

        $admin = $this->createAdmin([
            'email' => 'mobile-admin@example.com',
        ]);
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation('mobile_live_chat');

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.messages.store', ['conversation' => $conversation]),
            ['message' => 'Halo, ini balasan dari admin mobile live chat.'],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action_result.transport', 'mobile_live_chat')
            ->assertJsonPath('data.action_result.message.delivery_status', 'sent')
            ->assertJsonPath('data.action_result.message.message_text', 'Halo, ini balasan dari admin mobile live chat.')
            ->assertJsonPath('data.composer_state.channel', 'mobile_live_chat');

        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    public function test_admin_mobile_can_mark_read_takeover_tag_note_and_release_conversation(): void
    {
        $admin = $this->createAdmin([
            'email' => 'workflow-admin@example.com',
        ]);
        $token = $this->loginAdmin($admin);
        [$conversation, $customer] = $this->createConversation('whatsapp');
        $this->addInboundCustomerMessage($conversation, 'Halo admin, saya belum dibalas.');

        $markRead = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.mark-read', ['conversation' => $conversation]),
        );

        $markRead->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'mark_read')
            ->assertJsonPath('data.action_result.unread_count', 0)
            ->assertJsonPath('data.updated_conversation.unread_count', 0);

        $this->assertDatabaseHas('conversation_user_reads', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin->id,
        ]);
        $this->assertNotNull($conversation->fresh()->last_read_at_admin);

        $takeover = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.takeover', ['conversation' => $conversation]),
        );

        $takeover->assertOk()
            ->assertJsonPath('data.action', 'takeover')
            ->assertJsonPath('data.action_result.mode', 'human_takeover')
            ->assertJsonPath('data.updated_conversation.assigned_admin.id', $admin->id)
            ->assertJsonPath('data.composer_state.is_takeover_active', true)
            ->assertJsonPath('data.composer_state.is_assigned_to_me', true);

        $tag = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.tags.store', ['conversation' => $conversation]),
            [
                'target' => 'conversation',
                'tag' => 'VIP Priority',
            ],
        );

        $tag->assertOk()
            ->assertJsonPath('data.action', 'store_tag')
            ->assertJsonPath('data.action_result.target', 'conversation')
            ->assertJsonPath('data.action_result.tag.value', 'vip-priority')
            ->assertJsonPath('data.insight_pane.conversation_tags.0.tag', 'vip-priority');

        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag' => 'vip-priority',
        ]);

        $note = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.notes.store', ['conversation' => $conversation]),
            [
                'target' => 'customer',
                'body' => 'Butuh follow up manual setelah jam operasional.',
            ],
        );

        $note->assertCreated()
            ->assertJsonPath('data.action', 'store_note')
            ->assertJsonPath('data.action_result.target', 'customer')
            ->assertJsonPath('data.action_result.note.body', 'Butuh follow up manual setelah jam operasional.')
            ->assertJsonPath('data.insight_pane.internal_notes.0.body', 'Butuh follow up manual setelah jam operasional.')
            ->assertJsonPath('data.insight_pane.internal_notes.0.target', 'customer');

        $this->assertDatabaseHas('admin_notes', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'noteable_type' => Customer::class,
            'noteable_id' => $customer->id,
            'body' => 'Butuh follow up manual setelah jam operasional.',
        ]);

        $release = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.release', ['conversation' => $conversation]),
        );

        $release->assertOk()
            ->assertJsonPath('data.action', 'release')
            ->assertJsonPath('data.action_result.mode', 'bot_active')
            ->assertJsonPath('data.composer_state.is_takeover_active', false)
            ->assertJsonPath('data.updated_conversation.operational_mode', 'bot_active');
    }

    public function test_admin_mobile_can_escalate_close_and_reopen_conversation(): void
    {
        $admin = $this->createAdmin([
            'email' => 'status-admin@example.com',
        ]);
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation('whatsapp');

        $escalate = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.status.escalate', ['conversation' => $conversation]),
            ['reason' => 'Perlu penanganan supervisor.'],
        );

        $escalate->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'status_escalate')
            ->assertJsonPath('data.action_result.status', 'escalated')
            ->assertJsonPath('data.updated_conversation.status', 'escalated')
            ->assertJsonPath('data.updated_conversation.operational_mode', 'escalated')
            ->assertJsonPath('data.insight_pane.quick_details.needs_human', true);

        $close = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.status.close', ['conversation' => $conversation]),
            ['reason' => 'Kasus sudah selesai.'],
        );

        $close->assertOk()
            ->assertJsonPath('data.action', 'status_close')
            ->assertJsonPath('data.action_result.status', 'closed')
            ->assertJsonPath('data.updated_conversation.status', 'closed')
            ->assertJsonPath('data.updated_conversation.operational_mode', 'closed')
            ->assertJsonPath('data.composer_state.can_send', false)
            ->assertJsonPath('data.composer_state.message_hint', 'Conversation ditutup. Reopen sebelum mengirim pesan baru.');

        $reopen = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.status.reopen', ['conversation' => $conversation]),
        );

        $reopen->assertOk()
            ->assertJsonPath('data.action', 'status_reopen')
            ->assertJsonPath('data.action_result.status', 'active')
            ->assertJsonPath('data.updated_conversation.status', 'active')
            ->assertJsonPath('data.updated_conversation.operational_mode', 'bot_active')
            ->assertJsonPath('data.composer_state.can_send', true)
            ->assertJsonPath('data.composer_state.message_hint', null);
    }

    public function test_operator_can_send_message_but_cannot_takeover_or_change_status(): void
    {
        config(['chatbot.security.allow_operator_actions' => true]);
        Queue::fake();

        $operator = $this->createAdmin([
            'name' => 'Operator Mobile',
            'email' => 'operator@example.com',
            'is_chatbot_admin' => false,
            'is_chatbot_operator' => true,
        ]);

        $token = $this->loginAdmin($operator);
        [$conversation] = $this->createConversation('mobile_live_chat');

        $send = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.messages.store', ['conversation' => $conversation]),
            ['message' => 'Halo, operator bantu lanjutkan chat ini.'],
        );

        $send->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'send_message')
            ->assertJsonPath('data.action_result.transport', 'mobile_live_chat');

        $takeover = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.takeover', ['conversation' => $conversation]),
        );

        $takeover->assertForbidden()
            ->assertJsonPath('success', false);

        $close = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.status.close', ['conversation' => $conversation]),
        );

        $close->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Mobile '.$index,
            'email' => "admin{$index}@example.com",
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
            'is_chatbot_operator' => false,
        ], $attributes));
    }

    private function loginAdmin(User $user, string $password = 'super-secret'): string
    {
        $response = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'QA Admin Mobile',
            'device_id' => 'qa-admin-mobile-device-'.$user->id,
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }

    /**
     * @return array{0: Conversation, 1: Customer}
     */
    private function createConversation(string $channel = 'whatsapp', array $conversationAttributes = [], array $customerAttributes = []): array
    {
        $index = Customer::query()->count() + 1;

        $customer = Customer::create(array_merge([
            'name' => 'Customer '.$index,
            'phone_e164' => $channel === 'mobile_live_chat'
                ? 'mlc:'.substr(hash('sha256', 'customer-'.$index), 0, 32)
                : '+6281234500'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'email' => "customer{$index}@example.com",
            'mobile_user_id' => 'mobile-user-'.$index,
            'status' => 'active',
        ], $customerAttributes));

        $conversation = Conversation::create(array_merge([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'needs_human' => false,
            'bot_paused' => false,
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now()->subMinutes(2),
            'source_app' => $channel === 'mobile_live_chat' ? 'flutter' : 'web-dashboard',
        ], $conversationAttributes));

        return [$conversation, $customer];
    }

    private function addInboundCustomerMessage(Conversation $conversation, string $text): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => $text,
            'raw_payload' => [],
            'sent_at' => now()->subMinute(),
        ]);
    }
}
