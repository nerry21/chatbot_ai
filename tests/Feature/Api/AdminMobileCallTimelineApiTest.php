<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppCallSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMobileCallTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_messages_and_poll_expose_call_timeline(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation();

        WhatsAppCallSession::query()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'initiated_by_user_id' => $admin->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_ENDED,
            'wa_call_id' => 'wacall.timeline.001',
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'started_at' => now()->subMinutes(3),
            'answered_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'end_reason' => 'completed',
            'meta_payload' => [
                'permission' => [
                    'requested_at' => now()->subMinutes(4)->toIso8601String(),
                    'granted_at' => now()->subMinutes(4)->toIso8601String(),
                ],
                'webhook_calls' => [
                    [
                        'event' => 'ringing',
                        'local_status' => WhatsAppCallSession::STATUS_RINGING,
                        'timestamp' => now()->subMinutes(3)->toIso8601String(),
                        'termination_reason' => null,
                    ],
                    [
                        'event' => 'connected',
                        'local_status' => WhatsAppCallSession::STATUS_CONNECTED,
                        'timestamp' => now()->subMinutes(2)->toIso8601String(),
                        'termination_reason' => null,
                    ],
                    [
                        'event' => 'ended',
                        'local_status' => WhatsAppCallSession::STATUS_ENDED,
                        'timestamp' => now()->subMinute()->toIso8601String(),
                        'termination_reason' => 'completed',
                    ],
                ],
            ],
        ]);

        $detail = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.show', ['conversation' => $conversation]),
        );

        $detail->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.id', $conversation->id)
            ->assertJsonPath('data.call_timeline.0.type', 'call_event')
            ->assertJsonPath('data.call_timeline.0.event', 'permission_requested')
            ->assertJsonFragment(['event' => 'permission_granted']);

        $messages = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.messages.index', ['conversation' => $conversation]),
        );

        $messages->assertOk()
            ->assertJsonFragment(['event' => 'call_started'])
            ->assertJsonFragment(['event' => 'ringing']);

        $poll = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.poll', ['conversation' => $conversation]),
        );

        $poll->assertOk()
            ->assertJsonFragment(['event' => 'connected'])
            ->assertJsonFragment([
                'event' => 'ended',
                'label' => 'Panggilan berakhir',
            ]);
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Timeline '.$index,
            'email' => "admin-timeline-{$index}@example.com",
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
            'device_name' => 'QA Admin Timeline',
            'device_id' => 'qa-admin-timeline-device-'.$user->id,
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }

    /**
     * @return array{0: Conversation, 1: Customer}
     */
    private function createConversation(): array
    {
        $index = Customer::query()->count() + 1;

        $customer = Customer::create([
            'name' => 'Customer Timeline '.$index,
            'phone_e164' => '+6281234567'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'handoff_mode' => 'admin',
            'needs_human' => true,
            'bot_paused' => true,
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now()->subMinute(),
        ]);

        return [$conversation, $customer];
    }
}
