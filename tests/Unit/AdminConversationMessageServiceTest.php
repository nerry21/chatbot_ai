<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\AdminConversationMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminConversationMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_auto_takes_over_and_queues_admin_message(): void
    {
        Queue::fake();

        [$customer, $conversation] = $this->makeConversation();
        $admin = User::factory()->create([
            'name' => 'Admin Panel',
            'is_chatbot_admin' => true,
        ]);

        $service = app(AdminConversationMessageService::class);
        $result = $service->send($conversation, 'Baik, kami bantu cek dulu ya.', $admin->id, 'live_chat_panel');

        $this->assertSame('queued', $result['status']);
        $this->assertFalse($result['duplicate']);

        $message = $result['message']->fresh();

        $this->assertSame('admin', $message->sender_type->value);
        $this->assertSame('outbound', $message->direction->value);
        $this->assertSame('pending', $message->delivery_status?->value);
        $this->assertSame($admin->id, data_get($message->raw_payload, 'admin_id'));
        $this->assertSame('Admin Panel', data_get($message->raw_payload, 'admin_name'));
        $this->assertSame('live_chat_panel', data_get($message->raw_payload, 'source'));
        $this->assertTrue($conversation->fresh()->isAdminTakeover());
        $this->assertSame($admin->id, $conversation->fresh()->assigned_admin_id);

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    }

    public function test_it_prevents_duplicate_double_submit_for_same_admin_message(): void
    {
        Queue::fake();

        [$customer, $conversation] = $this->makeConversation();
        $admin = User::factory()->create([
            'name' => 'Admin Panel',
            'is_chatbot_admin' => true,
        ]);

        $service = app(AdminConversationMessageService::class);

        $first = $service->send($conversation, 'Mohon tunggu sebentar ya.', $admin->id, 'live_chat_panel');
        $second = $service->send($conversation->fresh(), 'Mohon tunggu sebentar ya.', $admin->id, 'live_chat_panel');

        $this->assertSame('queued', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['message']->id, $second['message']->id);
        $this->assertSame(1, ConversationMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'admin')->count());
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    }

    public function test_it_marks_mobile_live_chat_admin_reply_as_sent_without_queuing_whatsapp(): void
    {
        Queue::fake();

        [$customer, $conversation] = $this->makeConversation(
            phone: 'mlc:'.substr(hash('sha256', 'mobile-admin-1'), 0, 32),
            channel: 'mobile_live_chat',
        );

        $admin = User::factory()->create([
            'name' => 'Admin Mobile',
            'is_chatbot_admin' => true,
        ]);

        $service = app(AdminConversationMessageService::class);
        $result = $service->send($conversation, 'Halo dari live chat mobile.', $admin->id, 'live_chat_panel');

        $this->assertSame('queued', $result['status']);
        $this->assertSame('mobile_live_chat', $result['transport']);
        $this->assertFalse($result['duplicate']);

        $message = $result['message']->fresh();

        $this->assertSame('admin', $message->sender_type->value);
        $this->assertSame('outbound', $message->direction->value);
        $this->assertSame('sent', $message->delivery_status?->value);
        Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(string $phone = '+6281234567890', string $channel = 'whatsapp'): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => $phone,
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }
}
