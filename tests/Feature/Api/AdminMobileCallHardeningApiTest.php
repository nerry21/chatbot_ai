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

class AdminMobileCallHardeningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_permission_respects_existing_cooldown_without_hitting_meta(): void
    {
        $this->setCallingConfig();

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
            'status' => WhatsAppCallSession::STATUS_PERMISSION_REQUESTED,
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
            'last_permission_requested_at' => now(),
            'started_at' => now()->subMinute(),
            'meta_payload' => [
                'permission' => [
                    'requested_at' => now()->toIso8601String(),
                    'last_requested_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        Http::fake();

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.call.request-permission', ['conversation' => $conversation]),
            [
                'call_type' => 'audio',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.call_action', 'permission_still_pending')
            ->assertJsonPath('data.call_session.permission_status', WhatsAppCallSession::PERMISSION_REQUESTED);

        Http::assertNothingSent();
    }

    public function test_start_call_returns_permission_rate_limited_during_local_cooldown(): void
    {
        $this->setCallingConfig();

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
            'status' => WhatsAppCallSession::STATUS_INITIATED,
            'permission_status' => WhatsAppCallSession::PERMISSION_RATE_LIMITED,
            'rate_limited_until' => now()->addMinutes(3),
            'started_at' => now()->subMinute(),
            'meta_payload' => [
                'permission' => [
                    'cooldown_until' => now()->addMinutes(3)->toIso8601String(),
                ],
            ],
        ]);

        Http::fake();

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.call.start', ['conversation' => $conversation]),
            [
                'call_type' => 'audio',
            ],
        );

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.call_action', 'permission_rate_limited')
            ->assertJsonPath('data.call_session.permission_status', WhatsAppCallSession::PERMISSION_RATE_LIMITED);

        Http::assertNothingSent();
    }

    public function test_permission_request_retries_once_on_transient_meta_server_error(): void
    {
        $this->setCallingConfig([
            'chatbot.whatsapp.calling.max_retries' => 1,
        ]);

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
            'https://graph.facebook.com/v23.0/test-phone-id/messages' => Http::sequence()
                ->push(['error' => ['message' => 'Temporary server error']], 500)
                ->push([
                    'messaging_product' => 'whatsapp',
                    'messages' => [
                        ['id' => 'wamid.permission.request.retry.001'],
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
            ->assertJsonPath('data.call_action', 'permission_requested');

        Http::assertSentCount(3);
    }

    public function test_start_call_returns_clear_configuration_error_when_calling_config_is_missing(): void
    {
        $this->setCallingConfig([
            'chatbot.whatsapp.calling.access_token' => '',
        ]);

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation] = $this->createConversation();

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.call.start', ['conversation' => $conversation]),
            [
                'call_type' => 'audio',
            ],
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.call_action', 'call_blocked_configuration_error')
            ->assertJsonPath('data.call_session.permission_status', WhatsAppCallSession::PERMISSION_FAILED);
    }

    public function test_readiness_endpoint_returns_sanitized_calling_summary(): void
    {
        $this->setCallingConfig();

        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);

        $response = $this->withToken($token)->getJson(
            route('api.admin-mobile.call.readiness'),
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.readiness.calling_enabled', true)
            ->assertJsonMissing(['test-access-token']);
    }

    public function test_duplicate_call_webhook_event_is_ignored_after_first_delivery(): void
    {
        $this->setCallingConfig();

        [$conversation, $customer] = $this->createConversation();

        WhatsAppCallSession::query()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_INITIATED,
            'wa_call_id' => 'wacall.hardening.001',
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'started_at' => now()->subMinute(),
            'meta_payload' => [],
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'calls',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '6281234567890',
                            'phone_number_id' => 'test-phone-id',
                        ],
                        'calls' => [[
                            'id' => 'wacall.hardening.001',
                            'event' => 'ringing',
                            'direction' => 'business_initiated',
                            'from' => '6281111111111',
                            'to' => '6281234567001',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        $first = $this->postJson(route('webhook.whatsapp.receive'), $payload);
        $second = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $first->assertOk()
            ->assertJsonPath('summary.processed_calls', 1);
        $second->assertOk()
            ->assertJsonPath('summary.ignored_calls', 1);

        $this->assertDatabaseCount('whatsapp_webhook_dedup_events', 1);
        $this->assertDatabaseHas('whatsapp_call_sessions', [
            'conversation_id' => $conversation->id,
            'status' => WhatsAppCallSession::STATUS_RINGING,
            'wa_call_id' => 'wacall.hardening.001',
        ]);
    }

    private function setCallingConfig(array $overrides = []): void
    {
        config(array_merge([
            'chatbot.whatsapp.calling.enabled' => true,
            'chatbot.whatsapp.calling.base_url' => 'https://graph.facebook.com',
            'chatbot.whatsapp.calling.api_version' => 'v23.0',
            'chatbot.whatsapp.calling.access_token' => 'test-access-token',
            'chatbot.whatsapp.calling.phone_number_id' => 'test-phone-id',
            'chatbot.whatsapp.calling.permission_request_enabled' => true,
            'chatbot.whatsapp.calling.default_permission_ttl_minutes' => 1440,
            'chatbot.whatsapp.calling.permission_cooldown_seconds' => 120,
            'chatbot.whatsapp.calling.start_cooldown_seconds' => 15,
            'chatbot.whatsapp.calling.rate_limit_backoff_seconds' => 60,
            'chatbot.whatsapp.calling.rate_limit_cooldown_seconds' => 180,
            'chatbot.whatsapp.calling.retry_enabled' => true,
            'chatbot.whatsapp.calling.max_retries' => 2,
            'chatbot.whatsapp.calling.retry_backoff_ms' => 1,
            'chatbot.whatsapp.calling.dedup_enabled' => true,
            'chatbot.whatsapp.calling.webhook_signature_enabled' => false,
            'chatbot.whatsapp.calling.action_lock_seconds' => 8,
        ], $overrides));
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Hardening '.$index,
            'email' => "admin-hardening-{$index}@example.com",
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
            'device_name' => 'QA Admin Hardening',
            'device_id' => 'qa-admin-hardening-device-'.$user->id,
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
            'name' => 'Customer Hardening '.$index,
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
