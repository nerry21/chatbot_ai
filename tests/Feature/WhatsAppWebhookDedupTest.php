<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppWebhookDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_inbound_with_same_wa_message_id_is_skipped(): void
    {
        Queue::fake([ProcessIncomingWhatsAppMessage::class]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'metadata' => [
                                    'display_phone_number' => '6281234567890',
                                    'phone_number_id' => '123456789',
                                ],
                                'contacts' => [
                                    [
                                        'wa_id' => '6281234567890',
                                        'profile' => [
                                            'name' => 'Budi',
                                        ],
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '6281234567890',
                                        'id' => 'wamid.TEST-DEDUP-001',
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Halo, saya mau tanya jadwal.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $first = $this->postJson(route('webhook.whatsapp.receive'), $payload);
        $second = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $first->assertOk();
        $second->assertOk();

        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame(1, ConversationMessage::query()
            ->where('wa_message_id', 'wamid.TEST-DEDUP-001')
            ->count());

        Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 1);
    }

    public function test_inbound_without_wa_message_id_is_skipped(): void
    {
        Queue::fake([ProcessIncomingWhatsAppMessage::class]);

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
                        'contacts' => [[
                            'wa_id' => '6281234567890',
                            'profile' => ['name' => 'Budi'],
                        ]],
                        'messages' => [[
                            'from' => '6281234567890',
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'Halo tanpa ID.'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $response = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $response->assertOk();
        $this->assertDatabaseCount('conversation_messages', 0);
        Queue::assertNothingPushed();
    }
}
