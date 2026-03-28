<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ConversationMessage;
use App\Services\OpenAiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WhatsAppWebhookOpenAiSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_webhook_persists_openai_seed_into_inbound_message(): void
    {
        Queue::fake([ProcessIncomingWhatsAppMessage::class]);

        config([
            'openai.enabled' => true,
            'openai.seed_on_webhook' => true,
            'services.openai.api_key' => 'test-key',
        ]);

        $mock = Mockery::mock(OpenAiChatService::class);
        $mock->shouldReceive('detectIntent')
            ->once()
            ->andReturn([
                'intent' => 'booking',
                'confidence' => 0.91,
                'reason' => 'user mau pesan travel',
            ]);
        $mock->shouldReceive('extractBookingData')
            ->once()
            ->andReturn([
                'passenger_name' => 'Nerry',
                'origin' => 'Pasir Pengaraian',
                'destination' => 'Pekanbaru',
                'departure_date' => '2026-03-29',
                'departure_time' => '08:00',
                'seat_count' => 2,
                'phone' => null,
                'notes' => null,
            ]);

        $this->app->instance(OpenAiChatService::class, $mock);

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

        $this->assertSame('booking', $message->ai_intent);
        $this->assertEquals(0.91, (float) $message->ai_confidence);
        $this->assertIsArray($message->raw_payload);
        $this->assertSame('booking', $message->raw_payload['_openai_seed']['intent']['intent'] ?? null);
        $this->assertSame('Pekanbaru', $message->raw_payload['_openai_seed']['booking_data']['destination'] ?? null);

        Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 1);
    }
}
