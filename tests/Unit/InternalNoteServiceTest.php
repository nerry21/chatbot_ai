<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\InternalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_conversation_and_customer_notes_without_sending_messages(): void
    {
        $service = app(InternalNoteService::class);
        $admin = User::factory()->create([
            'name' => 'Ops Admin',
            'is_chatbot_admin' => true,
        ]);

        [$conversation, $customer] = $this->makeConversation();

        $conversationNote = $service->addConversationNote($conversation, 'Customer minta follow-up sore ini.', $admin->id);
        $customerNote = $service->addCustomerNote($customer, 'Customer termasuk repeat buyer.', $admin->id, $conversation);

        $this->assertSame(2, $service->recentForConversation($conversation)->count());
        $this->assertSame(0, $conversation->messages()->count());
        $this->assertSame(Conversation::class, $conversationNote->noteable_type);
        $this->assertSame(Customer::class, $customerNote->noteable_type);

        $this->assertDatabaseCount('admin_notes', 2);
        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'internal_note_created',
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
