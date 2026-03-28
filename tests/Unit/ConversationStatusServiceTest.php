<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\ConversationStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_escalated_urgent_close_and_reopen_with_audit_and_system_messages(): void
    {
        $service = app(ConversationStatusService::class);
        $admin = User::factory()->create([
            'name' => 'Ops Admin',
            'is_chatbot_admin' => true,
        ]);

        $conversation = $this->makeConversation();

        $escalated = $service->markEscalated($conversation, $admin->id, 'manual_console_escalation');
        $escalatedSnapshot = $escalated->fresh();
        $urgent = $service->setUrgency($escalatedSnapshot, $admin->id, true);
        $urgentSnapshot = $urgent->fresh();
        $closed = $service->close($urgentSnapshot, $admin->id, 'handled_and_closed');
        $closedSnapshot = $closed->fresh();
        $reopened = $service->reopen($closed->fresh(), $admin->id);
        $reopenedSnapshot = $reopened->fresh();

        $this->assertSame(ConversationStatus::Escalated, $escalatedSnapshot->status);
        $this->assertTrue((bool) $escalatedSnapshot->bot_paused);
        $this->assertTrue((bool) $urgentSnapshot->is_urgent);
        $this->assertSame(ConversationStatus::Closed, $closedSnapshot->status);
        $this->assertNotNull($closedSnapshot->closed_at);
        $this->assertSame(ConversationStatus::Active, $reopenedSnapshot->status);
        $this->assertNotNull($reopenedSnapshot->reopened_at);

        $this->assertDatabaseHas('escalations', [
            'conversation_id' => $conversation->id,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_marked_escalated',
            'actor_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_closed',
            'actor_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_reopened',
            'actor_user_id' => $admin->id,
        ]);

        $this->assertGreaterThanOrEqual(4, $conversation->fresh()->messages()->where('sender_type', 'system')->count());
    }

    private function makeConversation(): Conversation
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        return Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);
    }
}
