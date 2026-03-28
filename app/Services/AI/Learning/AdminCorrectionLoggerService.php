<?php

namespace App\Services\AI\Learning;

use App\Enums\SenderType;
use App\Models\ChatbotAdminCorrection;
use App\Models\ChatbotLearningSignal;
use App\Models\Conversation;
use App\Models\ConversationMessage;

class AdminCorrectionLoggerService
{
    public function __construct(
        private readonly CaseMemoryService $caseMemoryService,
    ) {
    }

    public function captureForAdminReply(
        Conversation $conversation,
        ConversationMessage $adminMessage,
        ?int $adminId = null,
    ): ?ChatbotAdminCorrection {
        if (! config('chatbot.continuous_improvement.enabled', true)) {
            return null;
        }

        if (! $adminMessage->isOutbound() || $adminMessage->sender_type !== SenderType::Agent) {
            return null;
        }

        $existing = ChatbotAdminCorrection::query()
            ->where('admin_message_id', $adminMessage->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $windowHours = max(1, (int) config('chatbot.continuous_improvement.correction_window_hours', 24));
        $cutoff = now()->subHours($windowHours);

        $latestInbound = $conversation->messages()
            ->where('id', '!=', $adminMessage->id)
            ->where('sent_at', '>=', $cutoff)
            ->inbound()
            ->latest('sent_at')
            ->latest('id')
            ->first();

        if ($latestInbound === null) {
            return null;
        }

        $latestBotMessage = $conversation->messages()
            ->where('id', '!=', $adminMessage->id)
            ->where('sent_at', '>=', $cutoff)
            ->outbound()
            ->where('sender_type', SenderType::Bot)
            ->latest('sent_at')
            ->latest('id')
            ->first();

        $learningSignal = $this->resolveLearningSignal($conversation, $latestInbound, $latestBotMessage);
        if ($learningSignal !== null && $learningSignal->corrected_by_admin) {
            return null;
        }

        $correction = ChatbotAdminCorrection::create([
            'conversation_id' => $conversation->id,
            'learning_signal_id' => $learningSignal?->id,
            'inbound_message_id' => $latestInbound->id,
            'bot_message_id' => $latestBotMessage?->id,
            'admin_message_id' => $adminMessage->id,
            'admin_id' => $adminId,
            'failure_type' => $learningSignal?->failure_type?->value,
            'reason' => $this->buildReason($learningSignal, $latestBotMessage, $conversation),
            'customer_message_text' => $latestInbound->message_text,
            'bot_response_text' => $latestBotMessage?->message_text,
            'admin_correction_text' => $adminMessage->message_text,
            'correction_payload' => [
                'conversation_status' => $conversation->status?->value ?? (string) $conversation->status,
                'conversation_needs_human' => (bool) $conversation->needs_human,
                'admin_takeover_active' => $conversation->isAdminTakeover(),
                'linked_signal_resolution' => $learningSignal?->resolution_status,
            ],
        ]);

        if ($learningSignal !== null) {
            $learningSignal->forceFill([
                'corrected_by_admin' => true,
                'corrected_at' => now(),
            ])->save();
        }

        $this->caseMemoryService->rememberFromAdminCorrection($correction);

        return $correction;
    }

    private function resolveLearningSignal(
        Conversation $conversation,
        ConversationMessage $latestInbound,
        ?ConversationMessage $latestBotMessage,
    ): ?ChatbotLearningSignal {
        return ChatbotLearningSignal::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($query) use ($latestInbound, $latestBotMessage): void {
                $query->where('inbound_message_id', $latestInbound->id);

                if ($latestBotMessage !== null) {
                    $query->orWhere('outbound_message_id', $latestBotMessage->id);
                }
            })
            ->latest('id')
            ->first();
    }

    private function buildReason(
        ?ChatbotLearningSignal $learningSignal,
        ?ConversationMessage $latestBotMessage,
        Conversation $conversation,
    ): string {
        if ($learningSignal?->failure_type !== null) {
            return 'admin_reply_after_' . $learningSignal->failure_type->value;
        }

        if ($learningSignal?->fallback_used) {
            return 'admin_reply_after_fallback';
        }

        if ($learningSignal?->handoff_happened || $conversation->needs_human) {
            return 'admin_reply_after_handoff';
        }

        if ($latestBotMessage !== null) {
            return 'admin_reply_after_bot_message';
        }

        return 'manual_admin_intervention';
    }
}
