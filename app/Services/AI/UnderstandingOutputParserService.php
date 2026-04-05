<?php

namespace App\Services\AI;

use App\Data\AI\LlmUnderstandingEntities;
use App\Data\AI\LlmUnderstandingResult;
use App\Enums\IntentType;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class UnderstandingOutputParserService
{
    private const GENERIC_CLARIFICATION_QUESTION = 'Boleh dijelaskan lagi kebutuhan perjalanannya?';

    /**
     * @var array<int, string>
     */
    private const ENTITY_KEYS = [
        'origin',
        'destination',
        'travel_date',
        'departure_time',
        'passenger_count',
        'passenger_name',
        'seat_number',
        'payment_method',
    ];

    public function __construct(
        private readonly JsonSchemaValidatorService $validator,
    ) {
    }

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @param  array<int, string>  $allowedIntents
     * @param  array<string, mixed>  $metadata
     */
    public function parse(
        array|string|null $payload,
        array $allowedIntents = [],
        array $metadata = [],
    ): LlmUnderstandingResult
    {
        $normalizedAllowedIntents = $this->normalizeAllowedIntents($allowedIntents);
        $fallbackIntent = $this->fallbackIntent($normalizedAllowedIntents);
        $runtimeMeta = $this->normalizeLlmRuntimeMeta($metadata['llm_runtime'] ?? []);

        $data = $this->decodePayload($payload);

        if ($data === []) {
            return LlmUnderstandingResult::fallback(
                intent: $fallbackIntent,
                reasoningSummary: $this->buildFallbackReasoningSummary(
                    base: $this->buildRuntimeAwareFallbackBase($runtimeMeta),
                    metadata: array_merge($metadata, [
                        'model' => $runtimeMeta['model'] ?? ($metadata['model'] ?? null),
                    ]),
                ),
            );
        }

        $intent = $this->normalizeIntent($data['intent'] ?? null, $normalizedAllowedIntents);
        $confidence = $this->normalizeConfidence($data['confidence'] ?? null);
        $entities = $this->normalizeEntities($data);
        $needsClarification = $this->normalizeBoolean($data['needs_clarification'] ?? null);
        $clarificationQuestion = $this->normalizeClarificationQuestion(
            $data['clarification_question'] ?? null,
            $needsClarification,
        );
        $reasoningSummary = $this->normalizeReasoningSummary(
            $data['reasoning_summary']
                ?? $data['reasoning_short']
                ?? null
        );

        [$confidence, $needsClarification, $clarificationQuestion] = $this->applyRuntimeSafetyAdjustments(
            confidence: $confidence,
            needsClarification: $needsClarification,
            clarificationQuestion: $clarificationQuestion,
            runtimeMeta: $runtimeMeta,
        );

        $reasoningSummary = $this->appendRuntimeNotesToReasoningSummary(
            summary: $reasoningSummary,
            runtimeMeta: $runtimeMeta,
        );

        $reasoningSummary = $this->enrichReasoningSummaryWithMetadata(
            summary: $reasoningSummary,
            metadata: array_merge($metadata, [
                'model' => $runtimeMeta['model'] ?? ($metadata['model'] ?? null),
            ]),
        );

        if ($intent === $fallbackIntent && $confidence <= 0.30 && $clarificationQuestion === null) {
            $needsClarification = true;
            $clarificationQuestion = self::GENERIC_CLARIFICATION_QUESTION;
        }

        return new LlmUnderstandingResult(
            intent: $intent,
            subIntent: $this->normalizeSubIntent($data['sub_intent'] ?? null),
            confidence: $confidence,
            usesPreviousContext: $this->normalizeBoolean($data['uses_previous_context'] ?? null),
            entities: $entities,
            needsClarification: $needsClarification,
            clarificationQuestion: $clarificationQuestion,
            handoffRecommended: $this->normalizeBoolean(
                $data['handoff_recommended']
                    ?? $data['should_escalate']
                    ?? null
            ),
            reasoningSummary: $reasoningSummary,
        );
    }

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @return array<string, mixed>
     */
    private function decodePayload(array|string|null $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = $this->validator->decodeAndValidate(trim($payload));
        if (is_array($decoded)) {
            return $decoded;
        }

        $embeddedJson = $this->extractFirstJsonObject($payload);
        if ($embeddedJson !== null) {
            $embeddedDecoded = $this->validator->decodeAndValidate($embeddedJson);
            if (is_array($embeddedDecoded)) {
                return $embeddedDecoded;
            }
        }

        return $this->parseKeyValueFallback($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizeEntities(array $data): LlmUnderstandingEntities
    {
        $entities = is_array($data['entities'] ?? null) ? $data['entities'] : [];

        foreach (self::ENTITY_KEYS as $key) {
            if (! array_key_exists($key, $entities) && array_key_exists($key, $data)) {
                $entities[$key] = $data[$key];
            }
        }

        return new LlmUnderstandingEntities(
            origin: $this->normalizeNullableString($entities['origin'] ?? null),
            destination: $this->normalizeNullableString($entities['destination'] ?? null),
            travelDate: $this->normalizeDate($entities['travel_date'] ?? null),
            departureTime: $this->normalizeTime($entities['departure_time'] ?? null),
            passengerCount: $this->normalizeInteger($entities['passenger_count'] ?? null),
            passengerName: $this->normalizeNullableString($entities['passenger_name'] ?? null),
            seatNumber: $this->normalizeSeatNumber($entities['seat_number'] ?? null),
            paymentMethod: $this->normalizeNullableString($entities['payment_method'] ?? null),
        );
    }

    /**
     * @param  array<int, string>  $allowedIntents
     */
    private function normalizeIntent(mixed $value, array $allowedIntents): string
    {
        $candidate = $this->normalizeIntentToken($value);
        $fallback = $this->fallbackIntent($allowedIntents);

        if ($candidate === null) {
            return $fallback;
        }

        if ($allowedIntents === [] || in_array($candidate, $allowedIntents, true)) {
            return $candidate;
        }

        $enum = IntentType::tryFrom($candidate);
        if ($enum !== null && in_array($enum->value, $allowedIntents, true)) {
            return $enum->value;
        }

        return $fallback;
    }

    private function normalizeIntentToken(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->trim()
            ->lower()
            ->replace(['-', ' '], '_')
            ->value();

        if ($normalized === '') {
            return null;
        }

        $aliases = [
            'book' => IntentType::Booking->value,
            'booking_request' => IntentType::Booking->value,
            'booking_confirmation' => IntentType::BookingConfirm->value,
            'confirm_booking' => IntentType::KonfirmasiBooking->value,
            'change_booking' => IntentType::UbahDataBooking->value,
            'edit_booking' => IntentType::UbahDataBooking->value,
            'price' => IntentType::PriceInquiry->value,
            'price_question' => IntentType::PriceInquiry->value,
            'route' => IntentType::LocationInquiry->value,
            'location' => IntentType::LocationInquiry->value,
            'schedule' => IntentType::ScheduleInquiry->value,
            'farewell_message' => IntentType::Farewell->value,
            'closing' => IntentType::CloseIntent->value,
            'handoff' => IntentType::HumanHandoff->value,
            'human' => IntentType::HumanHandoff->value,
            'admin' => IntentType::HumanHandoff->value,
            'unanswered_question' => IntentType::PertanyaanTidakTerjawab->value,
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (is_string($value) && str_ends_with(trim($value), '%')) {
            $numeric = rtrim(trim($value), '%');
            if (is_numeric($numeric)) {
                return $this->validator->clampConfidence(((float) $numeric) / 100);
            }
        }

        return $this->validator->clampConfidence($value);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(
            strtolower(trim($value)),
            ['1', 'true', 'yes', 'y', 'ya', 'iya'],
            true,
        );
    }

    private function normalizeSubIntent(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->trim()
            ->lower()
            ->replace(['-', ' '], '_')
            ->value();

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeReasoningSummary(mixed $value): string
    {
        $summary = $this->normalizeNullableString($value);

        if ($summary === null) {
            return 'Reasoning summary tidak tersedia.';
        }

        return Str::limit($summary, 180, '...');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function buildFallbackReasoningSummary(string $base, array $metadata = []): string
    {
        $mode = $this->normalizeNullableString($metadata['understanding_mode'] ?? null);
        $model = $this->normalizeNullableString($metadata['model'] ?? null);

        $suffix = [];

        if ($mode !== null) {
            $suffix[] = 'mode='.$mode;
        }

        if ($model !== null) {
            $suffix[] = 'model='.$model;
        }

        if ($suffix === []) {
            return $base;
        }

        return Str::limit($base.' ['.implode(', ', $suffix).']', 180, '...');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function enrichReasoningSummaryWithMetadata(string $summary, array $metadata = []): string
    {
        $mode = $this->normalizeNullableString($metadata['understanding_mode'] ?? null);

        if ($mode === null) {
            return $summary;
        }

        if (Str::contains($summary, 'mode=')) {
            return $summary;
        }

        return Str::limit($summary.' [mode='.$mode.']', 180, '...');
    }

    private function normalizeClarificationQuestion(mixed $value, bool $needsClarification): ?string
    {
        $question = $this->normalizeNullableString($value);

        if (! $needsClarification) {
            return null;
        }

        return $question ?? self::GENERIC_CLARIFICATION_QUESTION;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $casted = (int) trim($value);

            return $casted > 0 ? $casted : null;
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $string = $this->normalizeNullableString($value);

        if ($string === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1) {
            return $string;
        }

        try {
            return Carbon::parse($string)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(mixed $value): ?string
    {
        $string = $this->normalizeNullableString($value);

        if ($string === null) {
            return null;
        }

        if (preg_match('/^(?<hour>[01]?\d|2[0-3])[:.](?<minute>[0-5]\d)$/', $string, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches['hour'], (int) $matches['minute']);
        }

        if (preg_match('/^(?<hour>[01]?\d|2[0-3])$/', $string, $matches) === 1) {
            return sprintf('%02d:00', (int) $matches['hour']);
        }

        return null;
    }

    private function normalizeSeatNumber(mixed $value): ?string
    {
        if (is_array($value)) {
            $values = array_values(array_filter(array_map(
                fn (mixed $item): ?string => $this->normalizeNullableString($item),
                $value,
            )));

            return $values !== [] ? implode(', ', $values) : null;
        }

        return $this->normalizeNullableString($value);
    }

    /**
     * @param  array<int, string>  $allowedIntents
     */
    private function fallbackIntent(array $allowedIntents): string
    {
        if ($allowedIntents === []) {
            return IntentType::Unknown->value;
        }

        if (in_array(IntentType::Unknown->value, $allowedIntents, true)) {
            return IntentType::Unknown->value;
        }

        return $allowedIntents[0];
    }

    /**
     * @param  array<int, string>  $allowedIntents
     * @return array<int, string>
     */
    private function normalizeAllowedIntents(array $allowedIntents): array
    {
        if ($allowedIntents === []) {
            return array_map(
                static fn (IntentType $intent): string => $intent->value,
                IntentType::cases(),
            );
        }

        $normalized = [];

        foreach ($allowedIntents as $intent) {
            $token = $this->normalizeIntentToken($intent);

            if ($token !== null && ! in_array($token, $normalized, true)) {
                $normalized[] = $token;
            }
        }

        return $normalized;
    }

    private function extractFirstJsonObject(string $payload): ?string
    {
        $length = strlen($payload);
        $depth = 0;
        $start = null;
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $payload[$i];

            if ($char === '"' && ! $escape) {
                $inString = ! $inString;
            }

            if ($inString) {
                $escape = $char === '\\' && ! $escape;
                continue;
            }

            if ($char === '{') {
                $depth++;
                $start ??= $i;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0 && $start !== null) {
                    return substr($payload, $start, ($i - $start) + 1);
                }
            }

            $escape = false;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseKeyValueFallback(string $payload): array
    {
        $result = [];

        foreach (preg_split('/\r\n|\r|\n/', $payload) ?: [] as $line) {
            if (preg_match('/^\s*"?([a-zA-Z_]+)"?\s*:\s*(.+)\s*$/', trim($line), $matches) !== 1) {
                continue;
            }

            $key = Str::snake(trim((string) $matches[1]));
            $rawValue = trim((string) $matches[2]);
            $rawValue = trim($rawValue, "\",'");

            if ($key === 'entities') {
                continue;
            }

            $result[$key] = $rawValue;
        }

        return $result;
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private function normalizeLlmRuntimeMeta(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return [
            'provider' => $this->normalizeNullableString($value['provider'] ?? null),
            'task_key' => $this->normalizeNullableString($value['task_key'] ?? null),
            'task_type' => $this->normalizeNullableString($value['task_type'] ?? null),
            'model' => $this->normalizeNullableString($value['model'] ?? null),
            'primary_model' => $this->normalizeNullableString($value['primary_model'] ?? null),
            'fallback_model' => $this->normalizeNullableString($value['fallback_model'] ?? null),
            'used_fallback_model' => $this->normalizeBoolean($value['used_fallback_model'] ?? null),
            'status' => $this->normalizeNullableString($value['status'] ?? null),
            'degraded_mode' => $this->normalizeBoolean($value['degraded_mode'] ?? null),
            'cache_hit' => $this->normalizeBoolean($value['cache_hit'] ?? null),
            'schema_valid' => array_key_exists('schema_valid', $value)
                ? $this->normalizeBoolean($value['schema_valid'])
                : true,
            'latency_ms' => $this->normalizeInteger($value['latency_ms'] ?? null),
            'http_status' => $this->normalizeInteger($value['http_status'] ?? null),
            'attempt' => $this->normalizeInteger($value['attempt'] ?? null),
            'max_attempts' => $this->normalizeInteger($value['max_attempts'] ?? null),
            'fallback_reason' => $this->normalizeNullableString($value['fallback_reason'] ?? null),
            'error_message' => $this->normalizeNullableString($value['error_message'] ?? null),
            'reasoning_effort' => $this->normalizeNullableString($value['reasoning_effort'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeMeta
     */
    private function buildRuntimeAwareFallbackBase(array $runtimeMeta): string
    {
        $status = $this->normalizeNullableString($runtimeMeta['status'] ?? null);
        $reason = $this->normalizeNullableString(
            $runtimeMeta['fallback_reason'] ?? $runtimeMeta['error_message'] ?? null
        );

        $base = 'Fallback understanding digunakan karena payload model tidak dapat diparse.';

        if ($status === 'fallback') {
            $base = 'Fallback understanding digunakan karena runtime LLM masuk mode fallback.';
        }

        if ($reason !== null) {
            return Str::limit($base.' reason='.$reason, 180, '...');
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $runtimeMeta
     * @return array{0: float, 1: bool, 2: string|null}
     */
    private function applyRuntimeSafetyAdjustments(
        float $confidence,
        bool $needsClarification,
        ?string $clarificationQuestion,
        array $runtimeMeta,
    ): array {
        $degradedMode = (bool) ($runtimeMeta['degraded_mode'] ?? false);
        $schemaValid = ! array_key_exists('schema_valid', $runtimeMeta) || (bool) $runtimeMeta['schema_valid'];
        $status = $this->normalizeNullableString($runtimeMeta['status'] ?? null);

        if ($degradedMode) {
            $confidence = min($confidence, 0.55);
        }

        if (! $schemaValid) {
            $confidence = min($confidence, 0.35);
            $needsClarification = true;
        }

        if ($status === 'fallback') {
            $confidence = min($confidence, 0.25);
            $needsClarification = true;
        }

        if ($needsClarification && $clarificationQuestion === null) {
            $clarificationQuestion = self::GENERIC_CLARIFICATION_QUESTION;
        }

        return [$confidence, $needsClarification, $clarificationQuestion];
    }

    /**
     * @param  array<string, mixed>  $runtimeMeta
     */
    private function appendRuntimeNotesToReasoningSummary(
        string $summary,
        array $runtimeMeta,
    ): string {
        $notes = [];

        if (($runtimeMeta['used_fallback_model'] ?? false) === true) {
            $notes[] = 'fallback_model';
        }

        if (($runtimeMeta['degraded_mode'] ?? false) === true) {
            $notes[] = 'degraded_mode';
        }

        if (($runtimeMeta['schema_valid'] ?? true) === false) {
            $notes[] = 'schema_invalid';
        }

        if (($runtimeMeta['cache_hit'] ?? false) === true) {
            $notes[] = 'cache_hit';
        }

        if ($notes === []) {
            return $summary;
        }

        return Str::limit($summary.' ['.implode(', ', $notes).']', 180, '...');
    }
}
