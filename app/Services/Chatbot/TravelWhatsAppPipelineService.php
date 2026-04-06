<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Support\WaLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class TravelWhatsAppPipelineService
{
    public function __construct(
        protected TravelChatbotOrchestratorService $orchestrator,
        protected TravelConversationStateService $stateService,
        protected ConversationManagerService $conversationManager,
        protected ConversationOutboundRouterService $outboundRouter,
    ) {
    }

    /**
     * Mengembalikan true jika pesan sudah ditangani oleh pipeline travel.
     */
    public function handleIfApplicable(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer
    ): bool {
        if (! $this->shouldHandle($message, $conversation, $customer)) {
            return false;
        }

        $incomingText = $this->extractIncomingText($message);
        if ($incomingText === '') {
            return false;
        }

        $customerPhone = $this->resolveCustomerPhone($conversation, $customer);
        if ($customerPhone === '') {
            WaLog::warning('[TravelPipeline] Customer phone empty, skip travel pipeline', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);

            return false;
        }

        /**
         * Jika state sebelumnya sudah dianggap batal karena timeout,
         * dan customer menghubungi lagi, maka mulai dari awal lagi.
         */
        $travelState = $this->stateService->findOrCreate(
            $customerPhone,
            'whatsapp',
            $customer->name ?? null
        );

        if ((bool) $travelState->is_cancelled === true) {
            $this->stateService->resetForNewConversation($travelState);

            WaLog::info('[TravelPipeline] Reset cancelled travel state for new incoming message', [
                'conversation_id'  => $conversation->id,
                'message_id'       => $message->id,
                'customer_id'      => $customer->id ?? null,
                'travel_state_id'  => $travelState->id,
            ]);
        }

        $result = $this->orchestrator->handleIncoming(
            [
                'text' => $incomingText,
                'customer_phone' => $customerPhone,
                'customer_name' => $customer->name ?? null,
                'channel' => 'whatsapp',
                'message_id' => (string) $message->id,
                'now' => now(config('chatbot.jet.timezone', 'Asia/Jakarta')),
            ],
            [
                'send_reply' => function (string $phone, string $replyText, array $context) use ($conversation, $message) {
                    return $this->dispatchOutboundMessage(
                        $conversation,
                        $message,
                        $phone,
                        $replyText,
                        $context
                    );
                },

                'notify_admin' => function (string $adminPhone, string $adminMessage, array $context) use ($message) {
                    return $this->sendAdminNotification(
                        $message,
                        $adminPhone,
                        $adminMessage,
                        $context
                    );
                },
            ]
        );

        WaLog::info('[TravelPipeline] Message handled by travel orchestrator', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'intent' => $result['intent'] ?? null,
            'conversation_state_id' => $result['conversation_state_id'] ?? null,
        ]);

        return true;
    }

    protected function shouldHandle(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer
    ): bool {
        $text = mb_strtolower($this->extractIncomingText($message));

        if ($text === '') {
            return false;
        }

        /**
         * Aturan awal:
         * 1. Jika ada keyword travel, tangani
         * 2. Jika nomor sudah punya state travel aktif, tangani
         */
        $travelKeywords = [
            'booking',
            'pesan travel',
            'mau pesan',
            'keberangkatan',
            'jadwal',
            'seat',
            'kursi',
            'penjemputan',
            'pengantaran',
            'ongkos',
            'tarif',
            'pekanbaru',
            'bangkinang',
            'kabun',
            'ujung batu',
            'pasirpengaraian',
            'petapahan',
            'suram',
            'aliantan',
            'tandun',
            'skpd',
            'simpang d',
            'skpc',
            'simpang kumu',
            'muara rumbai',
            'surau tinggi',
            'ubah jadwal',
            'ganti jadwal',
            'perubahan jadwal',
            'assalamualaikum',
        ];

        foreach ($travelKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        $phone = $this->resolveCustomerPhone($conversation, $customer);
        if ($phone !== '') {
            $state = $this->stateService->findOrCreate($phone, 'whatsapp', $customer->name ?? null);

            if (in_array($state->status, ['booking', 'booking_confirmed', 'schedule_change'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function dispatchOutboundMessage(
        Conversation $conversation,
        ConversationMessage $incomingMessage,
        string $phone,
        string $replyText,
        array $context = []
    ): array {
        try {
            $outboundMessage = $this->conversationManager->appendOutboundMessage(
                conversation: $conversation,
                text: $replyText,
                rawPayload: [
                    'source' => 'travel_orchestrator',
                    'incoming_conversation_message_id' => $incomingMessage->id,
                    'intent' => $context['intent'] ?? null,
                    'router_meta' => $context['meta'] ?? [],
                ],
                messageType: 'text',
            );

            $this->outboundRouter->dispatch($outboundMessage, WaLog::traceId());

            return [
                'status' => 'queued',
                'channel' => 'whatsapp',
                'target' => $phone,
                'source' => 'travel_orchestrator',
                'conversation_message_id' => $outboundMessage->id,
            ];
        } catch (Throwable $e) {
            Log::error('[TravelPipeline] Failed queue outbound reply', [
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'channel' => 'whatsapp',
                'target' => $phone,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function sendAdminNotification(
        ConversationMessage $incomingMessage,
        string $adminPhone,
        string $adminMessage,
        array $context = []
    ): array {
        try {
            $adminConversation = $this->findOrCreateAdminConversation($adminPhone);

            $outboundMessage = $this->conversationManager->appendOutboundMessage(
                conversation: $adminConversation,
                text: $adminMessage,
                rawPayload: [
                    'source' => 'travel_orchestrator_admin_notify',
                    'incoming_conversation_message_id' => $incomingMessage->id,
                    'intent' => $context['intent'] ?? null,
                    'router_meta' => $context['meta'] ?? [],
                ],
                messageType: 'text',
            );

            $this->outboundRouter->dispatch($outboundMessage, WaLog::traceId());

            return [
                'status' => 'queued',
                'channel' => 'whatsapp',
                'target' => $adminPhone,
                'source' => 'travel_orchestrator_admin_notify',
                'conversation_id' => $adminConversation->id,
                'conversation_message_id' => $outboundMessage->id,
            ];
        } catch (Throwable $e) {
            Log::error('[TravelPipeline] Failed sending admin notification via admin conversation', [
                'incoming_message_id' => $incomingMessage->id,
                'admin_phone' => $adminPhone,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'channel' => 'whatsapp',
                'target' => $adminPhone,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Opsi B:
     * Buat / cari conversation khusus admin utama.
     *
     * Asumsi aman:
     * - project Anda punya Customer model
     * - conversation berelasi ke customer
     * - customer phone_e164 dipakai outbound router/job
     *
     * Bila field nama/tipe di project Anda sedikit berbeda,
     * bagian ini yang paling mungkin perlu penyesuaian kecil.
     */
    protected function findOrCreateAdminConversation(string $adminPhone): Conversation
    {
        $normalizedAdminPhone = $this->normalizePhone($adminPhone);

        /** @var Customer $adminCustomer */
        $adminCustomer = Customer::query()->firstOrCreate(
            [
                'phone_e164' => $normalizedAdminPhone,
            ],
            [
                'name' => config('chatbot.jet.business_name', 'JET') . ' Admin Utama',
            ]
        );

        /** @var Conversation $conversation */
        $conversation = Conversation::query()
            ->where('customer_id', $adminCustomer->id)
            ->where(function ($query) {
                $query->whereNull('channel')
                    ->orWhere('channel', 'whatsapp');
            })
            ->latest('id')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return Conversation::query()->create([
            'customer_id' => $adminCustomer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'subject' => 'Admin Utama Travel',
            'last_message_at' => now(),
        ]);
    }

    protected function extractIncomingText(ConversationMessage $message): string
    {
        return trim((string) (
            $message->message_text
            ?? $message->content
            ?? $message->body
            ?? ''
        ));
    }

    protected function resolveCustomerPhone(Conversation $conversation, Customer $customer): string
    {
        return (string) (
            $customer->phone_e164
            ?? $customer->phone
            ?? $customer->phone_number
            ?? $conversation->customer_phone
            ?? $conversation->phone
            ?? ''
        );
    }

    public function sendFollowUpToCustomerPhone(string $customerPhone, string $message): array
    {
        try {
            $normalizedPhone = $this->normalizePhone($customerPhone);

            $customer = \App\Models\Customer::query()
                ->where('phone_e164', $normalizedPhone)
                ->first();

            if (! $customer) {
                return [
                    'status' => 'error',
                    'target' => $customerPhone,
                    'error'  => 'Customer not found for follow-up.',
                ];
            }

            $conversation = \App\Models\Conversation::query()
                ->where('customer_id', $customer->id)
                ->where('channel', 'whatsapp')
                ->latest('id')
                ->first();

            if (! $conversation) {
                return [
                    'status' => 'error',
                    'target' => $customerPhone,
                    'error'  => 'WhatsApp conversation not found for follow-up.',
                ];
            }

            $outboundMessage = $this->conversationManager->appendOutboundMessage(
                conversation: $conversation,
                text: $message,
                rawPayload: [
                    'source' => 'travel_follow_up_scheduler',
                ],
                messageType: 'text',
            );

            $this->outboundRouter->dispatch($outboundMessage, WaLog::traceId());

            return [
                'status'                  => 'queued',
                'target'                  => $customerPhone,
                'conversation_id'         => $conversation->id,
                'conversation_message_id' => $outboundMessage->id,
            ];
        } catch (\Throwable $e) {
            Log::error('[TravelPipeline] Failed sending follow-up message', [
                'customer_phone' => $customerPhone,
                'error'          => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'target' => $customerPhone,
                'error'  => $e->getMessage(),
            ];
        }
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', trim($phone)) ?? trim($phone);

        if (str_starts_with($phone, '+')) {
            $phone = ltrim($phone, '+');
        }

        return $phone;
    }
}