<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotConversationState;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TravelChatbotOrchestratorService
{
    public function __construct(
        protected TravelConversationStateService $stateService,
        protected TravelMessageRouterService $routerService,
    ) {
    }

    /**
     * Payload minimum:
     * [
     *   'text' => 'Saya mau booking',
     *   'customer_phone' => '62812xxxx',
     *   'customer_name' => 'Budi', // opsional
     *   'channel' => 'whatsapp',   // opsional
     *   'message_id' => 'wamid.xxx', // opsional
     *   'now' => now('Asia/Jakarta') // opsional
     * ]
     *
     * Handler opsional:
     * [
     *   'send_reply' => callable(string $phone, string $message, array $context): mixed,
     *   'notify_admin' => callable(string $adminPhone, string $message, array $context): mixed,
     * ]
     */
    public function handleIncoming(array $payload, array $handlers = []): array
    {
        $text         = trim((string) ($payload['text'] ?? ''));
        $customerPhone = trim((string) ($payload['customer_phone'] ?? ''));
        $customerName = $this->nullableString($payload['customer_name'] ?? null);
        $channel      = trim((string) ($payload['channel'] ?? 'whatsapp'));
        $messageId    = $this->nullableString($payload['message_id'] ?? null);
        $now          = $this->resolveNow($payload['now'] ?? null);

        if ($customerPhone === '') {
            throw new \InvalidArgumentException('customer_phone wajib diisi.');
        }

        if ($text === '') {
            throw new \InvalidArgumentException('text wajib diisi.');
        }

        $conversation = $this->stateService->findOrCreate($customerPhone, $channel, $customerName);

        $conversation = $this->stateService->markIncomingMessage(
            $conversation,
            $text,
            $now
        );

        $routerResult = $this->routerService->route([
            'text'  => $text,
            'phone' => $customerPhone,
            'state' => $this->stateService->toRouterState($conversation),
            'now'   => $now,
        ]);

        $conversation = $this->stateService->applyRouterResult(
            $conversation,
            $routerResult,
            $now
        );

        $replyText = (string) ($routerResult['reply_text'] ?? '');
        $intent    = (string) ($routerResult['intent'] ?? '');
        $actions   = (array) ($routerResult['actions'] ?? []);
        $meta      = (array) ($routerResult['meta'] ?? []);

        $replyResult  = null;
        $adminResults = [];

        if ($replyText !== '') {
            $replyResult = $this->sendReply(
                customerPhone: $customerPhone,
                replyText: $replyText,
                conversation: $conversation,
                handlers: $handlers,
                context: [
                    'intent'     => $intent,
                    'message_id' => $messageId,
                    'channel'    => $channel,
                    'meta'       => $meta,
                ]
            );

            $conversation = $this->stateService->markBotSent(
                $conversation,
                $replyText,
                $now
            );
        }

        foreach ($actions as $action) {
            if (($action['type'] ?? null) !== 'notify_admin') {
                continue;
            }

            $adminResult = $this->handleAdminNotificationAction(
                action: $action,
                incomingPayload: $payload,
                conversation: $conversation,
                handlers: $handlers,
                now: $now
            );

            if ($adminResult !== null) {
                $adminResults[] = $adminResult;
            }
        }

        return [
            'ok'                   => true,
            'intent'               => $intent,
            'reply_text'           => $replyText,
            'reply_result'         => $replyResult,
            'admin_results'        => $adminResults,
            'conversation_state_id' => $conversation->id,
            'conversation'         => $conversation->fresh(),
            'router_result'        => $routerResult,
        ];
    }

    /**
     * Dipakai untuk cron/scheduler follow-up percakapan booking yang berhenti.
     */
    public function processPendingFollowUps(array $handlers = [], ?Carbon $now = null): array
    {
        $now ??= now(config('chatbot.jet.timezone', 'Asia/Jakarta'));

        $states = ChatbotConversationState::query()
            ->where('channel', 'whatsapp')
            ->where('is_active', true)
            ->where('is_waiting_customer_reply', true)
            ->get();

        $processed = [];

        foreach ($states as $state) {
            if ($this->stateService->shouldSendFirstFollowUp($state, $now)) {
                $message = $this->buildFollowUpMessage($state);

                $replyResult = $this->sendReply(
                    customerPhone: $state->customer_phone,
                    replyText: $message,
                    conversation: $state,
                    handlers: $handlers,
                    context: [
                        'intent'  => 'booking_follow_up',
                        'channel' => $state->channel,
                        'meta'    => ['follow_up_stage' => 1],
                    ]
                );

                $state = $this->stateService->markFirstFollowUpSent($state, $now);
                $state = $this->stateService->markBotSent($state, $message, $now);

                $processed[] = [
                    'type'                  => 'first_follow_up',
                    'conversation_state_id' => $state->id,
                    'customer_phone'        => $state->customer_phone,
                    'reply_result'          => $replyResult,
                ];

                continue;
            }

            if ($this->stateService->shouldCancelAfterSecondTimeout($state, $now)) {
                $state = $this->stateService->markSecondFollowUpAndCancel($state, $now);

                $processed[] = [
                    'type'                  => 'cancel_after_second_timeout',
                    'conversation_state_id' => $state->id,
                    'customer_phone'        => $state->customer_phone,
                ];
            }
        }

        return [
            'ok'        => true,
            'processed' => $processed,
            'count'     => count($processed),
        ];
    }

    protected function handleAdminNotificationAction(
        array $action,
        array $incomingPayload,
        ChatbotConversationState $conversation,
        array $handlers,
        Carbon $now
    ): ?array {
        $message = trim((string) ($action['message'] ?? ''));

        if ($message === '') {
            return null;
        }

        $notificationKey = $this->buildAdminNotificationKey($action, $message, $conversation);

        if ($this->stateService->shouldAvoidDuplicateAdminNotification($conversation, $notificationKey)) {
            return [
                'skipped'          => true,
                'reason'           => 'duplicate_admin_notification',
                'notification_key' => $notificationKey,
            ];
        }

        $adminPhone = (string) config('chatbot.jet.admin_phone', '');

        $result = $this->notifyAdmin(
            adminPhone: $adminPhone,
            message: $message,
            conversation: $conversation,
            handlers: $handlers,
            context: [
                'intent'  => $incomingPayload['intent'] ?? null,
                'action'  => $action,
                'channel' => $incomingPayload['channel'] ?? 'whatsapp',
            ]
        );

        $this->stateService->markAdminNotified($conversation, $notificationKey, $now);

        return [
            'skipped'          => false,
            'notification_key' => $notificationKey,
            'admin_phone'      => $adminPhone,
            'result'           => $result,
        ];
    }

    protected function sendReply(
        string $customerPhone,
        string $replyText,
        ChatbotConversationState $conversation,
        array $handlers,
        array $context = []
    ): mixed {
        if (isset($handlers['send_reply']) && is_callable($handlers['send_reply'])) {
            return $handlers['send_reply']($customerPhone, $replyText, array_merge($context, [
                'conversation' => $conversation,
            ]));
        }

        Log::info('[TravelChatbotOrchestrator] Reply prepared', [
            'customer_phone'        => $customerPhone,
            'reply_text'            => $replyText,
            'conversation_state_id' => $conversation->id,
            'context'               => $context,
        ]);

        return [
            'mocked'  => true,
            'target'  => $customerPhone,
            'message' => $replyText,
        ];
    }

    protected function notifyAdmin(
        string $adminPhone,
        string $message,
        ChatbotConversationState $conversation,
        array $handlers,
        array $context = []
    ): mixed {
        if (isset($handlers['notify_admin']) && is_callable($handlers['notify_admin'])) {
            return $handlers['notify_admin']($adminPhone, $message, array_merge($context, [
                'conversation' => $conversation,
            ]));
        }

        Log::info('[TravelChatbotOrchestrator] Admin notification prepared', [
            'admin_phone'           => $adminPhone,
            'message'               => $message,
            'conversation_state_id' => $conversation->id,
            'context'               => $context,
        ]);

        return [
            'mocked'  => true,
            'target'  => $adminPhone,
            'message' => $message,
        ];
    }

    protected function buildFollowUpMessage(ChatbotConversationState $state): string
    {
        $lastStep = $this->humanizeStep($state->current_step);

        $template = (string) config(
            'chatbot.jet.follow_up_message',
            'Halo, apakah Anda masih ingin melanjutkan proses ... Kami siap membantu.'
        );

        return str_replace('...', $lastStep ?: 'booking', $template);
    }

    protected function buildAdminNotificationKey(
        array $action,
        string $message,
        ChatbotConversationState $conversation
    ): string {
        $explicit = trim((string) ($action['notification_key'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $type    = (string) ($action['type'] ?? 'notify_admin');
        $channel = (string) ($action['channel'] ?? 'main_admin');

        return $type . ':' . $channel . ':' . md5(
            $conversation->customer_phone . '|' . $conversation->status . '|' . $message
        );
    }

    protected function humanizeStep(?string $step): ?string
    {
        return match ($step) {
            'ask_passenger_count'           => 'jumlah penumpang',
            'ask_departure_time_and_date'   => 'tanggal dan jam keberangkatan',
            'ask_seat'                      => 'pilihan seat',
            'ask_pickup_point'              => 'titik penjemputan',
            'ask_pickup_address'            => 'alamat lengkap penjemputan',
            'ask_dropoff_point'             => 'tujuan pengantaran',
            'ask_passenger_name'            => 'nama penumpang',
            'ask_contact_number'            => 'nomor kontak',
            'ask_review_confirmation'       => 'konfirmasi review perjalanan',
            'ask_change_time_or_date'       => 'perubahan jam atau tanggal',
            'ask_change_seat'               => 'perubahan seat',
            'ask_change_pickup_address'     => 'perubahan alamat jemput',
            'ask_change_dropoff_point'      => 'perubahan tujuan pengantaran',
            'ask_schedule_change_confirmation' => 'konfirmasi perubahan jadwal',
            default                         => $step,
        };
    }

    protected function resolveNow(mixed $now): Carbon
    {
        $tz = config('chatbot.jet.timezone', 'Asia/Jakarta');

        if ($now instanceof Carbon) {
            return $now->copy()->setTimezone($tz);
        }

        if (is_string($now) && trim($now) !== '') {
            return Carbon::parse($now, $tz);
        }

        return now($tz);
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
