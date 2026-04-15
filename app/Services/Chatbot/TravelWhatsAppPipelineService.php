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

        // If router defers to LLM, handle it here with direct OpenAI call
        if (($result['intent'] ?? null) === 'defer_to_llm') {
            $originalText = (string) ($result['router_result']['meta']['original_text'] ?? $text);
            $customerName = $customer->name ?? 'Bapak/Ibu';

            // Check if this is a closing message — reset state after responding
            $isClosingMessage = $this->isClosingMessage($originalText);

            $llmReply = $this->callLlmForNaturalResponse($originalText, $customerName, $conversation, $message);

            $deliveryMeta = [
                'source' => 'travel_whatsapp_pipeline_llm',
                'conversation_id' => $conversation->id,
            ];

            $delivery = $this->sender->sendText($phone, $llmReply, $deliveryMeta);

            $this->conversationManager->appendOutboundMessage(
                $conversation,
                $llmReply,
                [
                    'source' => 'travel_whatsapp_pipeline_llm',
                    'delivery' => $delivery,
                    'meta' => ['intent' => 'llm_natural_response'],
                ],
                'text'
            );

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
            'siap', 'siap min', 'siap kak', 'siap ya',
            'oke', 'ok', 'oke min', 'oke kak',
            'baik', 'baik min', 'baik kak',
            'sip', 'sip min', 'sip kak',
            'tidak ada', 'tidak ada min', 'tidak ada kak',
            'sudah cukup', 'cukup', 'cukup min',
            'tidak ada lagi', 'ga ada', 'gak ada',
        ];

        return in_array($normalized, $closingPatterns, true);
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
                . "5. Jika customer bilang terima kasih, oke, siap, baik → balas SINGKAT saja, misal 'Siap kak 🙏' atau 'Sama-sama kak 😊🙏'. JANGAN bertanya lagi atau menawarkan bantuan lain.\n"
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