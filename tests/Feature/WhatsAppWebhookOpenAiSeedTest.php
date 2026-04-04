<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppWebhookOpenAiSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_persists_ingress_seed_into_inbound_message(): void
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
                                            'name' => 'Nerry',
                                        ],
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '6281234567890',
                                        'id' => 'wamid.TEST-SEED-001',
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Nama saya Nerry, 2 kursi dari Pasir Pengaraian ke Pekanbaru besok jam 8',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson(route('webhook.whatsapp.receive'), $payload);

        $response->assertOk();

        /** @var ConversationMessage $message */
        $message = ConversationMessage::query()
            ->where('wa_message_id', 'wamid.TEST-SEED-001')
            ->firstOrFail();

        $this->assertNull($message->ai_intent);
        $this->assertNull($message->ai_confidence);
        $this->assertIsArray($message->raw_payload);
        $this->assertSame('whatsapp_webhook_ingress', $message->raw_payload['_ingress_seed']['source'] ?? null);
        $this->assertSame('whatsapp', $message->raw_payload['_ingress_seed']['channel'] ?? null);
        $this->assertSame('text', $message->raw_payload['_ingress_seed']['message_type'] ?? null);
        $this->assertTrue((bool) ($message->raw_payload['_ingress_seed']['has_text'] ?? false));
        $this->assertSame(
            'Nama saya Nerry, 2 kursi dari Pasir Pengaraian ke Pekanbaru besok jam 8',
            $message->raw_payload['_ingress_seed']['text_preview'] ?? null
        );

        Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 1);
    }
}
