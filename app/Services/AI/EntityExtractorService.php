<?php

namespace App\Services\AI;

use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class EntityExtractorService
{
    /**
     * Entity fields that must be present in a valid extraction result.
     *
     * @var array<int, string>
     */
    private const REQUIRED_FIELDS = [
        'customer_name',
        'pickup_location',
        'destination',
        'departure_date',
        'departure_time',
        'passenger_count',
        'notes',
        'missing_fields',
    ];

    /**
     * Canonical safe default — all nulls, no missing fields.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_RESULT = [
        'customer_name'   => null,
        'pickup_location' => null,
        'destination'     => null,
        'departure_date'  => null,
        'departure_time'  => null,
        'passenger_count' => null,
        'notes'           => null,
        'missing_fields'  => [],
    ];

    public function __construct(
        private readonly LlmClientService          $llmClient,
        private readonly PromptBuilderService      $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * Extract travel entities from the current message and conversation context.
     *
     * Required context keys: message_text, conversation_id, message_id.
     * Optional context keys: customer_memory, active_states, recent_messages, intent_result.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function extract(array $context): array
    {
        try {
            // 1. Build prompts
            $prompts = $this->promptBuilder->buildExtractionPrompt($context);

            // 2. Assemble LLM context
            $llmContext = array_merge($context, [
                'system' => $prompts['system'],
                'user'   => $prompts['user'],
                'model'  => config('chatbot.llm.models.extraction'),
            ]);

            // 3. Call LLM
            $raw = $this->llmClient->extractEntities($llmContext);

            // 4. Validate and fill defaults for missing optional keys
            $validated = $this->validator->validateAndFill(
                data         : $raw,
                requiredKeys : [],                  // No field is strictly required to be present
                defaults     : self::DEFAULT_RESULT,
            );

            if ($validated === null) {
                return self::DEFAULT_RESULT;
            }

            // 5. Sanitize types
            return $this->sanitize($validated);
        } catch (\Throwable $e) {
            Log::error('EntityExtractorService: unexpected error', ['error' => $e->getMessage()]);
            return self::DEFAULT_RESULT;
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Enforce expected types on extracted fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        // Ensure passenger_count is integer or null
        if (isset($data['passenger_count']) && ! is_int($data['passenger_count'])) {
            $casted = filter_var($data['passenger_count'], FILTER_VALIDATE_INT);
            $data['passenger_count'] = $casted !== false ? $casted : null;
        }

        // Ensure missing_fields is always an array
        if (! is_array($data['missing_fields'] ?? null)) {
            $data['missing_fields'] = [];
        }

        // Strip any blank string values → null (LLM sometimes returns empty strings)
        foreach (['customer_name', 'pickup_location', 'destination', 'departure_date', 'departure_time', 'notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
