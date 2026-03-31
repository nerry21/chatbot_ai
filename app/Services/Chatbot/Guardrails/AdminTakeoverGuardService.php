<?php

namespace App\Services\Chatbot\Guardrails;

use App\Models\Conversation;
use App\Services\Chatbot\BotAutomationToggleService;

class AdminTakeoverGuardService
{
    public function __construct(
        private readonly ?BotAutomationToggleService $botToggleService = null,
    ) {}

    public function shouldSuppressAutomation(Conversation $conversation): bool
    {
        $normalizedConversation = $this->botToggleService?->ensureAutoResumed($conversation) ?? $conversation;

        return $normalizedConversation->isAutomationSuppressed();
    }

    /**
     * @return array{
     *     admin_takeover: bool,
     *     handoff_admin_id: int|null,
     *     handoff_at: string|null,
     *     bot_paused: bool,
     *     bot_paused_reason: string|null,
     *     assigned_admin_id: int|null,
     *     last_admin_intervention_at: string|null,
     *     operational_mode: string
     * }
     */
    public function context(Conversation $conversation): array
    {
        $conversation = $this->botToggleService?->ensureAutoResumed($conversation) ?? $conversation;

        return [
            'admin_takeover' => $this->shouldSuppressAutomation($conversation),
            'handoff_admin_id' => $conversation->handoff_admin_id,
            'handoff_at' => $conversation->handoff_at?->toDateTimeString(),
            'bot_paused' => (bool) $conversation->bot_paused,
            'bot_paused_reason' => $conversation->bot_paused_reason,
            'assigned_admin_id' => $conversation->assigned_admin_id,
            'last_admin_intervention_at' => $conversation->last_admin_intervention_at?->toDateTimeString(),
            'operational_mode' => $conversation->currentOperationalMode(),
        ];
    }
}
