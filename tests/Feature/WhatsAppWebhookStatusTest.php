<?php

namespace Tests\Feature;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_read_status_updates_message_delivery_state(): void
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
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Halo dari admin.',
            'wa_message_id' => 'wamid.STATUS-READ-001',
            'delivery_status' => MessageDeliveryStatus::Sent,
            'sent_at' => now()->subMinute(),
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '6281234567890',
                            'phone_number_id' => '123456789',
                        ],
                        'statuses' => [[
                            'id' => 'wamid.STATUS-READ-001',
                            'recipient_id' => '6281234567890',
                            'status' => 'read',
                            'timestamp' => (string) now()->timestamp,
                            'conversation' => [
                                'id' => 'conversation-status-1',
                            ],
                            'pricing' => [
                                'billable' => true,
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $response = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $response->assertOk();

        $message->refresh();

        $this->assertSame(MessageDeliveryStatus::Delivered, $message->delivery_status);
        $this->assertNotNull($message->read_at);
        $this->assertNotNull($message->delivered_at);
        $this->assertSame('read', data_get($message->raw_payload, 'wa_webhook_status.status'));
    }
}
