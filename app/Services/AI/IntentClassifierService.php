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
     * @return array{intent: string, confidence: float, reasoning_short: string}
     */
    public function classify(array $context): array
    {
        $defaultResult = [
            'intent'           => IntentType::Unknown->value,
            'confidence'       => 0.0,
            'reasoning_short'  => 'Klasifikasi gagal atau tidak tersedia.',
        ];

        try {
            // 1. Build prompts
            $prompts = $this->promptBuilder->buildIntentPrompt($context);

            // 2. Assemble LLM context
            $llmContext = array_merge($context, [
                'system' => $prompts['system'],
                'user'   => $prompts['user'],
                'model'  => config('chatbot.llm.models.intent'),
            ]);

            // 3. Call LLM
            $raw = $this->llmClient->classifyIntent($llmContext);

            // 4. Validate required keys
            $validated = $this->validator->validateAndFill(
                data         : $raw,
                requiredKeys : ['intent', 'confidence'],
                defaults     : $defaultResult,
            );

            if ($validated === null) {
                Log::warning('IntentClassifierService: missing required keys in LLM output', ['raw' => $raw]);
                return $defaultResult;
            }

            // 5. Normalize intent to valid enum value
            $normalizedIntent = $this->normalizeIntent($validated['intent']);
            $confidence       = $this->validator->clampConfidence($validated['confidence']);

            return [
                'intent'          => $normalizedIntent,
                'confidence'      => $confidence,
                'reasoning_short' => $validated['reasoning_short'] ?? '',
            ];
        } catch (\Throwable $e) {
            Log::error('IntentClassifierService: unexpected error', ['error' => $e->getMessage()]);
            return $defaultResult;
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
}
