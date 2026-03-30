<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppSenderService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSenderServiceTest extends TestCase
{
    public function test_it_falls_back_to_text_when_interactive_payload_is_rejected(): void
    {
        config()->set('chatbot.whatsapp.enabled', true);
        config()->set('chatbot.whatsapp.access_token', 'test-token');
        config()->set('chatbot.whatsapp.phone_number_id', '123456789');
        config()->set('chatbot.whatsapp.interactive_enabled', true);
        config()->set('chatbot.whatsapp.interactive_text_fallback_enabled', true);

        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $body = json_decode($request->body(), true);
            $requests[] = $body;

            if (($body['type'] ?? null) === 'interactive') {
                return Http::response([
                    'error' => ['message' => 'Unsupported interactive parameter'],
                ], 400);
            }

            return Http::response([
                'messages' => [
                    ['id' => 'wamid.outbound-001'],
                ],
            ], 200);
        });

        $service = app(WhatsAppSenderService::class);

        $result = $service->sendMessage(
            toPhoneE164: '+6281234567890',
            text: "Izin Bapak/Ibu, mohon pilih lokasi penjemputannya ya.\n\n1. SKPD\n2. Simpang D",
            messageType: 'interactive',
            providerPayload: [
                'interactive' => [
                    'type' => 'list',
                    'body' => ['text' => 'Pilih lokasi'],
                    'action' => [
                        'button' => 'Pilih',
                        'sections' => [[
                            'title' => 'Lokasi',
                            'rows' => [
                                ['id' => 'pickup_location:skpd', 'title' => 'SKPD'],
                            ],
                        ]],
                    ],
                ],
            ],
        );

        $this->assertSame('sent', $result['status']);
        $this->assertTrue($result['fallback_used'] ?? false);
        $this->assertSame('interactive', $result['requested_type'] ?? null);
        $this->assertSame('text', $result['sent_type'] ?? null);
        $this->assertSame('interactive', $requests[0]['type'] ?? null);
        $this->assertSame('text', $requests[1]['type'] ?? null);
        $this->assertCount(2, $requests);
    }

    public function test_it_falls_back_to_template_when_24_hour_window_is_closed(): void
    {
        config()->set('chatbot.whatsapp.enabled', true);
        config()->set('chatbot.whatsapp.access_token', 'test-token');
        config()->set('chatbot.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.reengagement_template_enabled', true);
        config()->set('services.whatsapp.reengagement_template_name', 'reengagement_notice');
        config()->set('services.whatsapp.reengagement_template_language', 'id');

        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $body = json_decode($request->body(), true);
            $requests[] = $body;

            if (($body['type'] ?? null) === 'text') {
                return Http::response([
                    'error' => [
                        'code' => 131047,
                        'message' => '24 hours have passed since the customer last replied to this number.',
                    ],
                ], 400);
            }

            return Http::response([
                'messages' => [
                    ['id' => 'wamid.template-001'],
                ],
            ], 200);
        });

        $service = app(WhatsAppSenderService::class);

        $result = $service->sendMessage(
            toPhoneE164: '+6281234567890',
            text: 'Halo, kami follow up kembali ya.',
        );

        $this->assertSame('sent', $result['status']);
        $this->assertTrue($result['fallback_used'] ?? false);
        $this->assertSame('text', $result['requested_type'] ?? null);
        $this->assertSame('template', $result['sent_type'] ?? null);
        $this->assertSame('text', $requests[0]['type'] ?? null);
        $this->assertSame('template', $requests[1]['type'] ?? null);
        $this->assertCount(2, $requests);
    }
}
