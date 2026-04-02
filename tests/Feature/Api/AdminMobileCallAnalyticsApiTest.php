<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppCallSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMobileCallAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_analytics_summary_recent_and_conversation_history_are_available(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        [$conversation, $secondaryConversation, $customer] = $this->createConversations();

        WhatsAppCallSession::query()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'initiated_by_user_id' => $admin->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_ENDED,
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'started_at' => now()->subMinutes(12),
            'answered_at' => now()->subMinutes(11),
            'connected_at' => now()->subMinutes(11),
            'ended_at' => now()->subMinutes(9)->subSeconds(30),
            'end_reason' => 'completed',
            'meta_payload' => [],
        ]);

        WhatsAppCallSession::query()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'initiated_by_user_id' => $admin->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_MISSED,
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
            'started_at' => now()->subMinutes(8),
            'ended_at' => now()->subMinutes(7),
            'end_reason' => 'no_answer',
            'meta_payload' => [],
        ]);

        WhatsAppCallSession::query()->create([
            'conversation_id' => $secondaryConversation->id,
            'customer_id' => $secondaryConversation->customer_id,
            'initiated_by_user_id' => $admin->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_REJECTED,
            'permission_status' => WhatsAppCallSession::PERMISSION_DENIED,
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(4),
            'end_reason' => 'rejected_by_customer',
            'meta_payload' => [],
        ]);

        WhatsAppCallSession::query()->create([
            'conversation_id' => $secondaryConversation->id,
            'customer_id' => $secondaryConversation->customer_id,
            'initiated_by_user_id' => $admin->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_INITIATED,
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
            'started_at' => now()->subMinutes(1),
            'meta_payload' => [],
        ]);

        $summary = $this->withToken($token)->getJson(route('api.admin-mobile.call-analytics.summary'));

        $summary->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total_calls', 4)
            ->assertJsonPath('data.summary.completed_calls', 1)
            ->assertJsonPath('data.summary.missed_calls', 1)
            ->assertJsonPath('data.summary.rejected_calls', 1)
            ->assertJsonPath('data.summary.in_progress_calls', 0)
            ->assertJsonPath('data.summary.permission_pending_calls', 1)
            ->assertJsonPath('data.summary.total_duration_seconds', 90)
            ->assertJsonPath('data.summary.average_duration_seconds', 90)
            ->assertJsonFragment([
                'final_status' => 'completed',
                'label' => 'Berhasil',
                'count' => 1,
            ]);

        $recent = $this->withToken($token)->getJson(route('api.admin-mobile.call-analytics.recent', [
            'limit' => 2,
        ]));

        $recent->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.recent_calls')
            ->assertJsonPath('data.recent_calls.0.final_status', 'permission_pending')
            ->assertJsonPath('data.recent_calls.0.final_status_label', 'Menunggu izin');

        $history = $this->withToken($token)->getJson(route('api.admin-mobile.conversations.call.history', [
            'conversation' => $conversation,
        ]));

        $history->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.call_history_summary.total_calls', 2)
            ->assertJsonPath('data.call_history_summary.last_call_status', 'missed')
            ->assertJsonPath('data.call_history_summary.last_call_label', 'Tidak dijawab')
            ->assertJsonPath('data.call_history.1.duration_seconds', 90)
            ->assertJsonPath('data.call_history.1.duration_human', '1 m 30 dtk');
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Analytics '.$index,
            'email' => "admin-analytics-{$index}@example.com",
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
            'device_name' => 'QA Admin Analytics',
            'device_id' => 'qa-admin-analytics-device-'.$user->id,
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }

    /**
     * @return array{0: Conversation, 1: Conversation, 2: Customer}
     */
    private function createConversations(): array
    {
        $customer = Customer::create([
            'name' => 'Customer Analytics',
            'phone_e164' => '+6281234567999',
            'status' => 'active',
        ]);

        $secondaryCustomer = Customer::create([
            'name' => 'Customer Analytics 2',
            'phone_e164' => '+6281234567888',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'handoff_mode' => 'admin',
            'needs_human' => true,
            'bot_paused' => true,
            'started_at' => now()->subMinutes(30),
            'last_message_at' => now()->subMinutes(8),
        ]);

        $secondaryConversation = Conversation::create([
            'customer_id' => $secondaryCustomer->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'handoff_mode' => 'admin',
            'needs_human' => true,
            'bot_paused' => true,
            'started_at' => now()->subMinutes(20),
            'last_message_at' => now()->subMinute(),
        ]);

        return [$conversation, $secondaryConversation, $customer];
    }
}
