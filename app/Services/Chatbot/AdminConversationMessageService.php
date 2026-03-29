<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AI\Learning\AdminCorrectionLoggerService;
use App\Services\Support\AuditLogService;
use App\Support\WaLog;
use Illuminate\Support\Facades\Cache;

class AdminConversationMessageService
{
    public function __construct(
        private readonly ConversationTakeoverService $takeoverService,
        private readonly ConversationManagerService $conversationManager,
        private readonly ConversationOutboundRouterService $outboundRouter,
        private readonly AdminCorrectionLoggerService $adminCorrectionLogger,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{
     *     status: 'queued'|'duplicate'|'failed',
     *     message: ConversationMessage,
     *     duplicate: bool,
     *     dispatch_status: string|null,
     *     transport: string|null,
     *     error: string|null
     * }
     */
    public function send(
        Conversation $conversation,
        string $text,
        int $adminId,
        string $source = 'conversation_detail',
    ): array {
        $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $fingerprint = hash('sha256', implode('|', [
            (string) $conversation->id,
            (string) $adminId,
            mb_strtolower($normalizedText, 'UTF-8'),
        ]));

        $lock = Cache::lock('chatbot:admin-message:'.$conversation->id.':'.$adminId, 10);

        if (! $lock->get()) {
            $existing = $this->findRecentDuplicate($conversation, $fingerprint);

            if ($existing !== null) {
                return [
                    'status' => 'duplicate',
                    'message' => $existing,
                    'duplicate' => true,
                    'dispatch_status' => null,
                    'transport' => $conversation->channel,
                    'error' => null,
                ];
            }

            return [
                'status' => 'failed',
                'message' => new ConversationMessage(),
                'duplicate' => false,
                'dispatch_status' => null,
                'transport' => $conversation->channel,
                'error' => 'Permintaan kirim sedang diproses. Silakan tunggu sebentar.',
            ];
        }

        try {
            $conversation = $conversation->fresh(['assignedAdmin']) ?? $conversation;
            $existing = $this->findRecentDuplicate($conversation, $fingerprint);

            if ($existing !== null) {
                return [
                    'status' => 'duplicate',
                    'message' => $existing,
                    'duplicate' => true,
                    'dispatch_status' => null,
                    'transport' => $conversation->channel,
                    'error' => null,
                ];
            }

            if (! $conversation->isAdminTakeover() || (int) ($conversation->assigned_admin_id ?? 0) !== $adminId) {
                $conversation = $this->takeoverService->takeOver(
                    conversation: $conversation,
                    adminId: $adminId,
                    reason: 'admin_manual_reply',
                );
            }

            $adminName = User::query()->whereKey($adminId)->value('name');
            $message = $this->conversationManager->appendAdminOutboundMessage(
                conversation: $conversation,
                text: $normalizedText,
                adminId: $adminId,
                rawPayload: [
                    'source' => $source,
                    'admin_name' => $adminName,
                    'admin_message_fingerprint' => $fingerprint,
                    'sender_role' => 'admin',
                ],
            );

            $this->adminCorrectionLogger->captureForAdminReply(
                conversation: $conversation,
                adminMessage: $message,
                adminId: $adminId,
            );

            try {
                $this->outboundRouter->dispatch($message, WaLog::traceId());
            } catch (\Throwable $e) {
                $message->markFailed('dispatch_failed: '.$e->getMessage());

                $this->audit->record(AuditActionType::WhatsAppSendFailure, [
                    'actor_user_id' => $adminId,
                    'auditable_type' => ConversationMessage::class,
                    'auditable_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'message' => 'Dispatch job kirim pesan admin gagal.',
                    'context' => [
                        'message_id' => $message->id,
                        'source' => $source,
                        'admin_id' => $adminId,
                        'transport' => $conversation->channel,
                        'error' => $e->getMessage(),
                    ],
                ]);

                return [
                    'status' => 'failed',
                    'message' => $message->fresh(),
                    'duplicate' => false,
                    'dispatch_status' => 'failed',
                    'transport' => $conversation->channel,
                    'error' => 'Pesan tersimpan, tetapi gagal masuk jalur pengiriman.',
                ];
            }

            $this->audit->record(AuditActionType::AdminReplySent, [
                'actor_user_id' => $adminId,
                'conversation_id' => $conversation->id,
                'auditable_type' => Conversation::class,
                'auditable_id' => $conversation->id,
                'message' => 'Admin mengirim balasan manual ke customer.',
                'context' => [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'admin_id' => $adminId,
                    'source' => $source,
                    'text_preview' => mb_substr($normalizedText, 0, 120),
                    'message_fingerprint' => $fingerprint,
                    'transport' => $conversation->channel,
                    'dispatch_status' => $message->delivery_status?->value,
                    'delivery_status' => $message->delivery_status?->value,
                ],
            ]);

            return [
                'status' => 'queued',
                'message' => $message->fresh(),
                'duplicate' => false,
                'dispatch_status' => $message->fresh()?->delivery_status?->value,
                'transport' => $conversation->channel,
                'error' => null,
            ];
        } finally {
            rescue(static function () use ($lock): void {
                $lock->release();
            }, report: false);
        }
    }

    private function findRecentDuplicate(Conversation $conversation, string $fingerprint): ?ConversationMessage
    {
        return $conversation->messages()
            ->where('direction', 'outbound')
            ->whereIn('sender_type', ['admin', 'agent'])
            ->where('sent_at', '>=', now()->subSeconds(12))
            ->latest('id')
            ->get()
            ->first(function (ConversationMessage $message) use ($fingerprint): bool {
                return (string) ($message->raw_payload['admin_message_fingerprint'] ?? '') === $fingerprint;
            });
    }
}
