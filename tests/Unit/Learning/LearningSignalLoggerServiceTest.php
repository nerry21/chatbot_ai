<?php

namespace Tests\Unit\Learning;

use App\Data\AI\LearningSignalPayload;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\ChatbotCaseMemory;
use App\Models\ChatbotLearningSignal;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\Learning\LearningSignalLoggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningSignalLoggerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_turn_and_creates_case_memory_for_clean_successful_reply(): void
    {
        [$conversation, $inbound, $outbound] = $this->makeConversationTurn();

        $signal = app(LearningSignalLoggerService::class)->logTurn(
            new LearningSignalPayload(
                conversationId: $conversation->id,
                inboundMessageId: $inbound->id,
                userMessage: 'besok jam 10 ke Pekanbaru ada?',
                contextSummary: 'User cek jadwal ke Pekanbaru.',
                contextSnapshot: [
                    'resolved_context' => [
                        'last_destination' => 'Pekanbaru',
                    ],
                ],
                understandingResult: [
                    'intent' => 'tanya_jam',
                    'confidence' => 0.94,
                    'needs_clarification' => false,
                    'uses_previous_context' => true,
                ],
                chosenAction: 'check_schedule',
                groundedFacts: [
                    'requested_schedule' => [
                        'available' => true,
                        'travel_time' => '10:00',
                    ],
                ],
                finalResponse: 'Untuk keberangkatan besok ke Pekanbaru, jadwal pukul 10.00 tersedia.',
                finalResponseMeta: [
                    'source' => 'grounded_response_composer',
                    'grounded_mode' => 'direct_answer',
                ],
                fallbackUsed: false,
                handoffHappened: false,
                adminTakeoverActive: false,
                outboundSent: true,
                outboundMessageId: $outbound->id,
                classifierContext: [
                    'policy_guard' => ['action' => 'allow'],
                    'hallucination_guard' => ['blocked' => false, 'action' => 'allow'],
                    'reply_guard' => [],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                        'departure_date' => '2026-03-29',
                        'departure_time' => '10:00',
                    ],
                ],
            ),
        );

        $this->assertInstanceOf(ChatbotLearningSignal::class, $signal);
        $this->assertSame('answered', $signal->resolution_status);
        $this->assertNull($signal->failure_type);

        $memory = ChatbotCaseMemory::query()->where('learning_signal_id', $signal->id)->first();

        $this->assertNotNull($memory);
        $this->assertSame('tanya_jam', $memory->intent);
        $this->assertStringContainsString('10.00 tersedia', (string) $memory->successful_response);
    }

    /**
     * @return array{0: Conversation, 1: ConversationMessage, 2: ConversationMessage}
     */
    private function makeConversationTurn(): array
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

        $inbound = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'besok jam 10 ke Pekanbaru ada?',
            'sent_at' => now(),
        ]);

        $outbound = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Untuk keberangkatan besok ke Pekanbaru, jadwal pukul 10.00 tersedia.',
            'sent_at' => now(),
        ]);

        return [$conversation, $inbound, $outbound];
    }
}
