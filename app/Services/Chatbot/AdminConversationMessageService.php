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
        private readonly BotAutomationToggleService $botToggleService,
        private readonly ConversationManagerService $conversationManager,
        private readonly ConversationOutboundRouterService $outboundRouter,
        private readonly AdminCorrectionLoggerService $adminCorrectionLogger,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $outboundPayload
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
        string $messageType = 'text',
        array $outboundPayload = [],
    ): array {
        $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $dedupeSeed = match ($messageType) {
            'audio' => (string) data_get($outboundPayload, 'audio.link', ''),
            'image' => (string) data_get($outboundPayload, 'image.link', ''),
            default => mb_strtolower($normalizedText, 'UTF-8'),
        };

        $fingerprint = hash('sha256', implode('|', [
            (string) $conversation->id,
            (string) $adminId,
            $messageType,
            $dedupeSeed,
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

            $conversation = $this->botToggleService->registerAdminReply(
                conversation: $conversation,
                adminId: $adminId,
                autoResumeMinutes: $this->botToggleService->autoResumeMinutes(),
            );

            $adminName = User::query()->whereKey($adminId)->value('name');
            $message = $this->conversationManager->appendAdminOutboundMessage(
                conversation: $conversation,
                text: $normalizedText,
                adminId: $adminId,
                messageType: $messageType,
                rawPayload: array_merge([
                    'source' => $source,
                    'admin_name' => $adminName,
                    'admin_message_fingerprint' => $fingerprint,
                    'sender_role' => 'admin',
                ], $messageType === 'audio' ? [
                    'outbound_payload' => [
                        'audio' => [
                            'link' => (string) data_get($outboundPayload, 'audio.link', ''),
                            'voice' => (bool) data_get($outboundPayload, 'audio.voice', true),
                        ],
                    ],
                    'media_caption' => data_get($outboundPayload, 'caption'),
                    'mime_type' => data_get($outboundPayload, 'mime_type'),
                ] : ($messageType === 'image' ? [
                    'outbound_payload' => [
                        'image' => [
                            'link' => (string) data_get($outboundPayload, 'image.link', ''),
                        ],
                    ],
                    'media_caption' => data_get($outboundPayload, 'caption'),
                    'mime_type' => data_get($outboundPayload, 'mime_type'),
                    'media_original_name' => data_get($outboundPayload, 'original_name'),
                    'media_size_bytes' => data_get($outboundPayload, 'size_bytes'),
                    'media_storage_disk' => data_get($outboundPayload, 'storage_disk'),
                    'media_storage_path' => data_get($outboundPayload, 'storage_path'),
                ] : [])),
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
                        'message_type' => $messageType,
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
                    'message_type' => $messageType,
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
