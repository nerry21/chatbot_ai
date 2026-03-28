<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\ConversationTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTagServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_normalized_conversation_and_customer_tags_without_duplicates(): void
    {
        $service = app(ConversationTagService::class);
        $admin = User::factory()->create([
            'is_chatbot_admin' => true,
        ]);

        [$conversation, $customer] = $this->makeConversation();

        $service->addConversationTag($conversation, 'Follow Up VIP', $admin->id);
        $service->addConversationTag($conversation, 'Follow Up VIP', $admin->id);
        $service->addCustomerTag($customer, 'Repeat Buyer', $admin->id, $conversation);

        $this->assertSame(1, $conversation->tags()->count());
        $this->assertSame('follow-up-vip', (string) $conversation->tags()->value('tag'));
        $this->assertSame(1, $customer->tags()->count());
        $this->assertSame('repeat-buyer', (string) $customer->tags()->value('tag'));

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_tagged',
            'actor_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'customer_tagged',
            'actor_user_id' => $admin->id,
        ]);
    }

    /**
     * @return array{0: Conversation, 1: Customer}
     */
    private function makeConversation(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$conversation, $customer];
    }
}
