<?php

namespace App\Services\Chatbot;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\WhatsApp\WhatsAppSenderService;

class TravelWhatsAppPipelineService
{
    public function __construct(
        private readonly TravelChatbotOrchestratorService $orchestrator,
        private readonly TravelConversationStateService $stateService,
        private readonly ConversationManagerService $conversationManager,
        private readonly WhatsAppSenderService $sender,
    ) {
    }

    public function handleIfApplicable(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer,
    ): bool {
        $direction = is_string($message->direction) ? $message->direction : $message->direction?->value;
        $senderType = is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value;
        $text = trim((string) ($message->message_text ?? ''));

        if ($direction !== MessageDirection::Inbound->value) {
            return false;
        }

        if ($senderType !== SenderType::Customer->value) {
            return false;
        }

        if ($text === '') {
            return false;
        }

        if ((string) $conversation->channel !== 'whatsapp') {
            return false;
        }

        $phone = trim((string) ($customer->phone_e164 ?? $customer->phone ?? ''));
        if ($phone === '') {
            return false;
        }

        $state = $this->stateService->findOrCreate(
            customerPhone: $phone,
            channel: 'whatsapp',
            customerName: $customer->name,
        );

        $routerState = $this->stateService->toRouterState($state);

        if (! $this->isTravelRelated($text, $routerState)) {
            return false;
        }

        $this->orchestrator->handleIncoming(
            [
                'text' => $text,
                'customer_phone' => $phone,
                'customer_name' => $customer->name,
                'channel' => 'whatsapp',
                'message_id' => (string) $message->id,
                'now' => now(config('chatbot.jet.timezone', 'Asia/Jakarta')),
            ],
            [
                'send_reply' => function (string $toPhone, string $replyText, array $context = []) use ($conversation): array {
                    $delivery = $this->sender->sendText($toPhone, $replyText, [
                        'source' => 'travel_whatsapp_pipeline',
                        'conversation_id' => $conversation->id,
                    ]);

                    $this->conversationManager->appendOutboundMessage(
                        $conversation,
                        $replyText,
                        [
                            'source' => 'travel_whatsapp_pipeline',
                            'delivery' => $delivery,
                        ],
                        'text'
                    );

                    return $delivery;
                },

                'notify_admin' => function (string $adminPhone, string $adminMessage, array $context = []): array {
                    if (trim($adminPhone) === '' || trim($adminMessage) === '') {
                        return ['status' => 'skipped', 'reason' => 'empty_admin_payload'];
                    }

                    return $this->sender->sendText($adminPhone, $adminMessage, [
                        'source' => 'travel_whatsapp_pipeline_admin_notify',
                    ]);
                },
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isTravelRelated(string $text, array $state): bool
    {
        $status = (string) ($state['status'] ?? 'idle');
        $currentStep = (string) ($state['current_step'] ?? '');

        if (in_array($status, ['booking', 'schedule_change', 'booking_confirmed'], true)) {
            return true;
        }

        if ($currentStep !== '') {
            return true;
        }

        $normalized = mb_strtolower(trim($text), 'UTF-8');

        $keywords = [
            'travel',
            'booking',
            'pesan',
            'jadwal',
            'keberangkatan',
            'berangkat',
            'jam 5',
            'jam 8',
            'jam 10',
            'jam 14',
            'jam 16',
            'jam 19',
            'harga',
            'ongkos',
            'tarif',
            'seat',
            'kursi',
            'jemput',
            'antar',
            'tujuan',
            'pickup',
            'dropoff',
            'ubah jadwal',
            'ganti jadwal',
            'pekanbaru',
            'ujung batu',
            'pasir pengaraian',
            'kabun',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
