<?php

namespace Tests\Unit\Learning;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\ChatbotAdminCorrection;
use App\Models\ChatbotCaseMemory;
use App\Models\ChatbotLearningSignal;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\Learning\AdminCorrectionLoggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCorrectionLoggerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_admin_correction_and_marks_signal_corrected(): void
    {
        [$conversation, $inbound, $botReply] = $this->makeConversationTurn();

        $signal = ChatbotLearningSignal::create([
            'conversation_id' => $conversation->id,
            'inbound_message_id' => $inbound->id,
            'outbound_message_id' => $botReply->id,
            'user_message' => $inbound->message_text,
            'context_summary' => 'User tanya jadwal tapi jawaban bot kurang tepat.',
            'understanding_result' => [
                'intent' => 'tanya_jam',
                'confidence' => 0.88,
            ],
            'chosen_action' => 'compose_ai_reply',
            'final_response' => $botReply->message_text,
            'final_response_meta' => [
                'source' => 'grounded_response_composer',
            ],
            'resolution_status' => 'answered',
            'fallback_used' => false,
            'handoff_happened' => true,
            'admin_takeover_active' => false,
            'outbound_sent' => true,
            'failure_type' => 'unsafe_hallucination_attempt',
            'failure_signals' => [
                'hallucination_action' => 'handoff',
            ],
        ]);

        $adminReply = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Agent,
            'message_type' => 'text',
            'message_text' => 'Waalaikumsalam, untuk besok jam 10 ke Pekanbaru tersedia ya. Jika ingin, saya bantu lanjut booking.',
            'raw_payload' => ['admin_id' => 77],
            'sent_at' => now()->addMinute(),
        ]);

        $correction = app(AdminCorrectionLoggerService::class)->captureForAdminReply(
            conversation: $conversation->fresh(),
            adminMessage: $adminReply,
            adminId: 77,
        );

        $this->assertInstanceOf(ChatbotAdminCorrection::class, $correction);
        $this->assertSame($signal->id, $correction->learning_signal_id);
        $this->assertSame('unsafe_hallucination_attempt', $correction->failure_type?->value);
        $this->assertSame(77, $correction->admin_id);

        $signal->refresh();
        $this->assertTrue($signal->corrected_by_admin);
        $this->assertNotNull($signal->corrected_at);

        $memory = ChatbotCaseMemory::query()->where('admin_correction_id', $correction->id)->first();

        $this->assertNotNull($memory);
        $this->assertSame('admin_correction', $memory->source_type);
        $this->assertStringContainsString('tersedia', (string) $memory->successful_response);
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
            'needs_human' => true,
        ]);

        $inbound = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?',
            'sent_at' => now(),
        ]);

        $botReply = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Izin Bapak/Ibu, untuk detail ini kami bantu teruskan ke admin ya.',
            'sent_at' => now(),
        ]);

        return [$conversation, $inbound, $botReply];
    }
}
