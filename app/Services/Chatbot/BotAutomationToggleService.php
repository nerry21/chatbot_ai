<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BotAutomationToggleService
{
    public const DEFAULT_AUTO_RESUME_MINUTES = 15;

    public function __construct(
        private readonly ConversationTakeoverService $takeoverService,
    ) {}

    public function autoResumeMinutes(): int
    {
        return max(1, (int) config('chatbot.admin_mobile.bot_auto_resume_after_minutes', self::DEFAULT_AUTO_RESUME_MINUTES));
    }

    public function turnBotOn(Conversation $conversation, ?int $actorAdminId = null, ?string $reason = null): Conversation
    {
        $conversation = $this->takeoverService->releaseToBot(
            conversation: $conversation,
            adminId: $actorAdminId,
            reason: $reason ?: 'bot_toggle_on',
        );

        $conversation->forceFill([
            'bot_auto_resume_enabled' => false,
            'bot_auto_resume_at' => null,
        ])->save();

        return $conversation->fresh(['customer', 'assignedAdmin', 'handoffAdmin']) ?? $conversation;
    }

    public function turnBotOff(
        Conversation $conversation,
        ?int $actorAdminId = null,
        ?int $autoResumeMinutes = null,
        ?string $reason = null,
    ): Conversation {
        $conversation = $this->takeoverService->takeOver(
            conversation: $conversation,
            adminId: $actorAdminId,
            reason: $reason ?: 'bot_toggle_off',
        );

        $minutes = $autoResumeMinutes ?? $this->autoResumeMinutes();

        $conversation->forceFill([
            'bot_auto_resume_enabled' => true,
            'bot_auto_resume_at' => now()->addMinutes($minutes),
            'bot_last_admin_reply_at' => now(),
            'last_admin_intervention_at' => now(),
        ])->save();

        return $conversation->fresh(['customer', 'assignedAdmin', 'handoffAdmin']) ?? $conversation;
    }

    public function registerAdminReply(Conversation $conversation, int $adminId, ?int $autoResumeMinutes = null): Conversation
    {
        if (! $conversation->isAdminTakeover() || (int) ($conversation->assigned_admin_id ?? 0) !== $adminId) {
            $conversation = $this->takeoverService->takeOver(
                conversation: $conversation,
                adminId: $adminId,
                reason: 'admin_manual_reply',
            );
        }

        $minutes = $autoResumeMinutes ?? $this->autoResumeMinutes();

        $conversation->forceFill([
            'bot_auto_resume_enabled' => true,
            'bot_auto_resume_at' => now()->addMinutes($minutes),
            'bot_last_admin_reply_at' => now(),
            'last_admin_intervention_at' => now(),
            'assigned_admin_id' => $adminId,
        ])->save();

        return $conversation->fresh(['customer', 'assignedAdmin', 'handoffAdmin']) ?? $conversation;
    }

    public function shouldSuppressBot(Conversation $conversation): bool
    {
        $conversation = $this->resumeIfDue($conversation);

        return $conversation->isAutomationSuppressed();
    }

    public function resumeIfDue(Conversation $conversation, ?int $actorAdminId = null): Conversation
    {
        $conversation = $conversation->fresh(['customer', 'assignedAdmin', 'handoffAdmin']) ?? $conversation;

        if ($conversation->shouldAutoResumeBot()) {
            return $this->turnBotOn(
                conversation: $conversation,
                actorAdminId: $actorAdminId,
                reason: 'auto_resume_after_admin_inactivity',
            );
        }

        return $conversation;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function reactivateExpiredConversations(int $limit = 100): Collection
    {
        /** @var Collection<int, Conversation> $conversations */
        $conversations = Conversation::query()
            ->where('handoff_mode', 'admin')
            ->where('bot_paused', true)
            ->where('bot_auto_resume_enabled', true)
            ->whereNotNull('bot_auto_resume_at')
            ->where('bot_auto_resume_at', '<=', now())
            ->orderBy('bot_auto_resume_at')
            ->limit(max(1, $limit))
            ->get();

        return $conversations->map(function (Conversation $conversation): Conversation {
            return DB::transaction(function () use ($conversation): Conversation {
                $locked = Conversation::query()->lockForUpdate()->find($conversation->id) ?? $conversation;

                return $this->resumeIfDue($locked);
            }, 3);
        });
    }

    public function ensureAutoResumed(Conversation $conversation, ?int $adminId = null): Conversation
    {
        return $this->resumeIfDue($conversation, $adminId);
    }

    public function reactivateExpiredTakeovers(int $limit = 200): int
    {
        return $this->reactivateExpiredConversations($limit)
            ->filter(fn (Conversation $conversation): bool => ! $conversation->isAutomationSuppressed())
            ->count();
    }

    public function setBotEnabled(
        Conversation $conversation,
        bool $enabled,
        ?int $adminId,
        string $reason = 'admin_mobile_toggle',
    ): Conversation {
        return $enabled
            ? $this->turnBotOn($conversation, $adminId, $reason)
            : $this->turnBotOff($conversation, $adminId, $this->autoResumeMinutes(), $reason);
    }

    public function touchAdminIntervention(Conversation $conversation, ?int $adminId = null): void
    {
        $updates = [
            'last_admin_intervention_at' => now(),
            'assigned_admin_id' => $adminId ?? $conversation->assigned_admin_id,
        ];

        if ($conversation->isAdminTakeover() || (bool) $conversation->bot_paused) {
            $updates['bot_auto_resume_enabled'] = true;
            $updates['bot_auto_resume_at'] = now()->addMinutes($this->autoResumeMinutes());
            $updates['bot_last_admin_reply_at'] = now();
        }

        $conversation->forceFill($updates)->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function statePayload(Conversation $conversation): array
    {
        $conversation = $this->resumeIfDue($conversation);

        return [
            'bot_enabled' => ! $conversation->isAutomationSuppressed(),
            'bot_paused' => (bool) $conversation->bot_paused,
            'handoff_mode' => (string) ($conversation->handoff_mode ?? 'bot'),
            'bot_paused_reason' => $conversation->bot_paused_reason,
            'assigned_admin_id' => $conversation->assigned_admin_id,
            'assigned_admin_name' => $conversation->assignedAdmin?->name,
            'last_admin_intervention_at' => $conversation->last_admin_intervention_at?->toIso8601String(),
            'bot_auto_resume_after_minutes' => $this->autoResumeMinutes(),
            'bot_auto_resume_enabled' => (bool) ($conversation->bot_auto_resume_enabled ?? false),
            'bot_auto_resume_at' => $conversation->bot_auto_resume_at?->toIso8601String(),
            'bot_last_admin_reply_at' => $conversation->bot_last_admin_reply_at?->toIso8601String(),
        ];
    }
}
