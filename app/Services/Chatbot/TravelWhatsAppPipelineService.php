<?php

namespace App\Services\Chatbot;

use App\Enums\BookingStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BookingRequest;
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

        $result = $this->orchestrator->handleIncoming(
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
                    $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
                    $interactiveType = (string) ($meta['interactive_type'] ?? '');
                    $interactiveList = is_array($meta['interactive_list'] ?? null) ? $meta['interactive_list'] : [];

                    if ($interactiveType === 'list' && $interactiveList !== []) {
                        $delivery = $this->sender->sendInteractiveList($toPhone, $interactiveList, [
                            'source' => 'travel_whatsapp_pipeline',
                            'conversation_id' => $conversation->id,
                        ]);

                        if (trim($replyText) !== '') {
                            $this->sender->sendText($toPhone, $replyText, [
                                'source' => 'travel_whatsapp_pipeline_followup_text',
                                'conversation_id' => $conversation->id,
                            ]);
                        }
                    } else {
                        $delivery = $this->sender->sendText($toPhone, $replyText, [
                            'source' => 'travel_whatsapp_pipeline',
                            'conversation_id' => $conversation->id,
                        ]);
                    }

                    $this->conversationManager->appendOutboundMessage(
                        $conversation,
                        $replyText,
                        [
                            'source' => 'travel_whatsapp_pipeline',
                            'delivery' => $delivery,
                            'meta' => $meta,
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

        if (($result['intent'] ?? null) === 'booking_confirmed') {
            $bookingData = (array) (($result['router_result']['new_state']['booking_data'] ?? null)
                ?? ($result['conversation']->booking_data ?? []));

            $this->persistBookingRequest($conversation, $customer, $bookingData);
        }

        return true;
    }

    private function persistBookingRequest(
        Conversation $conversation,
        Customer $customer,
        array $bookingData,
    ): void {
        $passengerNames = $bookingData['passenger_names'] ?? null;
        $passengerName  = is_array($passengerNames) && count($passengerNames) > 0
            ? implode(', ', $passengerNames)
            : null;

        BookingRequest::create([
            'conversation_id'  => $conversation->id,
            'customer_id'      => $customer->id,
            'departure_date'   => $bookingData['departure_date'] ?? null,
            'departure_time'   => $bookingData['departure_time'] ?? null,
            'passenger_count'  => $bookingData['passenger_count'] ?? null,
            'passenger_names'  => is_array($passengerNames) ? $passengerNames : null,
            'passenger_name'   => $passengerName,
            'pickup_location'  => $bookingData['pickup_point'] ?? null,
            'pickup_full_address' => $bookingData['pickup_address'] ?? null,
            'destination'      => $bookingData['dropoff_point'] ?? null,
            'selected_seats'   => isset($bookingData['seat']) ? [$bookingData['seat']] : null,
            'contact_number'   => $bookingData['contact_number'] ?? null,
            'booking_status'   => BookingStatus::Confirmed,
            'confirmed_at'     => now(config('chatbot.jet.timezone', 'Asia/Jakarta')),
        ]);
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
            'jam 7',
            'jam 9',
            'jam 13',
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