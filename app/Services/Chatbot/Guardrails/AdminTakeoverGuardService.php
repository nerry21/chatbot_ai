<?php

namespace App\Services\Chatbot\Guardrails;

use App\Models\Conversation;

class AdminTakeoverGuardService
{
    public function shouldSuppressAutomation(Conversation $conversation): bool
    {
        return $conversation->isAdminTakeover();
    }

    /**
     * @return array{admin_takeover: bool, handoff_admin_id: int|null, handoff_at: string|null}
     */
    public function context(Conversation $conversation): array
    {
        return [
            'admin_takeover' => $this->shouldSuppressAutomation($conversation),
            'handoff_admin_id' => $conversation->handoff_admin_id,
            'handoff_at' => $conversation->handoff_at?->toDateTimeString(),
        ];
    }
}
