<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\WhatsAppCallSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_webhook_updates_session_by_wa_call_id(): void
    {
        [$customer, $conversation] = $this->createConversationContext();

        $session = WhatsAppCallSession::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_RINGING,
            'wa_call_id' => 'wacall.accepted.001',
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
            'started_at' => now()->subMinute(),
            'meta_payload' => [
                'integration' => [
                    'mode' => 'stub',
                ],
            ],
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'calls',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '628111111111',
                            'phone_number_id' => '123456789',
                        ],
                        'calls' => [[
                            'id' => 'wacall.accepted.001',
                            'event' => 'accepted',
                            'direction' => 'business_initiated',
                            'from' => '628111111111',
                            'to' => '6281234567890',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        $response = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $response->assertOk()
            ->assertJsonPath('summary.call_count', 1)
            ->assertJsonPath('summary.processed_calls', 1);

        $session->refresh();

        $this->assertSame(WhatsAppCallSession::STATUS_CONNECTED, $session->status);
        $this->assertNotNull($session->answered_at);
        $this->assertSame('connected', data_get($session->meta_payload, 'last_webhook_call.local_status'));
        $this->assertSame('accepted', data_get($session->meta_payload, 'last_webhook_call.event'));
    }

    public function test_call_webhook_can_fallback_to_customer_phone_and_mark_missed(): void
    {
        [$customer, $conversation] = $this->createConversationContext();

        $session = WhatsAppCallSession::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'direction' => 'business_initiated',
            'call_type' => 'audio',
            'status' => WhatsAppCallSession::STATUS_RINGING,
            'permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
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
                            'display_phone_number' => '628111111111',
                            'phone_number_id' => '123456789',
                        ],
                        'calls' => [[
                            'id' => 'wacall.terminated.002',
                            'event' => 'terminated',
                            'direction' => 'business_initiated',
                            'from' => '628111111111',
                            'to' => '6281234567890',
                            'timestamp' => (string) now()->timestamp,
                            'termination_reason' => 'timeout',
                        ]],
                    ],
                ]],
            ]],
        ];

        $response = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $response->assertOk()
            ->assertJsonPath('summary.call_count', 1)
            ->assertJsonPath('summary.processed_calls', 1);

        $session->refresh();

        $this->assertSame('wacall.terminated.002', $session->wa_call_id);
        $this->assertSame(WhatsAppCallSession::STATUS_MISSED, $session->status);
        $this->assertNotNull($session->ended_at);
        $this->assertSame('timeout', $session->end_reason);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function createConversationContext(): array
    {
        $customer = Customer::create([
            'name' => 'Budi',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'last_message_at' => now()->subMinutes(1),
        ]);

        return [$customer, $conversation];
    }
}
