<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\User;
use App\Services\Support\AuditLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationStatusService
{
    public function __construct(
        private readonly ConversationTakeoverService $takeoverService,
        private readonly ConversationManagerService $conversationManager,
        private readonly AuditLogService $audit,
    ) {}

    public function markEscalated(Conversation $conversation, int $actorId, ?string $reason = null): Conversation
    {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($actorId, $reason): Conversation {
            $reason = trim((string) ($reason ?: 'manual_console_escalation'));

            DB::transaction(function () use ($lockedConversation, $actorId, $reason): void {
                $this->takeoverService->pauseForEscalation($lockedConversation, $reason);

                $lockedConversation->update([
                    'status' => ConversationStatus::Escalated,
                    'needs_human' => true,
                    'escalation_reason' => $reason,
                    'last_admin_intervention_at' => now(),
                ]);

                $escalation = Escalation::query()
                    ->where('conversation_id', $lockedConversation->id)
                    ->whereIn('status', ['open', 'assigned'])
                    ->latest('id')
                    ->first();

                if ($escalation === null) {
                    $escalation = Escalation::create([
                        'conversation_id' => $lockedConversation->id,
                        'reason' => $reason,
                        'priority' => $lockedConversation->is_urgent ? 'urgent' : 'high',
                        'status' => 'open',
                        'summary' => $lockedConversation->summary,
                    ]);
                } elseif ($lockedConversation->is_urgent && $escalation->priority !== 'urgent') {
                    $escalation->update(['priority' => 'urgent']);
                }

                $actorName = $this->actorName($actorId);
                $this->conversationManager->appendSystemMessage(
                    $lockedConversation,
                    $actorName !== null
                        ? "Percakapan ditandai escalated oleh {$actorName}."
                        : 'Percakapan ditandai escalated oleh admin.',
                    [
                        'system_event' => 'conversation_marked_escalated',
                        'actor_user_id' => $actorId,
                        'reason' => $reason,
                        'escalation_id' => $escalation->id,
                    ],
                );

                $this->audit->record(AuditActionType::ConversationMarkedEscalated, [
                    'actor_user_id' => $actorId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => 'Percakapan ditandai escalated oleh admin.',
                    'context' => [
                        'reason' => $reason,
                        'escalation_id' => $escalation->id,
                        'priority' => $escalation->priority,
                    ],
                ]);
            });

            return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']) ?? $lockedConversation;
        });
    }

    public function setUrgency(Conversation $conversation, int $actorId, bool $urgent): Conversation
    {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($actorId, $urgent): Conversation {
            if ((bool) $lockedConversation->is_urgent === $urgent) {
                return $lockedConversation->fresh() ?? $lockedConversation;
            }

            DB::transaction(function () use ($lockedConversation, $actorId, $urgent): void {
                $lockedConversation->update([
                    'is_urgent' => $urgent,
                    'urgent_marked_at' => $urgent ? now() : null,
                    'urgent_marked_by' => $urgent ? $actorId : null,
                    'last_admin_intervention_at' => now(),
                ]);

                if ($urgent) {
                    Escalation::query()
                        ->where('conversation_id', $lockedConversation->id)
                        ->whereIn('status', ['open', 'assigned'])
                        ->update(['priority' => 'urgent']);
                }

                $actorName = $this->actorName($actorId);
                $text = $urgent
                    ? ($actorName !== null ? "Percakapan ditandai urgent oleh {$actorName}." : 'Percakapan ditandai urgent oleh admin.')
                    : ($actorName !== null ? "Tanda urgent dibersihkan oleh {$actorName}." : 'Tanda urgent dibersihkan oleh admin.');

                $this->conversationManager->appendSystemMessage(
                    $lockedConversation,
                    $text,
                    [
                        'system_event' => $urgent ? 'conversation_marked_urgent' : 'conversation_urgency_cleared',
                        'actor_user_id' => $actorId,
                    ],
                );

                $this->audit->record($urgent ? AuditActionType::ConversationMarkedUrgent : AuditActionType::ConversationUrgencyCleared, [
                    'actor_user_id' => $actorId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => $urgent ? 'Percakapan ditandai urgent.' : 'Tanda urgent pada percakapan dibersihkan.',
                    'context' => [
                        'is_urgent' => $urgent,
                    ],
                ]);
            });

            return $lockedConversation->fresh(['urgentMarkedByUser']) ?? $lockedConversation;
        });
    }

    public function close(Conversation $conversation, int $actorId, ?string $reason = null): Conversation
    {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($actorId, $reason): Conversation {
            if ($lockedConversation->status === ConversationStatus::Closed) {
                return $lockedConversation->fresh() ?? $lockedConversation;
            }

            DB::transaction(function () use ($lockedConversation, $actorId, $reason): void {
                $lockedConversation->update([
                    'status' => ConversationStatus::Closed,
                    'needs_human' => false,
                    'closed_at' => now(),
                    'closed_by' => $actorId,
                    'close_reason' => $reason ?: 'closed_from_console',
                    'last_admin_intervention_at' => now(),
                    'bot_paused' => false,
                    'bot_paused_reason' => null,
                    'handoff_mode' => 'bot',
                    'handoff_admin_id' => null,
                    'assigned_admin_id' => null,
                ]);

                $actorName = $this->actorName($actorId);
                $this->conversationManager->appendSystemMessage(
                    $lockedConversation,
                    $actorName !== null
                        ? "Percakapan ditutup oleh {$actorName}."
                        : 'Percakapan ditutup oleh admin.',
                    [
                        'system_event' => 'conversation_closed',
                        'actor_user_id' => $actorId,
                        'reason' => $reason,
                    ],
                );

                $this->audit->record(AuditActionType::ConversationClosed, [
                    'actor_user_id' => $actorId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => 'Percakapan ditutup dari admin console.',
                    'context' => [
                        'reason' => $reason,
                    ],
                ]);
            });

            return $lockedConversation->fresh(['closedByUser']) ?? $lockedConversation;
        });
    }

    public function reopen(Conversation $conversation, int $actorId): Conversation
    {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($actorId): Conversation {
            if ($lockedConversation->status === ConversationStatus::Active) {
                return $lockedConversation->fresh() ?? $lockedConversation;
            }

            DB::transaction(function () use ($lockedConversation, $actorId): void {
                $lockedConversation->update([
                    'status' => ConversationStatus::Active,
                    'needs_human' => false,
                    'reopened_at' => now(),
                    'reopened_by' => $actorId,
                    'last_admin_intervention_at' => now(),
                    'bot_paused' => false,
                    'bot_paused_reason' => null,
                    'handoff_mode' => 'bot',
                    'handoff_admin_id' => null,
                    'assigned_admin_id' => null,
                ]);

                $actorName = $this->actorName($actorId);
                $this->conversationManager->appendSystemMessage(
                    $lockedConversation,
                    $actorName !== null
                        ? "Percakapan dibuka kembali oleh {$actorName}."
                        : 'Percakapan dibuka kembali oleh admin.',
                    [
                        'system_event' => 'conversation_reopened',
                        'actor_user_id' => $actorId,
                    ],
                );

                $this->audit->record(AuditActionType::ConversationReopened, [
                    'actor_user_id' => $actorId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => 'Percakapan dibuka kembali dari admin console.',
                ]);
            });

            return $lockedConversation->fresh(['reopenedByUser']) ?? $lockedConversation;
        });
    }

    private function actorName(int $actorId): ?string
    {
        return User::query()->whereKey($actorId)->value('name');
    }

    private function withLock(Conversation $conversation, callable $callback): Conversation
    {
        $lock = Cache::lock('chatbot:conversation-status:'.$conversation->id, 15);

        if (! $lock->get()) {
            return $conversation->fresh() ?? $conversation;
        }

        try {
            $freshConversation = Conversation::query()->findOrFail($conversation->id);

            return $callback($freshConversation);
        } finally {
            rescue(static function () use ($lock): void {
                $lock->release();
            }, report: false);
        }
    }
}
