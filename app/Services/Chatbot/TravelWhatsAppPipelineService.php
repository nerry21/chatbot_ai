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
use Illuminate\Support\Facades\Log;

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
                    $loggedText = $replyText;
                    $deliveryMeta = [
                        'source' => 'travel_whatsapp_pipeline',
                        'conversation_id' => $conversation->id,
                    ];

                    if ($interactiveType === 'list' && $interactiveList !== []) {
                        // Send greeting text separately before interactive menu
                        $greetingKey = (string) ($meta['greeting_key'] ?? '');
                        if ($greetingKey !== '' && trim($replyText) !== '') {
                            $this->sender->sendText($toPhone, $replyText, $deliveryMeta);
                            $this->conversationManager->appendOutboundMessage(
                                $conversation,
                                $replyText,
                                [
                                    'source' => 'travel_whatsapp_pipeline',
                                    'delivery' => ['status' => 'sent'],
                                    'meta' => $meta,
                                ],
                                'text'
                            );
                        }

                        $fallbackText = $this->resolveInteractiveFallbackText($replyText, $meta, $interactiveList);
                        $sendResult = $this->sendInteractiveOrTextFallback(
                            to: $toPhone,
                            interactivePayload: $interactiveList,
                            fallbackText: $fallbackText,
                            deliveryMeta: $deliveryMeta,
                        );

                        $delivery = $sendResult['delivery'];
                        $loggedText = $sendResult['used_text_fallback'] ? $fallbackText : $replyText;
                    } else {
                        $delivery = $this->sender->sendText($toPhone, $replyText, $deliveryMeta);
                    }

                    $this->conversationManager->appendOutboundMessage(
                        $conversation,
                        $loggedText,
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

        // If router defers to LLM, return false so the LLM+CRM pipeline handles it
        if (($result['intent'] ?? null) === 'defer_to_llm') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $interactivePayload
     * @param  array<string, mixed>  $deliveryMeta
     * @return array{delivery: array<string, mixed>, used_text_fallback: bool}
     */
    private function sendInteractiveOrTextFallback(
        string $to,
        array $interactivePayload,
        string $fallbackText,
        array $deliveryMeta = [],
    ): array {
        try {
            $result = $this->sender->sendInteractiveList($to, $interactivePayload, array_merge(
                $deliveryMeta,
                ['disable_interactive_text_fallback' => true],
            ));

            if (($result['status'] ?? null) === 'sent') {
                return [
                    'delivery' => $result,
                    'used_text_fallback' => false,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Interactive list send failed, fallback to text', [
                'to' => $to,
                'header' => $interactivePayload['header'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'delivery' => $this->sender->sendText(
                $to,
                $fallbackText,
                array_merge($deliveryMeta, [
                    'source' => ((string) ($deliveryMeta['source'] ?? 'travel_whatsapp_pipeline')).'_fallback_text',
                    'fallback_from' => 'interactive_list',
                ]),
            ),
            'used_text_fallback' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $interactivePayload
     */
    private function resolveInteractiveFallbackText(
        string $replyText,
        array $meta,
        array $interactivePayload,
    ): string {
        return match ((string) ($meta['step'] ?? '')) {
            'ask_pickup_point' => $this->buildNumberedLocationFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Izin Bapak/Ibu, silakan pilih titik penjemputannya.',
                listLabel: 'Pilihan lokasi:',
                closing: 'Silakan balas dengan nomor yang sesuai dengan lokasi, sebagai contoh ketik 19 otomatis sama dengan Pekanbaru.',
            ),
            'ask_dropoff_point' => $this->buildNumberedLocationFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Untuk pengantarannya ke mana, Bapak/Ibu? Silakan pilih lokasinya.',
                listLabel: 'Pilihan tujuan:',
                closing: 'Silakan balas dengan nomor yang sesuai dengan lokasi, sebagai contoh ketik 19 otomatis sama dengan Pekanbaru.',
            ),
            default => trim($replyText) !== ''
                ? $replyText
                : (string) ($interactivePayload['body'] ?? 'Silakan pilih salah satu.'),
        };
    }

    /**
     * @param  array<string, mixed>  $interactivePayload
     */
    private function buildNumberedLocationFallbackText(
        array $interactivePayload,
        string $intro,
        string $listLabel,
        string $closing,
    ): string {
        $lines = [$intro, '', $listLabel];
        $index = 1;

        foreach ((array) ($interactivePayload['sections'] ?? []) as $section) {
            foreach ((array) ($section['rows'] ?? []) as $row) {
                $title = trim((string) ($row['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                $lines[] = $index.'. '.$title;
                $index++;
            }
        }

        $lines[] = '';
        $lines[] = $closing;

        return implode("\n", $lines);
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
            'destination_full_address' => $bookingData['dropoff_address'] ?? null,
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
        // ALL WhatsApp messages are handled by the Travel Pipeline.
        // The TravelMessageRouterService decides what to do with each message:
        // - Greetings → show service menu
        // - Booking keywords → start booking flow
        // - Dropping/Rental/Paket → forward to admin
        // - Unknown → escalate to admin
        //
        // This prevents the LLM pipeline from running in parallel and
        // sending duplicate/conflicting messages.
        return true;
    }
}