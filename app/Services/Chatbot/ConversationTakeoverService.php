<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Enums\ConversationStatus;
use App\Models\AdminNotification;
use App\Models\Conversation;
use App\Models\ConversationHandoff;
use App\Models\User;
use App\Services\Support\AuditLogService;
use App\Support\WaLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationTakeoverService
{
    public function __construct(
        private readonly ConversationManagerService $conversationManager,
        private readonly AuditLogService $audit,
    ) {}

    public function takeOver(
        Conversation $conversation,
        ?int $adminId,
        ?string $reason = null,
    ): Conversation {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($adminId, $reason): Conversation {
            $before = $this->snapshot($lockedConversation);
            $alreadyOwnedBySameAdmin = $lockedConversation->isAdminTakeover()
                && (int) ($lockedConversation->assigned_admin_id ?? 0) === (int) ($adminId ?? 0)
                && $lockedConversation->isBotPaused();

            if ($alreadyOwnedBySameAdmin) {
                return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
            }

            DB::transaction(function () use ($lockedConversation, $adminId, $reason, $before): void {
                $lockedConversation->takeoverBy($adminId, $reason);
                $lockedConversation->refresh();

                $actorName = $this->resolveAdminName($adminId);
                $systemText = $actorName !== null
                    ? "Percakapan diambil alih oleh {$actorName}. Bot dihentikan sementara."
                    : 'Percakapan diambil alih oleh admin. Bot dihentikan sementara.';

                $this->conversationManager->appendSystemMessage(
                    conversation: $lockedConversation,
                    text: $systemText,
                    rawPayload: [
                        'system_event' => 'conversation_takeover',
                        'admin_id' => $adminId,
                        'reason' => $reason,
                    ],
                );

                $this->recordHandoff(
                    conversation: $lockedConversation,
                    action: 'takeover',
                    actorUserId: $adminId,
                    assignedAdminId: $adminId,
                    reason: $reason,
                    before: $before,
                    after: $this->snapshot($lockedConversation),
                );

                $this->audit->record(AuditActionType::ConversationTakeover, [
                    'actor_user_id' => $adminId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => "Admin (ID {$adminId}) mengambil alih percakapan. Bot dihentikan sementara.",
                    'context' => [
                        'conversation_id' => $lockedConversation->id,
                        'admin_id' => $adminId,
                        'reason' => $reason,
                        'snapshot_before' => $before,
                        'snapshot_after' => $this->snapshot($lockedConversation),
                    ],
                ]);

                if (config('chatbot.notifications.enabled', true) && config('chatbot.notifications.create_on_takeover', true)) {
                    AdminNotification::create([
                        'type' => 'takeover',
                        'title' => 'Admin Takeover: Percakapan #'.$lockedConversation->id,
                        'body' => "Admin (ID {$adminId}) mengambil alih percakapan. Bot dinonaktifkan.",
                        'payload' => [
                            'conversation_id' => $lockedConversation->id,
                            'admin_id' => $adminId,
                            'reason' => $reason,
                        ],
                        'is_read' => false,
                    ]);
                }
            });

            WaLog::info('[ConversationTakeover] manual takeover applied', [
                'conversation_id' => $lockedConversation->id,
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
        });
    }

    public function releaseToBot(
        Conversation $conversation,
        ?int $adminId,
        ?string $reason = null,
    ): Conversation {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($adminId, $reason): Conversation {
            $before = $this->snapshot($lockedConversation);
            $alreadyReleased = ! $lockedConversation->isAutomationSuppressed()
                && ($lockedConversation->handoff_mode === null || $lockedConversation->handoff_mode === 'bot');

            if ($alreadyReleased) {
                return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
            }

            DB::transaction(function () use ($lockedConversation, $adminId, $reason, $before): void {
                $lockedConversation->releaseToBot($adminId);
                $lockedConversation->refresh();

                $actorName = $this->resolveAdminName($adminId);
                $systemText = $actorName !== null
                    ? "Percakapan dilepas kembali ke bot oleh {$actorName}. Balasan otomatis aktif lagi."
                    : 'Percakapan dilepas kembali ke bot. Balasan otomatis aktif lagi.';

                $this->conversationManager->appendSystemMessage(
                    conversation: $lockedConversation,
                    text: $systemText,
                    rawPayload: [
                        'system_event' => 'conversation_release',
                        'admin_id' => $adminId,
                        'reason' => $reason,
                    ],
                );

                $this->recordHandoff(
                    conversation: $lockedConversation,
                    action: 'release',
                    actorUserId: $adminId,
                    assignedAdminId: null,
                    reason: $reason,
                    before: $before,
                    after: $this->snapshot($lockedConversation),
                );

                $this->audit->record(AuditActionType::ConversationRelease, [
                    'actor_user_id' => $adminId,
                    'conversation_id' => $lockedConversation->id,
                    'auditable_type' => Conversation::class,
                    'auditable_id' => $lockedConversation->id,
                    'message' => "Admin (ID {$adminId}) melepas percakapan kembali ke bot.",
                    'context' => [
                        'conversation_id' => $lockedConversation->id,
                        'admin_id' => $adminId,
                        'reason' => $reason,
                        'snapshot_before' => $before,
                        'snapshot_after' => $this->snapshot($lockedConversation),
                    ],
                ]);

                if (config('chatbot.notifications.enabled', true) && config('chatbot.notifications.create_on_release', false)) {
                    AdminNotification::create([
                        'type' => 'release',
                        'title' => 'Bot Diaktifkan Kembali: Percakapan #'.$lockedConversation->id,
                        'body' => "Admin (ID {$adminId}) melepas percakapan. Bot aktif kembali.",
                        'payload' => [
                            'conversation_id' => $lockedConversation->id,
                            'admin_id' => $adminId,
                            'reason' => $reason,
                        ],
                        'is_read' => false,
                    ]);
                }
            });

            WaLog::info('[ConversationTakeover] released back to bot', [
                'conversation_id' => $lockedConversation->id,
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
        });
    }

    public function pauseForEscalation(
        Conversation $conversation,
        ?string $reason = null,
    ): Conversation {
        return $this->withLock($conversation, function (Conversation $lockedConversation) use ($reason): Conversation {
            $before = $this->snapshot($lockedConversation);
            $alreadyPausedForHuman = $lockedConversation->isBotPaused()
                && ! $lockedConversation->isAdminTakeover()
                && $lockedConversation->needs_human;

            if ($alreadyPausedForHuman) {
                return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
            }

            DB::transaction(function () use ($lockedConversation, $reason, $before): void {
                $lockedConversation->update([
                    'handoff_mode' => 'bot',
                    'handoff_admin_id' => null,
                    'handoff_at' => now(),
                    'bot_paused' => true,
                    'bot_paused_reason' => $reason ?: 'escalated',
                    'assigned_admin_id' => null,
                    'needs_human' => true,
                    'status' => $lockedConversation->isTerminal() ? $lockedConversation->status : ConversationStatus::Escalated,
                ]);

                $lockedConversation->refresh();

                $this->recordHandoff(
                    conversation: $lockedConversation,
                    action: 'pause_for_escalation',
                    actorUserId: null,
                    assignedAdminId: null,
                    reason: $reason,
                    before: $before,
                    after: $this->snapshot($lockedConversation),
                );
            });

            WaLog::info('[ConversationTakeover] bot paused for escalation', [
                'conversation_id' => $lockedConversation->id,
                'reason' => $reason,
            ]);

            return $lockedConversation->fresh(['assignedAdmin', 'handoffAdmin']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Conversation $conversation): array
    {
        return [
            'status' => is_string($conversation->status) ? $conversation->status : $conversation->status?->value,
            'needs_human' => (bool) $conversation->needs_human,
            'handoff_mode' => $conversation->handoff_mode,
            'handoff_admin_id' => $conversation->handoff_admin_id,
            'assigned_admin_id' => $conversation->assigned_admin_id,
            'bot_paused' => (bool) $conversation->bot_paused,
            'bot_paused_reason' => $conversation->bot_paused_reason,
            'last_message_at' => $conversation->last_message_at?->toDateTimeString(),
            'states' => $conversation->states()
                ->active()
                ->get(['state_key', 'state_value'])
                ->map(fn ($state): array => [
                    'key' => $state->state_key,
                    'value' => $state->state_value,
                ])
                ->all(),
        ];
    }

    private function recordHandoff(
        Conversation $conversation,
        string $action,
        ?int $actorUserId,
        ?int $assignedAdminId,
        ?string $reason,
        array $before,
        array $after,
    ): void {
        ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'actor_user_id' => $actorUserId,
            'assigned_admin_id' => $assignedAdminId,
            'action' => $action,
            'from_mode' => (string) ($before['handoff_mode'] ?? 'bot'),
            'to_mode' => (string) ($after['handoff_mode'] ?? 'bot'),
            'reason' => $reason,
            'snapshot' => [
                'before' => $before,
                'after' => $after,
            ],
            'happened_at' => now(),
        ]);
    }

    private function resolveAdminName(?int $adminId): ?string
    {
        if ($adminId === null) {
            return null;
        }

        return User::query()
            ->whereKey($adminId)
            ->value('name');
    }

    private function withLock(Conversation $conversation, callable $callback): Conversation
    {
        $lock = Cache::lock('chatbot:takeover:conversation:'.$conversation->id, 15);

        if (! $lock->get()) {
            WaLog::warning('[ConversationTakeover] lock busy', [
                'conversation_id' => $conversation->id,
            ]);

            return $conversation->fresh(['assignedAdmin', 'handoffAdmin']) ?? $conversation;
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
