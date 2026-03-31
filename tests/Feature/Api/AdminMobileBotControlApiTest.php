<?php

namespace Tests\Feature\Api;

use App\Enums\ConversationStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminMobileBotControlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_turn_bot_off_and_auto_resume_is_armed(): void
    {
        $admin = $this->createAdmin();
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation();

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.bot-control.off', ['conversation' => $conversation]),
            ['auto_resume_minutes' => 15],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bot_enabled', false)
            ->assertJsonPath('data.bot_paused', true)
            ->assertJsonPath('data.human_takeover', true)
            ->assertJsonPath('data.bot.bot_auto_resume_enabled', true)
            ->assertJsonPath('data.bot.bot_auto_resume_after_minutes', 15);

        $updatedConversation = $conversation->fresh();

        $this->assertTrue($updatedConversation?->isAdminTakeover() ?? false);
        $this->assertTrue((bool) $updatedConversation?->bot_paused);
        $this->assertTrue((bool) $updatedConversation?->bot_auto_resume_enabled);
        $this->assertNotNull($updatedConversation?->bot_auto_resume_at);
        $this->assertSame($admin->id, $updatedConversation?->assigned_admin_id);
    }

    public function test_admin_reply_manual_takeover_turns_bot_off_and_sets_auto_resume(): void
    {
        Queue::fake();

        $admin = $this->createAdmin(['email' => 'reply-admin@example.com']);
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation(channel: 'mobile_live_chat');

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.reply', ['conversation' => $conversation]),
            ['message' => 'Admin ambil alih chat ini sekarang.'],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $updatedConversation = $conversation->fresh();

        $this->assertTrue($updatedConversation?->isAdminTakeover() ?? false);
        $this->assertTrue((bool) $updatedConversation?->bot_paused);
        $this->assertTrue((bool) $updatedConversation?->bot_auto_resume_enabled);
        $this->assertNotNull($updatedConversation?->bot_auto_resume_at);
        $this->assertSame($admin->id, $updatedConversation?->assigned_admin_id);
    }

    public function test_admin_reply_route_preserves_emoji_message_for_whatsapp_delivery(): void
    {
        Queue::fake();

        $admin = $this->createAdmin(['email' => 'emoji-admin@example.com']);
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation(channel: 'whatsapp');
        $emojiMessage = 'Siap admin bantu ya 🙂🙏';

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.reply', ['conversation' => $conversation]),
            ['message' => $emojiMessage],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.notice', 'Balasan admin berhasil diantrekan ke WhatsApp.')
            ->assertJsonPath('data.transport', 'whatsapp')
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'message_text' => $emojiMessage,
        ]);

        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    }

    public function test_admin_can_turn_bot_on_again_after_manual_off(): void
    {
        $admin = $this->createAdmin(['email' => 'reactivate-admin@example.com']);
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation(conversationAttributes: [
            'handoff_mode' => 'admin',
            'handoff_admin_id' => $admin->id,
            'assigned_admin_id' => $admin->id,
            'bot_paused' => true,
            'bot_paused_reason' => 'human_takeover',
            'needs_human' => true,
            'bot_auto_resume_enabled' => true,
            'bot_auto_resume_at' => now()->addMinutes(15),
            'bot_last_admin_reply_at' => now(),
            'last_admin_intervention_at' => now(),
        ]);

        $turnOn = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.bot-control.on', ['conversation' => $conversation]),
        );

        $turnOn->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bot_enabled', true)
            ->assertJsonPath('data.bot_paused', false)
            ->assertJsonPath('data.human_takeover', false)
            ->assertJsonPath('data.bot.bot_auto_resume_enabled', false);

        $detail = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.show', ['conversation' => $conversation]),
        );

        $detail->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.bot_control.enabled', true)
            ->assertJsonPath('data.conversation.bot_control.human_takeover', false);

        $poll = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.poll', ['conversation' => $conversation]),
        );

        $poll->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.bot_control.enabled', true);

        $updatedConversation = $conversation->fresh();

        $this->assertFalse($updatedConversation?->isAutomationSuppressed() ?? true);
        $this->assertSame('bot', $updatedConversation?->handoff_mode);
        $this->assertFalse((bool) $updatedConversation?->bot_paused);
        $this->assertFalse((bool) $updatedConversation?->bot_auto_resume_enabled);
    }

    public function test_sending_contact_also_arms_bot_auto_resume_for_manual_takeover(): void
    {
        Queue::fake();

        $admin = $this->createAdmin(['email' => 'contact-admin@example.com']);
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation(channel: 'whatsapp');

        $response = $this->withToken($token)->postJson(
            route('api.admin-mobile.conversations.send-contact', ['conversation' => $conversation]),
            [
                'full_name' => 'Nerry Popindo',
                'phone' => '+628117598804',
                'email' => 'nerry@example.com',
                'company' => 'JET Travel',
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $updatedConversation = $conversation->fresh();

        $this->assertTrue($updatedConversation?->isAdminTakeover() ?? false);
        $this->assertTrue((bool) $updatedConversation?->bot_paused);
        $this->assertTrue((bool) $updatedConversation?->bot_auto_resume_enabled);
        $this->assertNotNull($updatedConversation?->bot_auto_resume_at);
        $this->assertSame($admin->id, $updatedConversation?->assigned_admin_id);
    }

    public function test_status_endpoint_auto_reactivates_bot_after_timeout(): void
    {
        $admin = $this->createAdmin(['email' => 'timeout-admin@example.com']);
        $token = $this->loginAdmin($admin);
        $conversation = $this->createConversation(conversationAttributes: [
            'handoff_mode' => 'admin',
            'handoff_admin_id' => $admin->id,
            'assigned_admin_id' => $admin->id,
            'bot_paused' => true,
            'bot_paused_reason' => 'human_takeover',
            'needs_human' => true,
            'bot_auto_resume_enabled' => true,
            'bot_auto_resume_at' => now()->subMinute(),
            'bot_last_admin_reply_at' => now()->subMinutes(16),
            'last_admin_intervention_at' => now()->subMinutes(16),
        ]);

        $response = $this->withToken($token)->getJson(
            route('api.admin-mobile.conversations.bot-control.status', ['conversation' => $conversation]),
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bot_enabled', true)
            ->assertJsonPath('data.bot_paused', false)
            ->assertJsonPath('data.human_takeover', false)
            ->assertJsonPath('data.bot.bot_auto_resume_enabled', false);

        $updatedConversation = $conversation->fresh();

        $this->assertFalse($updatedConversation?->isAutomationSuppressed() ?? true);
        $this->assertSame('bot', $updatedConversation?->handoff_mode);
        $this->assertFalse((bool) $updatedConversation?->bot_paused);
        $this->assertFalse((bool) $updatedConversation?->bot_auto_resume_enabled);
    }

    private function createAdmin(array $attributes = []): User
    {
        $index = User::query()->count() + 1;

        return User::factory()->create(array_merge([
            'name' => 'Admin Mobile '.$index,
            'email' => "admin{$index}@example.com",
            'password' => Hash::make('super-secret'),
            'is_chatbot_admin' => true,
            'is_chatbot_operator' => false,
        ], $attributes));
    }

    private function loginAdmin(User $user, string $password = 'super-secret'): string
    {
        $response = $this->postJson(route('api.admin-mobile.auth.login'), [
            'email' => $user->email,
            'password' => $password,
            'device_name' => 'QA Admin Mobile',
            'device_id' => 'qa-admin-mobile-device-'.$user->id,
        ]);

        $response->assertOk();

        return (string) $response->json('data.access_token');
    }

    private function createConversation(
        string $channel = 'whatsapp',
        array $conversationAttributes = [],
        array $customerAttributes = [],
    ): Conversation {
        $index = Customer::query()->count() + 1;

        $customer = Customer::create(array_merge([
            'name' => 'Customer '.$index,
            'phone_e164' => $channel === 'mobile_live_chat'
                ? 'mlc:'.substr(hash('sha256', 'customer-'.$index), 0, 32)
                : '+6281234500'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'email' => "customer{$index}@example.com",
            'mobile_user_id' => 'mobile-user-'.$index,
            'status' => 'active',
        ], $customerAttributes));

        return Conversation::create(array_merge([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'needs_human' => false,
            'bot_paused' => false,
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now()->subMinutes(2),
            'source_app' => $channel === 'mobile_live_chat' ? 'flutter' : 'web-dashboard',
        ], $conversationAttributes));
    }
}
