<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppCallSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMobileCallApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_call_requests_permission_when_meta_reports_permission_required(): void
    {
        $this->setCallingConfig();

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation();

        Http::fake([
            'https://graph.facebook.com/v23.0/test-phone-id/call_permissions*' => Http::response([
                'messaging_product' => 'whatsapp',
                'permission' => [
                    'status' => 'not_set',
                ],
                'actions' => [
                    [
                        'action_name' => 'send_call_permission_request',
                        'can_perform_action' => true,
                    ],
                    [
                        'action_name' => 'start_call',
                        'can_perform_action' => false,
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v23.0/test-phone-id/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [
                    ['id' => 'wamid.permission.request.001'],
                ],
            ], 200),
        ]);

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.call.start', ['conversation' => $conversation]),
            [
                'call_type' => 'audio',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.call_action', 'permission_requested')
            ->assertJsonPath('data.permission_required', true)
            ->assertJsonPath('data.call_session.permission_status', WhatsAppCallSession::PERMISSION_REQUESTED)
            ->assertJsonPath('data.call_session.status', WhatsAppCallSession::STATUS_PERMISSION_REQUESTED);

        $this->assertDatabaseHas('whatsapp_call_sessions', [
            'conversation_id' => $conversation->id,
            'direction' => 'business_initiated',
            'status' => WhatsAppCallSession::STATUS_PERMISSION_REQUESTED,
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
        ]);
    }

    public function test_start_call_initiates_outbound_call_when_permission_is_granted(): void
    {
        $this->setCallingConfig();

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation();

        Http::fake([
            'https://graph.facebook.com/v23.0/test-phone-id/call_permissions*' => Http::response([
                'messaging_product' => 'whatsapp',
                'permission' => [
                    'status' => 'temporary',
                    'expiration_time' => now()->addHours(6)->timestamp,
                ],
                'actions' => [
                    [
                        'action_name' => 'send_call_permission_request',
                        'can_perform_action' => false,
                    ],
                    [
                        'action_name' => 'start_call',
                        'can_perform_action' => true,
                    ],
                ],
            ], 200),
            'https://graph.facebook.com/v23.0/test-phone-id/calls' => Http::response([
                'messaging_product' => 'whatsapp',
                'calls' => [
                    ['id' => 'wacall.started.001'],
                ],
            ], 200),
        ]);

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.call.start', ['conversation' => $conversation]),
            [
                'call_type' => 'audio',
                'session' => [
                    'sdp_type' => 'offer',
                    'sdp' => 'v=0',
                ],
                'biz_opaque_callback_data' => 'conversation:test',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.call_action', 'call_started')
            ->assertJsonPath('data.permission_required', false)
            ->assertJsonPath('data.call_session.permission_status', WhatsAppCallSession::PERMISSION_GRANTED)
            ->assertJsonPath('data.call_session.status', WhatsAppCallSession::STATUS_RINGING)
            ->assertJsonPath('data.call_session.wa_call_id', 'wacall.started.001');

        $this->assertDatabaseHas('whatsapp_call_sessions', [
            'conversation_id' => $conversation->id,
            'direction' => 'business_initiated',
            'status' => WhatsAppCallSession::STATUS_RINGING,
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'wa_call_id' => 'wacall.started.001',
        ]);
    }

    private function setCallingConfig(): void
    {
        config([
            'chatbot.whatsapp.calling.enabled' => true,
            'chatbot.whatsapp.calling.base_url' => 'https://graph.facebook.com',
            'chatbot.whatsapp.calling.api_version' => 'v23.0',
            'chatbot.whatsapp.calling.access_token' => 'test-access-token',
            'chatbot.whatsapp.calling.phone_number_id' => 'test-phone-id',
            'chatbot.whatsapp.calling.permission_request_enabled' => true,
            'chatbot.whatsapp.calling.default_permission_ttl_minutes' => 1440,
            'chatbot.whatsapp.calling.rate_limit_backoff_seconds' => 60,
        ]);
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Call '.$index,
            'email' => "admin-call-{$index}@example.com",
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
            'device_id' => 'qa-admin-call-device-'.$user->id,
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
            'name' => 'Customer Call '.$index,
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
