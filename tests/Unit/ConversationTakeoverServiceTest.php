<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Chatbot\ConversationTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTakeoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_manual_takeover_and_writes_system_trail(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $admin = User::factory()->create([
            'name' => 'Admin Ops',
            'is_chatbot_admin' => true,
        ]);

        $service = app(ConversationTakeoverService::class);
        $updatedConversation = $service->takeOver($conversation, $admin->id, 'manual_takeover');

        $this->assertTrue($updatedConversation->fresh()->isAdminTakeover());
        $this->assertTrue($updatedConversation->fresh()->isAutomationSuppressed());
        $this->assertTrue((bool) $updatedConversation->fresh()->bot_paused);
        $this->assertSame('human_takeover', $updatedConversation->fresh()->bot_paused_reason);
        $this->assertSame($admin->id, $updatedConversation->fresh()->assigned_admin_id);
        $this->assertSame($admin->id, $updatedConversation->fresh()->human_takeover_by);

        $systemMessage = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::System)
            ->latest('id')
            ->first();

        $this->assertNotNull($systemMessage);
        $this->assertStringContainsString('diambil alih', (string) $systemMessage?->message_text);

        $this->assertDatabaseHas('conversation_handoffs', [
            'conversation_id' => $conversation->id,
            'action' => 'takeover',
            'actor_user_id' => $admin->id,
            'assigned_admin_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_takeover',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_it_releases_conversation_back_to_bot_and_updates_status_safely(): void
    {
        [$customer, $conversation] = $this->makeConversation(status: ConversationStatus::Escalated);
        $admin = User::factory()->create([
            'name' => 'Admin Ops',
            'is_chatbot_admin' => true,
        ]);

        $conversation->takeoverBy($admin->id);

        $service = app(ConversationTakeoverService::class);
        $releasedConversation = $service->releaseToBot($conversation->fresh(), $admin->id, 'manual_release');

        $releasedConversation = $releasedConversation->fresh();

        $this->assertFalse($releasedConversation->isAutomationSuppressed());
        $this->assertSame('bot', $releasedConversation->handoff_mode);
        $this->assertFalse((bool) $releasedConversation->bot_paused);
        $this->assertNull($releasedConversation->assigned_admin_id);
        $this->assertSame(ConversationStatus::Active, $releasedConversation->status);
        $this->assertNotNull($releasedConversation->released_to_bot_at);

        $this->assertDatabaseHas('conversation_handoffs', [
            'conversation_id' => $conversation->id,
            'action' => 'release',
            'actor_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'conversation_id' => $conversation->id,
            'action_type' => 'conversation_release',
            'actor_user_id' => $admin->id,
        ]);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(
        string $phone = '+6281234567890',
        ConversationStatus $status = ConversationStatus::Active,
    ): array {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => $phone,
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => $status,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Halo admin',
            'raw_payload' => [],
            'is_fallback' => false,
            'sent_at' => now(),
        ]);

        return [$customer, $conversation];
    }
}
