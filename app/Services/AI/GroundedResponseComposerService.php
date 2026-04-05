<?php

namespace App\Services\AI;

use App\Data\AI\GroundedResponseFacts;
use App\Data\AI\GroundedResponseResult;
use App\Enums\GroundedResponseMode;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GroundedResponseComposerService
{
    /**
     * @var array<string, mixed>
     */
    private array $lastRuntimeMeta = [];

    public function __construct(
        private readonly LlmClientService $llmClient,
        private readonly GroundedResponsePromptBuilderService $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lastRuntimeMeta(): array
    {
        return $this->lastRuntimeMeta;
    }

    public function compose(GroundedResponseFacts $facts): GroundedResponseResult
    {
        $this->lastRuntimeMeta = [];

        try {
            $prompts = $this->promptBuilder->build($facts);
            $groundedModel = config(
                'openai.tasks.grounded_response.model',
                config('chatbot.llm.models.grounded_response', config('chatbot.llm.models.reply'))
            );

            $raw = $this->llmClient->composeGroundedResponse([
                'conversation_id' => $facts->conversationId,
                'message_id' => $facts->messageId,
                'message_text' => $facts->latestMessageText,
                'grounded_response_facts' => $facts->toArray(),
                'system' => $prompts['system'],
                'user' => $prompts['user'],
                'model' => $groundedModel,
                'expect_json' => true,
            ]);

            $llmRuntimeMeta = is_array($raw['_llm'] ?? null) ? $raw['_llm'] : [];
            $this->lastRuntimeMeta = $llmRuntimeMeta;

            $validated = $this->validator->validateAndFill(
                is_array($raw) ? $raw : [],
                ['text'],
                ['mode' => $facts->mode->value],
            );

            if ($validated === null) {
                $this->lastRuntimeMeta = array_merge($llmRuntimeMeta, [
                    'status' => $llmRuntimeMeta['status'] ?? 'fallback',
                    'degraded_mode' => true,
                    'schema_valid' => false,
                    'fallback_reason' => $llmRuntimeMeta['fallback_reason'] ?? 'grounded_response_validation_failed',
                ]);

                return $this->fallback($facts);
            }

            $text = trim((string) ($validated['text'] ?? ''));
            $mode = GroundedResponseMode::tryFrom((string) ($validated['mode'] ?? ''))
                ?? $facts->mode;

            if ($text === '') {
                $this->lastRuntimeMeta = array_merge($llmRuntimeMeta, [
                    'status' => $llmRuntimeMeta['status'] ?? 'fallback',
                    'degraded_mode' => true,
                    'fallback_reason' => $llmRuntimeMeta['fallback_reason'] ?? 'grounded_response_empty_text',
                ]);

                return $this->fallback($facts);
            }

            return new GroundedResponseResult(
                text: $text,
                mode: $mode,
                isFallback: false,
            );
        } catch (\Throwable $e) {
            Log::error('GroundedResponseComposerService: unexpected error', [
                'error' => $e->getMessage(),
                'conversation_id' => $facts->conversationId,
                'message_id' => $facts->messageId,
            ]);

            $this->lastRuntimeMeta = array_merge($this->lastRuntimeMeta, [
                'status' => 'fallback',
                'degraded_mode' => true,
                'fallback_reason' => 'grounded_response_exception',
                'error_message' => $e->getMessage(),
            ]);

            return $this->fallback($facts);
        }
    }

    /**
     * @param  array<string, mixed>  $replyDraft
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>|null  $faqResult
     * @return array<string, mixed>
     */
    public function composeGroundedReply(
        array $replyDraft,
        array $context,
        array $intentResult = [],
        array $orchestrationSnapshot = [],
        array $knowledgeHits = [],
        ?array $faqResult = null,
    ): array {
        $reply = trim((string) ($replyDraft['reply'] ?? $replyDraft['text'] ?? ''));
        $usedFacts = is_array($replyDraft['used_crm_facts'] ?? null) ? $replyDraft['used_crm_facts'] : [];
        $traceId = $this->resolveTraceId($replyDraft, $context, $intentResult, $orchestrationSnapshot);
        $llmRuntime = $this->normalizeRuntimeMeta(
            $replyDraft['meta']['llm_runtime'] ?? $context['grounded_response_runtime'] ?? []
        );

        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $customer = is_array($crm['customer'] ?? null) ? $crm['customer'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $lead = is_array($crm['lead_pipeline'] ?? null) ? $crm['lead_pipeline'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];

        $groundingNotes = [];
        $crmGroundingSections = [];

        foreach ([
            'customer' => $customer,
            'conversation' => $conversation,
            'booking' => $booking,
            'lead_pipeline' => $lead,
            'business_flags' => $flags,
        ] as $section => $value) {
            if (! empty($value)) {
                $crmGroundingSections[] = $section;
                $usedFacts[] = 'crm.'.$section;
            }
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $groundingNotes[] = 'Reply constrained by booking missing fields';
        }

        if (($flags['admin_takeover_active'] ?? false) === true) {
            $groundingNotes[] = 'Reply constrained by admin takeover';
        }

        if (! empty($context['conversation_summary'])) {
            $groundingNotes[] = 'Conversation summary grounding used';
        }

        if (! empty($context['customer_memory'])) {
            $groundingNotes[] = 'Customer memory grounding used';
        }

        if ($faqResult !== null && ! empty($faqResult['matched'])) {
            $groundingNotes[] = 'FAQ grounding used';
        }

        if ($knowledgeHits !== []) {
            $groundingNotes[] = 'Knowledge grounding used';
        }

        if (($orchestrationSnapshot['reply_force_handoff'] ?? false) === true) {
            $groundingNotes[] = 'Reply constrained by orchestration handoff flag';
        }

        if (($llmRuntime['used_fallback_model'] ?? false) === true) {
            $groundingNotes[] = 'Grounded response used fallback model';
        }

        if (($llmRuntime['degraded_mode'] ?? false) === true) {
            $groundingNotes[] = 'Grounded response runtime degraded';
        }

        if (($llmRuntime['schema_valid'] ?? true) === false) {
            $groundingNotes[] = 'Grounded response schema invalid';
        }

        if ($reply === '') {
            $reply = 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.';
            $groundingNotes[] = 'Empty draft replaced with grounded fallback';
        }

        $replyDraft['reply'] = $reply;
        $replyDraft['text'] = $reply;
        $replyDraft['used_crm_facts'] = array_values(array_unique(array_filter($usedFacts)));
        $replyDraft['grounding_notes'] = array_values(array_unique(array_filter(array_merge(
            is_array($replyDraft['grounding_notes'] ?? null) ? $replyDraft['grounding_notes'] : [],
            $groundingNotes,
        ))));
        $replyDraft['message_type'] = $replyDraft['message_type'] ?? 'text';
        $replyDraft['outbound_payload'] = is_array($replyDraft['outbound_payload'] ?? null) ? $replyDraft['outbound_payload'] : [];

        $existingMeta = is_array($replyDraft['meta'] ?? null) ? $replyDraft['meta'] : [];
        $groundingSource = $this->detectGroundingSource($faqResult, $knowledgeHits, $crm);

        $replyDraft['meta'] = array_merge(
            $existingMeta,
            [
                'trace_id' => $traceId,
                'grounded' => true,
                'grounding_source' => $groundingSource,
                'crm_grounding_sections' => $crmGroundingSections,
                'llm_runtime' => $llmRuntime,
                'runtime_health' => $this->resolveRuntimeHealth($llmRuntime),
                'decision_trace' => $this->mergeDecisionTrace(
                    is_array($existingMeta['decision_trace'] ?? null) ? $existingMeta['decision_trace'] : [],
                    [
                        'trace_id' => $traceId,
                        'grounding' => [
                            'stage' => 'grounded_response_composer',
                            'grounded' => true,
                            'grounding_source' => $groundingSource,
                            'crm_grounding_sections' => $crmGroundingSections,
                            'used_crm_facts' => array_values(array_unique(array_filter($replyDraft['used_crm_facts'] ?? []))),
                            'knowledge_hit_count' => count($knowledgeHits),
                            'faq_matched' => (bool) (($faqResult['matched'] ?? false) === true),
                            'notes' => array_values(array_unique(array_filter($replyDraft['grounding_notes'] ?? []))),
                            'evaluated_at' => now()->toIso8601String(),
                            'runtime_health' => $this->resolveRuntimeHealth($llmRuntime),
                            'model_used' => $llmRuntime['model'],
                            'provider' => $llmRuntime['provider'],
                            'runtime_status' => $llmRuntime['status'],
                            'degraded_mode' => (bool) ($llmRuntime['degraded_mode'] ?? false),
                            'used_fallback_model' => (bool) ($llmRuntime['used_fallback_model'] ?? false),
                            'schema_valid' => (bool) ($llmRuntime['schema_valid'] ?? true),
                            'cache_hit' => (bool) ($llmRuntime['cache_hit'] ?? false),
                            'latency_ms' => $llmRuntime['latency_ms'],
                            'http_status' => $llmRuntime['http_status'],
                        ],
                        'outcome' => [
                            'final_decision' => $existingMeta['decision_source'] ?? $existingMeta['source'] ?? 'grounded_response',
                            'reply_action' => $replyDraft['next_action'] ?? null,
                            'handoff' => (bool) ($replyDraft['should_escalate'] ?? false),
                            'handoff_reason' => $replyDraft['handoff_reason'] ?? null,
                            'is_fallback' => (bool) ($replyDraft['is_fallback'] ?? false),
                        ],
                    ],
                ),
            ],
        );

        return $replyDraft;
    }

    private function fallback(GroundedResponseFacts $facts): GroundedResponseResult
    {
        $officialFacts = $facts->officialFacts;

        $text = match ($facts->mode) {
            GroundedResponseMode::ClarificationQuestion => $this->clarificationFallback($facts),
            GroundedResponseMode::BookingContinuation => $this->bookingContinuationFallback($facts),
            GroundedResponseMode::PoliteRefusal => $this->politeRefusalFallback($facts),
            GroundedResponseMode::HandoffMessage => 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya. Mohon tunggu sebentar.',
            GroundedResponseMode::DirectAnswer => $this->directAnswerFallback($facts),
        };

        return new GroundedResponseResult(
            text: $text,
            mode: $facts->mode,
            isFallback: true,
        );
    }

    /**
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>  $crm
     */
    private function detectGroundingSource(?array $faqResult, array $knowledgeHits, array $crm): string
    {
        if ($faqResult !== null && ! empty($faqResult['matched']) && $knowledgeHits !== [] && $crm !== []) {
            return 'faq+knowledge+crm';
        }

        if ($faqResult !== null && ! empty($faqResult['matched']) && $knowledgeHits !== []) {
            return 'faq+knowledge';
        }

        if ($faqResult !== null && ! empty($faqResult['matched'])) {
            return 'faq+crm';
        }

        if ($knowledgeHits !== []) {
            return 'knowledge+crm';
        }

        if ($crm !== []) {
            return 'crm';
        }

        return 'fallback';
    }

    private function directAnswerFallback(GroundedResponseFacts $facts): string
    {
        $verifiedAnswer = is_array($facts->officialFacts['verified_answer'] ?? null)
            ? $facts->officialFacts['verified_answer']
            : null;
        if ($verifiedAnswer !== null && ! empty($verifiedAnswer['text'])) {
            return (string) $verifiedAnswer['text'];
        }

        $requestedSchedule = is_array($facts->officialFacts['requested_schedule'] ?? null)
            ? $facts->officialFacts['requested_schedule']
            : [];
        $destination = $facts->entityResult['destination'] ?? $facts->officialFacts['route']['destination'] ?? null;
        $hasIslamicGreeting = preg_match('/ass?alamu[\'’ ]?alaikum/iu', $facts->latestMessageText) === 1;

        if (($requestedSchedule['available'] ?? null) === true) {
            $dateLabel = $this->dateLabel($requestedSchedule['travel_date'] ?? null);
            $timeLabel = $this->timeLabel($requestedSchedule['travel_time'] ?? null);
            $destinationLabel = is_string($destination) && trim($destination) !== '' ? ' ke '.$destination : '';

            return trim(($hasIslamicGreeting ? 'Waalaikumsalam Bapak/Ibu, ' : 'Baik Bapak/Ibu, ').'untuk keberangkatan'.$dateLabel.$destinationLabel.', jadwal pukul '.$timeLabel.' tersedia. Jika ingin, saya bisa bantu lanjut bookingnya.');
        }

        return 'Baik Bapak/Ibu, saya bantu jawab berdasarkan data yang tersedia ya.';
    }

    private function clarificationFallback(GroundedResponseFacts $facts): string
    {
        $question = $facts->intentResult['clarification_question'] ?? $facts->officialFacts['suggested_follow_up'] ?? null;

        return is_string($question) && trim($question) !== ''
            ? trim($question)
            : 'Izin Bapak/Ibu, boleh dijelaskan lagi detail perjalanan yang ingin dicek ya?';
    }

    private function bookingContinuationFallback(GroundedResponseFacts $facts): string
    {
        $expectedInput = $facts->officialFacts['booking_context']['expected_input'] ?? null;

        return match ($expectedInput) {
            'passenger_count' => 'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya ya?',
            'travel_date' => 'Izin Bapak/Ibu, tanggal keberangkatannya kapan ya?',
            'travel_time' => 'Izin Bapak/Ibu, jam keberangkatannya yang diinginkan jam berapa ya?',
            'selected_seats' => 'Izin Bapak/Ibu, mohon pilih seat yang diinginkan ya.',
            'pickup_location' => 'Izin Bapak/Ibu, lokasi jemputnya di mana ya?',
            'pickup_full_address' => 'Izin Bapak/Ibu, mohon dibantu alamat jemput lengkapnya ya.',
            'destination' => 'Izin Bapak/Ibu, tujuan pengantarannya ke mana ya?',
            'passenger_name' => 'Izin Bapak/Ibu, mohon dibantu nama penumpangnya ya.',
            'contact_number' => 'Izin Bapak/Ibu, mohon dibantu nomor kontak penumpangnya ya.',
            default => 'Baik Bapak/Ibu, jika berkenan saya bisa bantu lanjutkan bookingnya ya.',
        };
    }

    private function politeRefusalFallback(GroundedResponseFacts $facts): string
    {
        $route = is_array($facts->officialFacts['route'] ?? null) ? $facts->officialFacts['route'] : [];
        if (($route['supported'] ?? null) === false) {
            return 'Izin Bapak/Ibu, untuk rute tersebut saat ini belum tersedia. Jika berkenan, silakan kirim rute lain yang ingin dicek ya.';
        }

        $requestedSchedule = is_array($facts->officialFacts['requested_schedule'] ?? null)
            ? $facts->officialFacts['requested_schedule']
            : [];
        if (($requestedSchedule['available'] ?? null) === false) {
            return 'Izin Bapak/Ibu, jam yang dimaksud saat ini belum tersedia. Jika berkenan, silakan pilih jam keberangkatan lain ya.';
        }

        return 'Izin Bapak/Ibu, untuk permintaan tersebut saat ini belum bisa kami penuhi. Jika berkenan, saya bantu cek opsi lain yang tersedia ya.';
    }

    private function dateLabel(mixed $date): string
    {
        if (! is_string($date) || trim($date) === '') {
            return '';
        }

        return ' '.trim($date);
    }

    private function timeLabel(mixed $time): string
    {
        if (! is_string($time) || trim($time) === '') {
            return '-';
        }

        return str_replace(':', '.', trim($time));
    }

    private function resolveTraceId(array ...$sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                $source['trace_id'] ?? null,
                $source['meta']['trace_id'] ?? null,
                $source['decision_trace']['trace_id'] ?? null,
                $source['job_trace_id'] ?? null,
            ] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return trim((string) $candidate);
                }
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    /**
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private function normalizeRuntimeMeta(mixed $value): array
    {
        if (! is_array($value)) {
            return [
                'provider' => null,
                'model' => null,
                'status' => null,
                'degraded_mode' => false,
                'used_fallback_model' => false,
                'schema_valid' => true,
                'cache_hit' => false,
                'latency_ms' => null,
                'http_status' => null,
                'attempt' => null,
                'max_attempts' => null,
                'fallback_reason' => null,
                'error_message' => null,
            ];
        }

        return [
            'provider' => $this->normalizeNullableString($value['provider'] ?? null),
            'model' => $this->normalizeNullableString($value['model'] ?? ($value['primary_model'] ?? null)),
            'status' => $this->normalizeNullableString($value['status'] ?? null),
            'degraded_mode' => (bool) ($value['degraded_mode'] ?? false),
            'used_fallback_model' => (bool) ($value['used_fallback_model'] ?? false),
            'schema_valid' => array_key_exists('schema_valid', $value) ? (bool) $value['schema_valid'] : true,
            'cache_hit' => (bool) ($value['cache_hit'] ?? false),
            'latency_ms' => isset($value['latency_ms']) ? (int) $value['latency_ms'] : null,
            'http_status' => isset($value['http_status']) ? (int) $value['http_status'] : null,
            'attempt' => isset($value['attempt']) ? (int) $value['attempt'] : null,
            'max_attempts' => isset($value['max_attempts']) ? (int) $value['max_attempts'] : null,
            'fallback_reason' => $this->normalizeNullableString($value['fallback_reason'] ?? null),
            'error_message' => $this->normalizeNullableString($value['error_message'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeMeta
     */
    private function resolveRuntimeHealth(array $runtimeMeta): string
    {
        if (($runtimeMeta['status'] ?? null) === 'fallback') {
            return 'fallback';
        }

        if (($runtimeMeta['schema_valid'] ?? true) === false) {
            return 'schema_invalid';
        }

        if (($runtimeMeta['degraded_mode'] ?? false) === true) {
            return 'degraded';
        }

        if (($runtimeMeta['used_fallback_model'] ?? false) === true) {
            return 'fallback_model';
        }

        if (($runtimeMeta['status'] ?? null) === 'success') {
            return 'healthy';
        }

        return 'unknown';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeDecisionTrace(array $base, array $extra): array
    {
        if (array_is_list($base)) {
            $normalized = [];

            foreach ($base as $part) {
                if (is_array($part)) {
                    $normalized = array_replace_recursive($normalized, $part);
                }
            }

            $base = $normalized;
        }

        $merged = array_replace_recursive($base, $extra);

        if (! isset($merged['trace_id']) || ! is_scalar($merged['trace_id']) || trim((string) $merged['trace_id']) === '') {
            $merged['trace_id'] = $this->resolveTraceId($base, $extra);
        }

        return $merged;
    }
}
