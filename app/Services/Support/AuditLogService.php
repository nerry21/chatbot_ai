<?php

namespace App\Services\Support;

use App\Enums\AuditActionType;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * Record an audit event.
     *
     * Returns the created AuditLog, or null if audit is disabled or creation fails.
     *
     * @param  AuditActionType|string  $actionType
     * @param  array<string, mixed>    $data  Optional context:
     *   - actor_user_id   (int|null)  — defaults to auth()->id()
     *   - auditable_type  (string)    — e.g. ConversationMessage::class
     *   - auditable_id    (int)
     *   - conversation_id (int)
     *   - message         (string)    — human-readable description
     *   - context         (array)     — arbitrary key→value data
     *   - ip_address      (string)    — defaults to current request IP
     *   - user_agent      (string)    — defaults to current request UA
     */
    public function record(
        AuditActionType|string $actionType,
        array $data = [],
    ): ?AuditLog {
        if (! config('chatbot.audit.enabled', true)) {
            return null;
        }

        try {
            $action = $actionType instanceof AuditActionType
                ? $actionType->value
                : $actionType;

            /** @var Request|null $request */
            $request = app()->bound('request') ? app('request') : null;

            return AuditLog::create([
                'actor_user_id'   => $data['actor_user_id']   ?? (auth()->check() ? auth()->id() : null),
                'action_type'     => $action,
                'auditable_type'  => $data['auditable_type']  ?? null,
                'auditable_id'    => $data['auditable_id']    ?? null,
                'conversation_id' => $data['conversation_id'] ?? null,
                'message'         => $data['message']         ?? null,
                'context'         => ! empty($data['context']) ? $data['context'] : null,
                'ip_address'      => $data['ip_address']      ?? $request?->ip(),
                'user_agent'      => $data['user_agent']      ?? $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AuditLog] Failed to record audit entry', [
                'action' => $actionType instanceof AuditActionType ? $actionType->value : $actionType,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Audit khusus untuk keputusan orchestration AI end-to-end.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function recordAiOrchestration(
        int $conversationId,
        string $message,
        array $snapshot = [],
        ?int $actorUserId = null,
    ): ?AuditLog {
        return $this->record(AuditActionType::BotReplyGenerated, [
            'actor_user_id' => $actorUserId,
            'conversation_id' => $conversationId,
            'message' => $message,
            'context' => [
                'ai_orchestration' => true,
                'snapshot' => $snapshot,
            ],
        ]);
    }
}
