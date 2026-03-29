<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessIncomingConversationMessage;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Chatbot\AdminConversationMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MobileLiveChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_register_login_me_and_logout_flow(): void
    {
        $register = $this->postJson(route('api.mobile.auth.register'), [
            'device_id' => 'device-alpha-01',
            'name' => 'Rina',
            'email' => 'rina@example.com',
            'avatar_url' => 'https://example.com/rina.png',
        ]);

        $register->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.name', 'Rina')
            ->assertJsonPath('data.customer.email', 'rina@example.com')
            ->assertJsonPath('data.customer.preferred_channel', 'mobile_live_chat');

        $mobileUserId = (string) $register->json('data.customer.mobile_user_id');
        $accessToken = (string) $register->json('data.access_token');

        $me = $this->withToken($accessToken)
            ->getJson(route('api.mobile.auth.me'));

        $me->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.mobile_user_id', $mobileUserId)
            ->assertJsonPath('data.customer.display_contact', 'rina@example.com');

        $logout = $this->withToken($accessToken)
            ->postJson(route('api.mobile.auth.logout'));

        $logout->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout mobile berhasil.');

        $meAfterLogout = $this->withToken($accessToken)
            ->getJson(route('api.mobile.auth.me'));

        $meAfterLogout->assertUnauthorized()
            ->assertJsonPath('success', false);

        $login = $this->postJson(route('api.mobile.auth.login'), [
            'mobile_user_id' => $mobileUserId,
            'device_id' => 'device-alpha-01',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.mobile_user_id', $mobileUserId)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'token_type',
                    'customer',
                ],
            ]);
    }

    public function test_mobile_live_chat_flow_can_start_send_poll_and_mark_read(): void
    {
        Queue::fake([ProcessIncomingConversationMessage::class, SendWhatsAppMessageJob::class]);

        [$token, $mobileUserId] = $this->registerMobileCustomer(
            deviceId: 'device-beta-01',
            name: 'Tari',
            email: 'tari@example.com',
        );

        $start = $this->withToken($token)->postJson(route('api.mobile.live-chat.start'), [
            'source_app' => 'flutter',
            'opening_message' => 'Halo, saya mau booking.',
            'client_message_id' => 'client-start-001',
        ]);

        $start->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.channel', 'mobile_live_chat')
            ->assertJsonPath('data.conversation.source_app', 'flutter')
            ->assertJsonPath('data.conversation.is_from_mobile_app', true)
            ->assertJsonPath('data.submitted_message.client_message_id', 'client-start-001')
            ->assertJsonPath('data.duplicate', false);

        $conversation = Conversation::query()->findOrFail((int) $start->json('data.conversation.id'));
        $this->assertSame($mobileUserId, $conversation->customer?->mobile_user_id);
        $this->assertNotNull($conversation->channel_conversation_id);

        Queue::assertPushed(ProcessIncomingConversationMessage::class, 1);

        $list = $this->withToken($token)
            ->getJson(route('api.mobile.live-chat.conversations.index'));

        $list->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $conversation->id);

        $send = $this->withToken($token)->postJson(route('api.mobile.live-chat.conversations.messages.store', [
            'conversation' => $conversation,
        ]), [
            'message' => 'Tolong cek kursi untuk besok pagi.',
            'client_message_id' => 'client-msg-002',
        ]);

        $send->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.client_message_id', 'client-msg-002')
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.message.delivery_status', 'sent');

        Queue::assertPushed(ProcessIncomingConversationMessage::class, 2);

        $admin = User::factory()->create([
            'name' => 'Admin Mobile',
            'is_chatbot_admin' => true,
        ]);

        $adminReply = app(AdminConversationMessageService::class)->send(
            conversation: $conversation->fresh(),
            text: 'Baik, kami cek jadwal dan kursinya ya.',
            adminId: $admin->id,
            source: 'live_chat_panel',
        );

        $this->assertSame('mobile_live_chat', $adminReply['transport']);
        $this->assertSame('sent', $adminReply['message']->fresh()?->delivery_status?->value);
        Queue::assertNotPushed(SendWhatsAppMessageJob::class);

        $messages = $this->withToken($token)
            ->getJson(route('api.mobile.live-chat.conversations.messages.index', [
                'conversation' => $conversation,
            ]));

        $messages->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.id', $conversation->id);

        $messageTexts = collect($messages->json('data.messages'))->pluck('message_text')->all();
        $this->assertContains('Halo, saya mau booking.', $messageTexts);
        $this->assertContains('Tolong cek kursi untuk besok pagi.', $messageTexts);
        $this->assertContains('Baik, kami cek jadwal dan kursinya ya.', $messageTexts);

        $afterMessageId = (int) $send->json('data.message.id');
        $poll = $this->withToken($token)
            ->getJson(route('api.mobile.live-chat.conversations.poll', [
                'conversation' => $conversation,
                'after_message_id' => $afterMessageId,
            ]));

        $poll->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.message_text', 'Baik, kami cek jadwal dan kursinya ya.')
            ->assertJsonPath('data.meta.unread_count', 1);

        $adminMessageId = (int) $poll->json('data.messages.0.id');
        $markRead = $this->withToken($token)
            ->postJson(route('api.mobile.live-chat.conversations.mark-read', [
                'conversation' => $conversation,
            ]), [
                'last_read_message_id' => $adminMessageId,
            ]);

        $markRead->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.conversation.unread_count', 0);

        $this->assertNotNull($conversation->fresh()?->last_read_at_customer);
        $this->assertNotNull(ConversationMessage::query()->find($adminMessageId)?->read_at);
        $this->assertNotNull(ConversationMessage::query()->find($adminMessageId)?->delivered_to_app_at);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function registerMobileCustomer(string $deviceId, string $name, string $email): array
    {
        $response = $this->postJson(route('api.mobile.auth.register'), [
            'device_id' => $deviceId,
            'name' => $name,
            'email' => $email,
        ]);

        $response->assertCreated();

        return [
            (string) $response->json('data.access_token'),
            (string) $response->json('data.customer.mobile_user_id'),
        ];
    }
}
