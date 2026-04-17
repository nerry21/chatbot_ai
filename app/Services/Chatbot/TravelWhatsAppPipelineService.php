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

                    $usedInteractive = false;
                    $interactiveMetaPayload = [];

                    if ($interactiveType === 'list' && $interactiveList !== []) {
                        // Send greeting text separately before interactive menu
                        $greetingKey = (string) ($meta['greeting_key'] ?? '');
                        if ($greetingKey !== '' && trim($replyText) !== '') {
                            $greetingDelivery = $this->sender->sendText($toPhone, $replyText, $deliveryMeta);
                            $greetingMessage = $this->conversationManager->appendOutboundMessage(
                                $conversation,
                                $replyText,
                                [
                                    'source' => 'travel_whatsapp_pipeline',
                                    'delivery' => $greetingDelivery,
                                    'meta' => $meta,
                                ],
                                'text'
                            );

                            $this->applyDeliveryStatus($greetingMessage, $greetingDelivery);
                        }

                        $fallbackText = $this->resolveInteractiveFallbackText($replyText, $meta, $interactiveList);
                        $sendResult = $this->sendInteractiveOrTextFallback(
                            to: $toPhone,
                            interactivePayload: $interactiveList,
                            fallbackText: $fallbackText,
                            deliveryMeta: $deliveryMeta,
                        );

                        $delivery = $sendResult['delivery'];
                        $usedInteractive = ! $sendResult['used_text_fallback'];
                        $loggedText = $sendResult['used_text_fallback'] ? $fallbackText : $replyText;

                        if ($usedInteractive) {
                            $interactiveMetaPayload = $this->buildInteractiveOutboundPayload($interactiveList);
                        }
                    } else {
                        $delivery = $this->sender->sendText($toPhone, $replyText, $deliveryMeta);
                    }

                    $rawPayload = [
                        'source' => 'travel_whatsapp_pipeline',
                        'delivery' => $delivery,
                        'meta' => $meta,
                    ];

                    if ($usedInteractive) {
                        $rawPayload['outbound_payload'] = [
                            'interactive' => $interactiveMetaPayload,
                        ];
                    }

                    $outboundMessage = $this->conversationManager->appendOutboundMessage(
                        $conversation,
                        $loggedText,
                        $rawPayload,
                        $usedInteractive ? 'interactive' : 'text'
                    );

                    $this->applyDeliveryStatus($outboundMessage, $delivery);

                    return $delivery;
                },

                'notify_admin' => function (string $adminPhone, string $adminMessage, array $context = []): array {
                    if (trim($adminMessage) === '') {
                        return ['status' => 'skipped', 'reason' => 'empty_admin_payload'];
                    }

                    // Kumpulkan semua nomor admin (admin_phone + admin_phones)
                    $allPhones = array_values(array_unique(array_filter(
                        array_merge(
                            [trim($adminPhone)],
                            array_map('trim', (array) config('chatbot.jet.admin_phones', [])),
                        ),
                        static fn (string $p): bool => $p !== '',
                    )));

                    if ($allPhones === []) {
                        return ['status' => 'skipped', 'reason' => 'no_admin_phones'];
                    }

                    $results = [];
                    foreach ($allPhones as $phone) {
                        $results[$phone] = $this->sender->sendText($phone, $adminMessage, [
                            'source' => 'travel_whatsapp_pipeline_admin_notify',
                        ]);
                    }

                    // Return status dari nomor utama
                    $primaryResult = $results[$allPhones[0]] ?? ['status' => 'failed'];

                    return array_merge($primaryResult, [
                        'all_results' => $results,
                        'phones_count' => count($allPhones),
                    ]);
                },
            ]
        );

        if (($result['intent'] ?? null) === 'booking_confirmed') {
            $bookingData = (array) (($result['router_result']['new_state']['booking_data'] ?? null)
                ?? ($result['conversation']->booking_data ?? []));

            $this->persistBookingRequest($conversation, $customer, $bookingData);
        }

        // If router defers to LLM, handle it here with direct OpenAI call
        if (($result['intent'] ?? null) === 'defer_to_llm') {
            $originalText = (string) ($result['router_result']['meta']['original_text'] ?? $text);
            $customerName = $customer->name ?? 'Bapak/Ibu';

            // Check if this is a closing message — reset state after responding
            $isClosingMessage = $this->isClosingMessage($originalText);

            // Detect booking intent context: either this message or the recent
            // conversation mentions booking/scheduling/travel intent. When the
            // customer says "oke baik" right after the bot shared schedule info,
            // we should follow the LLM reply with an interactive service menu
            // instead of letting the thread die on "Siap kak".
            // NOTE: use $routerState (array) not $state (Eloquent Model) —
            // shouldFollowUpWithServiceMenu expects array as 3rd argument.
            $shouldFollowUpWithMenu = $this->shouldFollowUpWithServiceMenu(
                $originalText,
                $conversation,
                $routerState,
                $isClosingMessage
            );

            $llmReply = $this->callLlmForNaturalResponse($originalText, $customerName, $conversation, $message);

            $deliveryMeta = [
                'source' => 'travel_whatsapp_pipeline_llm',
                'conversation_id' => $conversation->id,
            ];

            $delivery = $this->sender->sendText($phone, $llmReply, $deliveryMeta);

            $llmMessage = $this->conversationManager->appendOutboundMessage(
                $conversation,
                $llmReply,
                [
                    'source' => 'travel_whatsapp_pipeline_llm',
                    'delivery' => $delivery,
                    'meta' => ['intent' => 'llm_natural_response'],
                ],
                'text'
            );

            $this->applyDeliveryStatus($llmMessage, $delivery);

            // If the customer is ready to book (or has just confirmed after a
            // booking-related LLM answer), follow up with the interactive
            // service menu so the conversation advances instead of stalling.
            if ($shouldFollowUpWithMenu) {
                $menuList = [
                    'button'   => 'Pilih Layanan',
                    'header'   => 'Layanan JET Travel',
                    'body'     => 'Bila berkenan memesan, silakan pilih layanan di bawah ini ya, Bapak/Ibu 🙏',
                    'footer'   => 'JET Travel Rokan Hulu',
                    'sections' => [
                        [
                            'title' => 'Pilihan Layanan',
                            'rows'  => [
                                ['id' => 'service:reguler',  'title' => 'Reguler',          'description' => 'Antar-jemput standar'],
                                ['id' => 'service:dropping', 'title' => 'Dropping',         'description' => '1 mobil langsung ke tujuan'],
                                ['id' => 'service:rental',   'title' => 'Rental',           'description' => 'Sewa mobil min. 2 hari'],
                                ['id' => 'service:paket',    'title' => 'Pengiriman Paket', 'description' => 'Kirim barang antar kota'],
                            ],
                        ],
                    ],
                ];

                $menuBody = (string) $menuList['body'];
                $menuFallbackText = $menuBody."\n\n1. Reguler\n2. Dropping\n3. Rental\n4. Pengiriman Paket\n\nSilakan balas dengan nomor atau nama layanannya.";

                $menuSendResult = $this->sendInteractiveOrTextFallback(
                    to: $phone,
                    interactivePayload: $menuList,
                    fallbackText: $menuFallbackText,
                    deliveryMeta: [
                        'source' => 'travel_whatsapp_pipeline_llm_followup_menu',
                        'conversation_id' => $conversation->id,
                    ],
                );

                $menuUsedInteractive = ! $menuSendResult['used_text_fallback'];
                $menuLoggedText = $menuUsedInteractive ? $menuBody : $menuFallbackText;

                $menuRawPayload = [
                    'source' => 'travel_whatsapp_pipeline_llm_followup_menu',
                    'delivery' => $menuSendResult['delivery'],
                    'meta' => [
                        'intent' => 'llm_followup_service_menu',
                        'interactive_type' => 'list',
                    ],
                ];

                if ($menuUsedInteractive) {
                    $menuRawPayload['outbound_payload'] = [
                        'interactive' => $this->buildInteractiveOutboundPayload($menuList),
                    ];
                }

                $menuMessage = $this->conversationManager->appendOutboundMessage(
                    $conversation,
                    $menuLoggedText,
                    $menuRawPayload,
                    $menuUsedInteractive ? 'interactive' : 'text'
                );

                $this->applyDeliveryStatus($menuMessage, $menuSendResult['delivery']);

                // Don't reset state when we've just offered the menu — the
                // customer is about to pick a service.
                return true;
            }

            // Reset state after closing message so next interaction starts fresh
            if ($isClosingMessage) {
                $this->stateService->resetForNewConversation($state);
            }

            return true;
        }

        return true;
    }

    private function isClosingMessage(string $text): bool
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

        $closingPatterns = [
            'siap', 'siap min', 'siap kak', 'siap ya', 'siap bang', 'siap pak', 'siap bu',
            'oke', 'ok', 'oke min', 'oke kak', 'oke ya', 'ok ya', 'ok min', 'ok kak',
            'oke baik', 'ok baik', 'oke sip', 'ok sip', 'oke siap', 'ok siap',
            'baik', 'baik min', 'baik kak', 'baik ya', 'baik bang', 'baik pak', 'baik bu',
            'baik sip', 'baik siap', 'baiklah',
            'sip', 'sip min', 'sip kak', 'sip ya', 'sip lah', 'siplah',
            'tidak ada', 'tidak ada min', 'tidak ada kak',
            'sudah cukup', 'cukup', 'cukup min',
            'tidak ada lagi', 'ga ada', 'gak ada', 'engga ada', 'nggak ada',
            'terima kasih', 'makasih', 'makasih kak', 'terima kasih kak', 'thanks', 'thank you',
        ];

        return in_array($normalized, $closingPatterns, true);
    }

    /**
     * Decide whether the LLM reply should be followed by an interactive
     * service menu. This bridges the gap between LLM natural answers and the
     * rule-based booking flow: when the customer signals booking intent —
     * either directly ("mau pesan") or by confirming ("oke baik") right
     * after the bot shared schedule/fare info — we proactively offer the
     * service menu so the conversation keeps moving.
     *
     * @param  array<string, mixed>  $state
     */
    private function shouldFollowUpWithServiceMenu(
        string $originalText,
        Conversation $conversation,
        array $state,
        bool $isClosingMessage,
    ): bool {
        // Only when the customer is not already inside a booking/paket/
        // dropping/rental/schedule_change flow — those have their own menus.
        $status = (string) ($state['status'] ?? 'idle');
        if (! in_array($status, ['idle', ''], true)) {
            return false;
        }

        $normalized = mb_strtolower(trim($originalText), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = (string) preg_replace('/\s+/u', ' ', trim($normalized));

        if ($normalized === '') {
            return false;
        }

        // Direct booking intent words → always offer the menu.
        $bookingIntentPatterns = [
            'mau booking', 'mau boking', 'mau pesan', 'mau berangkat',
            'ingin booking', 'ingin boking', 'ingin pesan', 'ingin berangkat',
            'pesan travel', 'pesan tiket', 'reservasi', 'pemesanan',
            'saya mau pesan', 'saya mau booking', 'saya mau boking',
            'saya mau berangkat', 'jadi mau pesan', 'jadi pesan',
            'jadi booking', 'jadi boking',
        ];

        foreach ($bookingIntentPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        // Positive confirmation after a booking-related bot message → offer
        // the menu so the customer can proceed. Without this the thread dies
        // at "Siap kak" when the customer was actually about to book.
        if ($isClosingMessage) {
            return $this->recentBotReplyLooksBookingRelated($conversation);
        }

        return false;
    }

    /**
     * Check the most recent outbound (bot) message to see if the previous
     * exchange was about scheduling/fares/booking. Used to decide whether a
     * customer "oke baik" deserves a follow-up service menu.
     */
    private function recentBotReplyLooksBookingRelated(Conversation $conversation): bool
    {
        try {
            $recent = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', MessageDirection::Outbound)
                ->orderByDesc('id')
                ->limit(3)
                ->get();
        } catch (\Throwable $e) {
            return false;
        }

        if ($recent->isEmpty()) {
            return false;
        }

        $bookingSignals = [
            'jadwal', 'keberangkatan', 'berangkat', 'pukul', 'wib',
            'tarif', 'harga', 'rp', 'seat', 'kursi',
            'booking', 'boking', 'pesan', 'reservasi', 'antar jemput',
            'dropping', 'rental', 'paket',
        ];

        foreach ($recent as $previous) {
            $body = mb_strtolower((string) ($previous->message_text ?? ''), 'UTF-8');

            if ($body === '') {
                continue;
            }

            foreach ($bookingSignals as $signal) {
                if (str_contains($body, $signal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reflect the provider delivery result onto the persisted message so the
     * admin mobile UI can render the correct delivery ticks. Without this,
     * every outbound message would remain `pending` (clock icon) forever —
     * status updates from Meta only arrive for inbound-triggered messages,
     * and marking sent at append time gives an immediate single tick.
     *
     * @param  array<string, mixed>  $delivery
     */
    private function applyDeliveryStatus(ConversationMessage $message, array $delivery): void
    {
        $status = (string) ($delivery['status'] ?? '');

        if ($status === 'sent') {
            $waMessageId = null;
            $response = is_array($delivery['response'] ?? null) ? $delivery['response'] : [];
            $messages = is_array($response['messages'] ?? null) ? $response['messages'] : [];
            if (isset($messages[0]['id']) && is_string($messages[0]['id']) && $messages[0]['id'] !== '') {
                $waMessageId = $messages[0]['id'];
            }

            $message->markSent($waMessageId, ['wa_send_result' => $delivery]);

            return;
        }

        if ($status === 'failed') {
            $errorMessage = (string) ($delivery['error'] ?? 'send_failed');
            $message->markFailed($errorMessage, ['wa_send_result' => $delivery]);
        }
    }

    /**
     * Convert the internal router-style interactive list payload into the
     * canonical Meta WhatsApp structure that {@see ConversationMessageResource}
     * expects (action.sections[].rows[].title, body.text, footer.text,
     * header.text, action.button). Mirrors the mapping performed inside
     * {@see \App\Services\WhatsApp\WhatsAppSenderService::sendInteractiveList}.
     *
     * @param  array<string, mixed>  $interactiveList
     * @return array<string, mixed>
     */
    private function buildInteractiveOutboundPayload(array $interactiveList): array
    {
        $payload = [
            'type' => 'list',
            'body' => [
                'text' => (string) ($interactiveList['body'] ?? 'Silakan pilih salah satu.'),
            ],
            'action' => [
                'button' => (string) ($interactiveList['button'] ?? 'Pilih'),
                'sections' => array_values((array) ($interactiveList['sections'] ?? [])),
            ],
        ];

        $headerText = trim((string) ($interactiveList['header'] ?? ''));
        if ($headerText !== '') {
            $payload['header'] = [
                'type' => 'text',
                'text' => $headerText,
            ];
        }

        $footerText = trim((string) ($interactiveList['footer'] ?? ''));
        if ($footerText !== '') {
            $payload['footer'] = [
                'text' => $footerText,
            ];
        }

        return $payload;
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
            'ask_pickup_point', 'paket_ask_pickup' => $this->buildNumberedLocationFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Izin Bapak/Ibu, silakan pilih titik penjemputannya.',
                listLabel: 'Pilihan lokasi:',
                closing: 'Silakan balas dengan nomor yang sesuai dengan lokasi, sebagai contoh ketik 19 otomatis sama dengan Pekanbaru.',
            ),
            'ask_dropoff_point', 'paket_ask_dropoff' => $this->buildNumberedLocationFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Untuk pengantarannya ke mana, Bapak/Ibu? Silakan pilih lokasinya.',
                listLabel: 'Pilihan tujuan:',
                closing: 'Silakan balas dengan nomor yang sesuai dengan lokasi, sebagai contoh ketik 19 otomatis sama dengan Pekanbaru.',
            ),
            'paket_ask_date' => $this->buildNumberedFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Silakan pilih tanggal pengiriman.',
                listLabel: 'Pilihan tanggal:',
                closing: 'Silakan balas dengan nomor yang sesuai.',
            ),
            'paket_ask_time' => $this->buildNumberedFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Silakan pilih jam keberangkatan.',
                listLabel: 'Pilihan jam:',
                closing: 'Silakan balas dengan nomor yang sesuai.',
            ),
            'paket_ask_size' => $this->buildNumberedFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Silakan pilih ukuran paket.',
                listLabel: 'Pilihan ukuran:',
                closing: 'Silakan balas dengan nomor atau ketik langsung (Kecil/Sedang/Besar).',
            ),
            'paket_ask_seat' => $this->buildNumberedFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Silakan pilih seat untuk paket berukuran besar.',
                listLabel: 'Pilihan seat:',
                closing: 'Silakan balas dengan nomor atau ketik langsung.',
            ),
            'paket_ask_type' => $this->buildNumberedFallbackText(
                interactivePayload: $interactivePayload,
                intro: 'Silakan pilih jenis paket yang dikirim.',
                listLabel: 'Pilihan jenis:',
                closing: 'Silakan balas dengan nomor atau ketik langsung.',
            ),
            default => trim($replyText) !== ''
                ? $replyText
                : (string) ($interactivePayload['body'] ?? 'Silakan pilih salah satu.'),
        };
    }

    /**
     * Generic numbered fallback for any interactive list (date, time, size, etc.)
     */
    private function buildNumberedFallbackText(
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
                $desc = trim((string) ($row['description'] ?? ''));
                $lines[] = $desc !== '' ? $index.'. '.$title.' — '.$desc : $index.'. '.$title;
                $index++;
            }
        }

        $lines[] = '';
        $lines[] = $closing;

        return implode("\n", $lines);
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

    private function callLlmForNaturalResponse(
        string $customerMessage,
        string $customerName,
        Conversation $conversation,
        ConversationMessage $message,
    ): string {
        $fallbackReply = 'Izin Bapak/Ibu, pertanyaannya sedang kami konsultasikan ke admin ya. Mohon tunggu sebentar 🙏';

        try {
            // Build knowledge context
            $knowledgeContext = '';
            try {
                $knowledgeBase = app(\App\Services\Knowledge\KnowledgeBaseService::class);
                $knowledgeHits = $knowledgeBase->search($customerMessage, ['max_in_prompt' => 3]);
                foreach ($knowledgeHits as $hit) {
                    $knowledgeContext .= "\n\n--- " . ($hit['title'] ?? '') . " ---\n" . ($hit['content'] ?? '');
                }
            } catch (\Throwable $e) {
                Log::debug('[LLM] Knowledge base search failed: ' . $e->getMessage());
            }

            // Build fare info from config
            $fareLines = [];
            foreach ((array) config('chatbot.jet.fare_rules', []) as $rule) {
                $a = implode(', ', (array) ($rule['a'] ?? []));
                $b = implode(', ', (array) ($rule['b'] ?? []));
                $amt = number_format((int) ($rule['amount'] ?? 0), 0, ',', '.');
                $fareLines[] = "• {$a} ↔ {$b}: Rp {$amt}";
            }

            // Build schedule info from config
            $scheduleLines = [];
            foreach ((array) config('chatbot.jet.departure_slots', []) as $slot) {
                $scheduleLines[] = "• " . ($slot['label'] ?? '') . " (" . ($slot['time'] ?? '') . " WIB)";
            }

            // Build locations
            $locationLabels = array_filter(array_map(
                fn($loc) => $loc['label'] ?? '',
                (array) config('chatbot.jet.locations', [])
            ));

            $systemPrompt = "Kamu adalah admin travel WhatsApp bernama JET (Jaya Executive Transport).\n"
                . "Jawab pertanyaan customer dengan natural, sopan, hangat, dan ringkas.\n"
                . "Gunakan bahasa Indonesia kasual-sopan. Gunakan emoji 🙏 😊 secukupnya.\n\n"
                . "ATURAN:\n"
                . "1. Jawab HANYA dari FAKTA di bawah. DILARANG mengarang.\n"
                . "2. Jika tidak tahu, bilang akan dikonsultasikan ke admin.\n"
                . "3. Maksimal 3-4 kalimat.\n"
                . "4. JANGAN memulai proses booking. Jika customer mau booking, arahkan bilang 'mau booking' atau 'pesan'.\n"
                . "5. Jika customer bilang terima kasih, oke, siap, baik → balas SINGKAT saja, misal 'Siap kak 🙏' atau 'Sama-sama kak 😊🙏'. Sistem akan otomatis menawarkan menu pemesanan bila memang konteksnya tentang booking, jadi kamu cukup balas singkat.\n"
                . "6. Jika customer bilang 'tidak ada', 'sudah cukup', 'tidak ada lagi' → balas penutup singkat saja, misal 'Baik kak, terima kasih ya 🙏'. JANGAN tanya lagi.\n\n"
                . "=== JADWAL (setiap hari) ===\n" . implode("\n", $scheduleLines) . "\n\n"
                . "=== TARIF (per orang) ===\n" . implode("\n", $fareLines) . "\n\n"
                . "=== LOKASI ===\n" . implode(', ', $locationLabels) . "\n\n"
                . "=== SEAT ===\nCC, BS Kiri, BS Kanan, BS Tengah, Belakang Kiri, Belakang Kanan\n"
                . $knowledgeContext;

            // Get API config
            $apiKey = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
            $baseUrl = rtrim((string) (config('openai.base_url', 'https://api.openai.com/v1')), '/');

            if ($apiKey === '') {
                Log::error('[LLM] OpenAI API key not configured');
                return $fallbackReply;
            }

            // Use model from config, fallback to gpt-5.4-mini
            $model = (string) config('chatbot.llm.models.grounded_response',
                config('chatbot.llm.models.reply', 'gpt-5.4-mini'));

            Log::info('[LLM] Calling OpenAI', [
                'conversation_id' => $conversation->id,
                'model' => $model,
                'message_preview' => mb_substr($customerMessage, 0, 80),
            ]);

            // GPT-5.x uses max_completion_tokens + reasoning.effort (no temperature)
            $requestBody = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $customerMessage],
                ],
                'max_completion_tokens' => 500,
            ];

            if (str_starts_with($model, 'gpt-5')) {
                $requestBody['reasoning_effort'] = 'low';
            } else {
                $requestBody['temperature'] = 0.7;
            }

            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->timeout(60)
                ->post("{$baseUrl}/chat/completions", $requestBody);

            if (! $response->successful()) {
                Log::error('[LLM] OpenAI API error', [
                    'status' => $response->status(),
                    'error' => mb_substr($response->body(), 0, 500),
                    'model' => $model,
                ]);
                return $fallbackReply;
            }

            $llmReply = trim((string) ($response->json('choices.0.message.content') ?? ''));

            // Clean JSON wrapper if LLM returned JSON
            if ($llmReply !== '' && (str_starts_with($llmReply, '{') || str_starts_with($llmReply, '```'))) {
                $cleaned = preg_replace('/^```json\s*|\s*```$/s', '', $llmReply) ?? $llmReply;
                $decoded = json_decode(trim($cleaned), true);
                if (is_array($decoded) && isset($decoded['text'])) {
                    $llmReply = trim((string) $decoded['text']);
                }
            }

            if ($llmReply === '') {
                Log::warning('[LLM] Empty reply from OpenAI');
                return $fallbackReply;
            }

            Log::info('[LLM] Success', [
                'conversation_id' => $conversation->id,
                'reply_preview' => mb_substr($llmReply, 0, 100),
            ]);

            return $llmReply;

        } catch (\Throwable $e) {
            Log::error('[LLM] Exception', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 500),
            ]);
            return $fallbackReply;
        }
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