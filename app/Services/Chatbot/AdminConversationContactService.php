<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Support\AuditLogService;
use App\Support\WaLog;
use Illuminate\Support\Facades\Cache;

class AdminConversationContactService
{
    public function __construct(
        private readonly ConversationTakeoverService $takeoverService,
        private readonly ConversationOutboundRouterService $outboundRouter,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param array{
     *   full_name:string,
     *   phone:string,
     *   email?:string|null,
     *   company?:string|null
     * } $contact
     *
     * @return array{
     *   status:'queued'|'duplicate'|'failed',
     *   message: ConversationMessage,
     *   duplicate: bool,
     *   dispatch_status: string|null,
     *   transport: string|null,
     *   error: string|null
     * }
     */
    public function send(
        Conversation $conversation,
        array $contact,
        int $adminId,
        string $source = 'admin_mobile_contact',
    ): array {
        $fullName = trim((string) ($contact['full_name'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));
        $company = trim((string) ($contact['company'] ?? ''));

        if ($fullName === '' || $phone === '') {
            return [
                'status' => 'failed',
                'message' => new ConversationMessage(),
                'duplicate' => false,
                'dispatch_status' => null,
                'transport' => $conversation->channel,
                'error' => 'Nama dan nomor kontak wajib diisi.',
            ];
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) $conversation->id,
            (string) $adminId,
            mb_strtolower($fullName, 'UTF-8'),
            preg_replace('/\D+/', '', $phone) ?? $phone,
            mb_strtolower($email, 'UTF-8'),
            mb_strtolower($company, 'UTF-8'),
            'contacts',
        ]));

        $lock = Cache::lock('chatbot:admin-contact:'.$conversation->id.':'.$adminId, 10);

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
                'error' => 'Permintaan kirim kontak sedang diproses. Silakan tunggu sebentar.',
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
                    reason: 'admin_send_contact',
                );
            }

            $adminName = User::query()->whereKey($adminId)->value('name');

            [$firstName, $lastName] = $this->splitName($fullName);

            $contactsPayload = [
                [
                    'name' => [
                        'formatted_name' => $fullName,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ],
                    'phones' => [
                        [
                            'phone' => $phone,
                            'type' => 'CELL',
                            'wa_id' => preg_replace('/\D+/', '', $phone),
                        ],
                    ],
                ],
            ];

            if ($email !== '') {
                $contactsPayload[0]['emails'] = [
                    [
                        'email' => $email,
                        'type' => 'WORK',
                    ],
                ];
            }

            if ($company !== '') {
                $contactsPayload[0]['org'] = [
                    'company' => $company,
                ];
            }

            $summary = 'Kontak dibagikan: '.$fullName.' ('.$phone.')'
                .($email !== '' ? ' | '.$email : '')
                .($company !== '' ? ' | '.$company : '');

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'sender_type' => SenderType::Admin,
                'message_type' => 'contacts',
                'message_text' => $summary,
                'raw_payload' => [
                    'source' => $source,
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'sender_role' => 'admin',
                    'admin_message_fingerprint' => $fingerprint,
                    'outbound_payload' => [
                        'contacts' => $contactsPayload,
                    ],
                    'contact_summary' => [
                        'full_name' => $fullName,
                        'phone' => $phone,
                        'email' => $email !== '' ? $email : null,
                        'company' => $company !== '' ? $company : null,
                    ],
                ],
                'sender_user_id' => $adminId,
                'sent_at' => now(),
                'delivery_status' => MessageDeliveryStatus::Pending,
            ]);

            $conversation->touchLastMessage();

            try {
                $this->outboundRouter->dispatch($message, WaLog::traceId());
            } catch (\Throwable $e) {
                $message->markFailed('dispatch_failed: '.$e->getMessage());

                $this->audit->record(AuditActionType::WhatsAppSendFailure, [
                    'actor_user_id' => $adminId,
                    'auditable_type' => ConversationMessage::class,
                    'auditable_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'message' => 'Dispatch job kirim kontak admin gagal.',
                    'context' => [
                        'message_id' => $message->id,
                        'source' => $source,
                        'admin_id' => $adminId,
                        'transport' => $conversation->channel,
                        'error' => $e->getMessage(),
                        'contact_name' => $fullName,
                        'contact_phone' => $phone,
                    ],
                ]);

                return [
                    'status' => 'failed',
                    'message' => $message->fresh(),
                    'duplicate' => false,
                    'dispatch_status' => 'failed',
                    'transport' => $conversation->channel,
                    'error' => 'Kontak tersimpan, tetapi gagal masuk jalur pengiriman.',
                ];
            }

            $this->audit->record(AuditActionType::AdminReplySent, [
                'actor_user_id' => $adminId,
                'conversation_id' => $conversation->id,
                'auditable_type' => Conversation::class,
                'auditable_id' => $conversation->id,
                'message' => 'Admin mengirim kontak ke customer.',
                'context' => [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'admin_id' => $adminId,
                    'source' => $source,
                    'message_kind' => 'contacts',
                    'contact_name' => $fullName,
                    'contact_phone' => $phone,
                    'delivery_status' => $message->delivery_status?->value,
                    'transport' => $conversation->channel,
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
            ->where('sent_at', '>=', now()->subSeconds(20))
            ->latest('id')
            ->get()
            ->first(function (ConversationMessage $message) use ($fingerprint): bool {
                return (string) ($message->raw_payload['admin_message_fingerprint'] ?? '') === $fingerprint;
            });
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));

        if ($parts === []) {
            return ['Kontak', ''];
        }

        if (count($parts) === 1) {
            return [$parts[0], 'Kontak'];
        }

        $first = array_shift($parts) ?: 'Kontak';
        $last = trim(implode(' ', $parts));

        return [$first, $last !== '' ? $last : 'Kontak'];
    }
}
