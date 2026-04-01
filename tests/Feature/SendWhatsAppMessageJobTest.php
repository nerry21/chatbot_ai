<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Support\AuditLogService;
use App\Services\WhatsApp\WhatsAppSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendWhatsAppMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_image_message_even_when_caption_is_empty(): void
    {
        config()->set('chatbot.whatsapp.enabled', true);
        config()->set('chatbot.whatsapp.access_token', 'test-token');
        config()->set('chatbot.whatsapp.phone_number_id', '123456789');

        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $requests[] = json_decode($request->body(), true);

            return Http::response([
                'messages' => [
                    ['id' => 'wamid.job-image-001'],
                ],
            ], 200);
        });

        $customer = Customer::create([
            'name' => 'Outbound Image Customer',
            'phone_e164' => '+628123450222',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now(),
            'source_app' => 'web-dashboard',
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'image',
            'message_text' => '',
            'raw_payload' => [
                'outbound_payload' => [
                    'image' => [
                        'link' => 'https://spesial.online/api/admin-mobile/media/messages/999?signature=test',
                    ],
                ],
            ],
            'delivery_status' => MessageDeliveryStatus::Pending,
            'sent_at' => now(),
        ]);

        $job = new SendWhatsAppMessageJob($message->id);
        $job->handle(app(WhatsAppSenderService::class), app(AuditLogService::class));

        $freshMessage = $message->fresh();

        $this->assertSame(MessageDeliveryStatus::Sent, $freshMessage?->delivery_status);
        $this->assertSame('wamid.job-image-001', $freshMessage?->wa_message_id);
        $this->assertSame('image', $requests[0]['type'] ?? null);
        $this->assertSame(
            'https://spesial.online/api/admin-mobile/media/messages/999?signature=test',
            $requests[0]['image']['link'] ?? null,
        );
    }
}
