<?php

namespace App\Services\AI;

use App\Enums\IntentType;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class IntentClassifierService
{
    public function __construct(
        private readonly LlmClientService          $llmClient,
        private readonly PromptBuilderService      $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * Classify the intent of the current inbound message.
     *
     * Required context keys: message_text, conversation_id, message_id.
     * Optional context keys: customer_memory, active_states, recent_messages.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function classify(array $context): array
    {
        $intentInput = $this->buildIntentInput($context);
        $defaultResult = [
            'intent' => IntentType::Unknown->value,
            'confidence' => 0.0,
            'should_escalate' => false,
            'entities' => [],
            'reasoning_short' => '',
        ];

        try {
            $prompts = $this->promptBuilder->buildIntentPrompt($intentInput);

            $llmContext = array_merge($context, $intentInput, [
                'system' => $prompts['system'],
                'user' => $prompts['user'],
                'model' => config('chatbot.llm.models.intent'),
            ]);

            $raw = $this->llmClient->classifyIntent($llmContext);

            $validated = $this->validator->validateAndFill(
                data: is_array($raw) ? $raw : [],
                requiredKeys: ['intent', 'confidence'],
                defaults: $defaultResult,
            );

            if ($validated === null) {
                Log::warning('IntentClassifierService: missing required keys in LLM output', ['raw' => $raw]);

                $validated = $defaultResult;
            }

            $normalized = $this->normalizeIntentResult($validated, $intentInput);

            if (($normalized['confidence'] ?? 0) < 0.35) {
                $normalized['intent'] = $normalized['intent'] ?: IntentType::Unknown->value;
                $normalized['reasoning_short'] = $normalized['reasoning_short'] ?: 'Low confidence intent detection';
            }

            if (($intentInput['admin_takeover'] ?? false) === true) {
                $normalized['should_escalate'] = true;
                $normalized['reasoning_short'] = trim(
                    (($normalized['reasoning_short'] ?? '') !== '' ? $normalized['reasoning_short'].'; ' : '')
                    .'Admin takeover active'
                );
            }

            return $normalized;
        } catch (\Throwable $e) {
            Log::error('IntentClassifierService: unexpected error', ['error' => $e->getMessage()]);

            $fallback = $this->normalizeIntentResult($defaultResult, $intentInput);

            if (($fallback['confidence'] ?? 0) < 0.35) {
                $fallback['intent'] = $fallback['intent'] ?: IntentType::Unknown->value;
                $fallback['reasoning_short'] = $fallback['reasoning_short'] ?: 'Low confidence intent detection';
            }

            if (($intentInput['admin_takeover'] ?? false) === true) {
                $fallback['should_escalate'] = true;
                $fallback['reasoning_short'] = trim(
                    (($fallback['reasoning_short'] ?? '') !== '' ? $fallback['reasoning_short'].'; ' : '')
                    .'Admin takeover active'
                );
            }

            return $fallback;
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Map raw LLM intent string to a valid IntentType value.
     * Falls back to Unknown if the string doesn't match any case.
     */
    private function normalizeIntent(mixed $raw): string
    {
        if (! is_string($raw)) {
            return IntentType::Unknown->value;
        }

        $normalized = strtolower(trim($raw));

        // Try exact enum match
        $enum = IntentType::tryFrom($normalized);

        if ($enum !== null) {
            return $enum->value;
        }

        // Fuzzy aliases the LLM might produce
        return match(true) {
            str_contains($normalized, 'salam') && str_contains($normalized, 'islam')
                => IntentType::SalamIslam->value,
            str_contains($normalized, 'greet')
                => IntentType::Greeting->value,
            str_contains($normalized, 'book')
                => IntentType::Booking->value,
            str_contains($normalized, 'cancel')
                => IntentType::BookingCancel->value,
            str_contains($normalized, 'konfirmasi booking')
                || str_contains($normalized, 'booking confirm')
                => IntentType::KonfirmasiBooking->value,
            str_contains($normalized, 'confirm')
                => IntentType::BookingConfirm->value,
            str_contains($normalized, 'ubah')
                || str_contains($normalized, 'change')
                || str_contains($normalized, 'edit')
                => IntentType::UbahDataBooking->value,
            str_contains($normalized, 'today')
                || str_contains($normalized, 'hari ini')
                => IntentType::TanyaKeberangkatanHariIni->value,
            str_contains($normalized, 'price')
                || str_contains($normalized, 'harga')
                || str_contains($normalized, 'tarif')
                => IntentType::TanyaHarga->value,
            str_contains($normalized, 'route')
                || str_contains($normalized, 'rute')
                || str_contains($normalized, 'lokasi')
                => IntentType::TanyaRute->value,
            str_contains($normalized, 'jadwal')
                || str_contains($normalized, 'schedule')
                || str_contains($normalized, 'jam')
                => IntentType::TanyaJam->value,
            str_contains($normalized, 'close')
                || str_contains($normalized, 'bye')
                || str_contains($normalized, 'farewell')
                || str_contains($normalized, 'terima kasih')
                => IntentType::CloseIntent->value,
            str_contains($normalized, 'tidak terjawab')
                || str_contains($normalized, 'fallback')
                => IntentType::PertanyaanTidakTerjawab->value,
            str_contains($normalized, 'human')
                || str_contains($normalized, 'agent')
                || str_contains($normalized, 'admin')  => IntentType::HumanHandoff->value,
            default => IntentType::Unknown->value,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeIntentResult(array $result, array $context = []): array
    {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];

        $intent = is_string($result['intent'] ?? null)
            ? $this->normalizeIntent((string) $result['intent'])
            : IntentType::Unknown->value;

        $confidence = is_numeric($result['confidence'] ?? null)
            ? max(0, min(1, (float) $result['confidence']))
            : 0.5;

        $shouldEscalate = (bool) ($result['should_escalate'] ?? false);

        if (($conversation['needs_human'] ?? false) === true || ($flags['needs_human_followup'] ?? false) === true) {
            $shouldEscalate = true;
        }

        $entities = is_array($result['entities'] ?? null) ? $result['entities'] : [];
        $reasoning = is_string($result['reasoning_short'] ?? null)
            ? trim((string) $result['reasoning_short'])
            : null;

        return [
            'intent' => $intent !== '' ? $intent : IntentType::Unknown->value,
            'confidence' => $confidence,
            'should_escalate' => $shouldEscalate,
            'entities' => $entities,
            'reasoning_short' => $reasoning,
            'source' => 'llm_with_crm_context',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildIntentInput(array $context): array
    {
        $latestMessage = trim((string) ($context['latest_message'] ?? $context['message_text'] ?? ''));
        $recentHistory = $this->normalizeRecentHistory(
            is_array($context['recent_history'] ?? null)
                ? $context['recent_history']
                : (is_array($context['context_messages'] ?? null) ? $context['context_messages'] : [])
        );
        $conversationState = is_array($context['conversation_state'] ?? null)
            ? $context['conversation_state']
            : (is_array($context['active_states'] ?? null) ? $context['active_states'] : []);

        return [
            'latest_message' => $latestMessage,
            'message_text' => $latestMessage,
            'recent_history' => $recentHistory,
            'recent_messages' => $recentHistory,
            'context_messages' => $recentHistory,
            'conversation_state' => $conversationState,
            'active_states' => $conversationState,
            'known_entities' => is_array($context['known_entities'] ?? null) ? $context['known_entities'] : [],
            'conversation_summary' => $context['conversation_summary'] ?? null,
            'customer_memory' => is_array($context['customer_memory'] ?? null) ? $context['customer_memory'] : [],
            'crm_context' => is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [],
            'admin_takeover' => (bool) ($context['admin_takeover'] ?? false),
        ];
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRecentHistory(array $messages): array
    {
        return array_values(array_filter(array_map(function ($message): ?array {
            if (! is_array($message)) {
                return null;
            }

            $text = trim((string) ($message['text'] ?? ''));
            $direction = trim((string) ($message['direction'] ?? ''));
            $role = trim((string) ($message['role'] ?? ''));

            if ($direction === '' && $role !== '') {
                $direction = $role === 'customer' || $role === 'user'
                    ? 'inbound'
                    : 'outbound';
            }

            return [
                'direction' => $direction !== '' ? $direction : 'inbound',
                'text' => $text,
                'sent_at' => isset($message['sent_at']) ? (string) $message['sent_at'] : null,
            ];
        }, $messages)));
    }
}
