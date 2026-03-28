<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationContextLoaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationContextLoaderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_follow_up_context_from_older_history_without_bloating_recent_messages(): void
    {
        config(['chatbot.memory.max_recent_messages' => 10]);

        [, $conversation] = $this->makeConversation();
        $baseTime = Carbon::create(2026, 3, 28, 8, 0, 0);

        $this->addMessage(
            $conversation,
            MessageDirection::Inbound,
            SenderType::Customer,
            'saya ingin ke Pekanbaru',
            $baseTime,
        );

        for ($i = 1; $i <= 10; $i++) {
            $this->addMessage(
                $conversation,
                $i % 2 === 1 ? MessageDirection::Outbound : MessageDirection::Inbound,
                $i % 2 === 1 ? SenderType::Bot : SenderType::Customer,
                $i % 2 === 1 ? 'Baik, saya catat dulu.' : 'oke saya cek lagi',
                $baseTime->copy()->addMinutes($i),
            );
        }

        $currentMessage = $this->addMessage(
            $conversation,
            MessageDirection::Inbound,
            SenderType::Customer,
            'besok jam 10 ada?',
            $baseTime->copy()->addMinutes(20),
        );

        $payload = app(ConversationContextLoaderService::class)->load(
            $conversation->fresh(),
            $currentMessage,
        );

        $recentTexts = array_map(
            static fn ($message) => $message->text,
            $payload->recentMessages,
        );

        $this->assertCount(10, $payload->recentMessages);
        $this->assertNotContains('saya ingin ke Pekanbaru', $recentTexts);
        $this->assertSame('Pekanbaru', $payload->resolvedContext['last_destination'] ?? null);
        $this->assertTrue((bool) ($payload->resolvedContext['context_dependency_detected'] ?? false));
        $this->assertStringContainsString('Pekanbaru', (string) $payload->conversationSummary);
        $this->assertStringContainsString('diringkas', (string) $payload->conversationSummary);
    }

    public function test_it_marks_admin_takeover_and_preserves_admin_role_in_recent_history(): void
    {
        [, $conversation] = $this->makeConversation([
            'handoff_mode' => 'admin',
            'handoff_admin_id' => 99,
            'needs_human' => true,
            'summary' => 'Percakapan sedang dipegang admin.',
        ]);

        $baseTime = Carbon::create(2026, 3, 28, 9, 0, 0);

        $this->addMessage(
            $conversation,
            MessageDirection::Outbound,
            SenderType::Agent,
            'Saya bantu cek manual ya.',
            $baseTime,
        );

        $currentMessage = $this->addMessage(
            $conversation,
            MessageDirection::Inbound,
            SenderType::Customer,
            'halo admin',
            $baseTime->copy()->addMinutes(1),
        );

        $payload = app(ConversationContextLoaderService::class)->load(
            $conversation->fresh(),
            $currentMessage,
        );

        $understandingInput = $payload->toUnderstandingInput();

        $this->assertTrue($payload->adminTakeover);
        $this->assertTrue((bool) ($payload->conversationState['admin_takeover'] ?? false));
        $this->assertTrue((bool) ($understandingInput['conversation_state']['admin_takeover'] ?? false));
        $this->assertSame('admin', $understandingInput['recent_history'][0]['role'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(array $overrides = []): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create(array_replace([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ], $overrides));

        return [$customer, $conversation];
    }

    private function addMessage(
        Conversation $conversation,
        MessageDirection $direction,
        SenderType $senderType,
        string $text,
        Carbon $sentAt,
    ): ConversationMessage {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'sender_type' => $senderType,
            'message_type' => 'text',
            'message_text' => $text,
            'sent_at' => $sentAt,
        ]);
    }
}
