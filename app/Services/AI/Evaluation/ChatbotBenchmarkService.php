<?php

namespace App\Services\AI\Evaluation;

use App\Data\AI\GroundedResponseFacts;
use App\Data\AI\LlmUnderstandingResult;
use App\Enums\ConversationStatus;
use App\Enums\GroundedResponseMode;
use App\Enums\IntentType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\GroundedResponseComposerService;
use App\Services\AI\GroundedResponsePromptBuilderService;
use App\Services\AI\LlmClientService;
use App\Services\AI\UnderstandingOutputParserService;
use App\Services\AI\UnderstandingResultAdapterService;
use App\Services\Chatbot\ConversationReplyGuardService;
use App\Services\Chatbot\Guardrails\AdminTakeoverGuardService;
use App\Services\Chatbot\Guardrails\PolicyGuardService;
use App\Services\Chatbot\Guardrails\ReplyLoopGuardService;
use App\Services\Chatbot\Guardrails\UnavailableReplyGuardService;
use App\Services\Support\JsonSchemaValidatorService;
use App\Support\Benchmark\ChatbotBenchmarkCaseRepository;

class ChatbotBenchmarkService
{
    public function __construct(
        private readonly ChatbotBenchmarkCaseRepository $cases,
        private readonly UnderstandingOutputParserService $understandingParser,
        private readonly UnderstandingResultAdapterService $understandingAdapter,
        private readonly GroundedResponsePromptBuilderService $groundedPromptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {
    }

    /**
     * @return array{
     *     total_cases: int,
     *     passed: int,
     *     failed: int,
     *     failure_categories: array<string, int>,
     *     tag_breakdown: array<string, array{cases: int, passed: int, failed: int}>,
     *     fallback_metrics: array{relevant_cases: int, fallback_used: int, fallback_rate_percent: float},
     *     cases: array<int, array<string, mixed>>
     * }
     */
    public function run(?string $category = null): array
    {
        $results = array_map(
            fn (array $case): array => $this->runArrayCase($case),
            $this->cases->all($category),
        );

        return $this->summarize($results);
    }

    /**
     * @return array<string, mixed>
     */
    public function runCase(string $caseId): array
    {
        $case = $this->cases->find($caseId);

        if ($case === null) {
            return [
                'id' => $caseId,
                'description' => 'Case not found.',
                'pipeline' => 'unknown',
                'tags' => [],
                'passed' => false,
                'failure_category' => 'case_not_found',
                'details' => 'Benchmark case tidak ditemukan.',
                'fallback_used' => false,
            ];
        }

        return $this->runArrayCase($case);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function runArrayCase(array $case): array
    {
        return match ($case['pipeline'] ?? null) {
            'grounded_response' => $this->evaluateGroundedResponseCase($case),
            'reply_guard' => $this->evaluateReplyGuardCase($case),
            default => $this->evaluateUnderstandingPolicyCase($case),
        };
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluateUnderstandingPolicyCase(array $case): array
    {
        $allowedIntents = array_map(
            static fn (IntentType $intent): string => $intent->value,
            IntentType::cases(),
        );

        $conversation = $this->makeConversation($case['conversation'] ?? []);
        $understanding = $this->understandingParser->parse($case['llm_output'] ?? null, $allowedIntents);
        $adapted = $this->understandingAdapter->adapt($understanding);

        $policyGuard = new PolicyGuardService(new AdminTakeoverGuardService());
        $policy = $policyGuard->guard(
            conversation: $conversation,
            intentResult: $adapted['intent_result'],
            entityResult: $adapted['entity_result'],
            understandingResult: $understanding->toArray(),
            resolvedContext: is_array($case['resolved_context'] ?? null) ? $case['resolved_context'] : [],
            conversationState: is_array($case['conversation_state'] ?? null) ? $case['conversation_state'] : [],
        );

        [$passed, $failureCategory, $details] = $this->assertUnderstandingPolicyExpectations(
            case: $case,
            understanding: $understanding,
            intentResult: $policy['intent_result'],
            entityResult: $policy['entity_result'],
            policyMeta: $policy['meta'],
        );

        return [
            'id' => $case['id'],
            'description' => $case['description'],
            'pipeline' => $case['pipeline'],
            'tags' => $case['tags'],
            'passed' => $passed,
            'failure_category' => $failureCategory,
            'details' => $details,
            'fallback_used' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluateGroundedResponseCase(array $case): array
    {
        $facts = $this->makeGroundedFacts($case['facts'] ?? []);
        $composer = new GroundedResponseComposerService(
            llmClient: new class($case['llm_response'] ?? []) extends LlmClientService {
                /**
                 * @param  array<string, mixed>  $response
                 */
                public function __construct(private readonly array $response) {}

                public function composeGroundedResponse(array $context): array
                {
                    return $this->response;
                }
            },
            promptBuilder: $this->groundedPromptBuilder,
            validator: $this->validator,
        );

        $result = $composer->compose($facts);

        [$passed, $failureCategory, $details] = $this->assertGroundedResponseExpectations(
            case: $case,
            mode: $result->mode->value,
            text: $result->text,
            isFallback: $result->isFallback,
        );

        return [
            'id' => $case['id'],
            'description' => $case['description'],
            'pipeline' => $case['pipeline'],
            'tags' => $case['tags'],
            'passed' => $passed,
            'failure_category' => $failureCategory,
            'details' => $details,
            'fallback_used' => $result->isFallback,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluateReplyGuardCase(array $case): array
    {
        $stateService = new InMemoryConversationStateService();
        $conversation = $this->makeConversation();

        foreach ((array) ($case['conversation_state'] ?? []) as $key => $value) {
            if (is_string($key)) {
                $stateService->put($conversation, $key, $value);
            }
        }

        $replyGuard = new ConversationReplyGuardService(
            unavailableGuard: new UnavailableReplyGuardService($stateService),
            replyLoopGuard: new ReplyLoopGuardService($stateService),
        );

        $candidateReply = is_array($case['candidate_reply'] ?? null) ? $case['candidate_reply'] : [];
        $candidateInboundContext = is_array($case['candidate_inbound_context'] ?? null)
            ? $case['candidate_inbound_context']
            : [];
        $candidateInboundFingerprint = $replyGuard->buildInboundContextFingerprint(
            messageText: (string) ($candidateInboundContext['message_text'] ?? ''),
            intentResult: is_array($candidateInboundContext['intent_result'] ?? null) ? $candidateInboundContext['intent_result'] : [],
            entityResult: is_array($candidateInboundContext['entity_result'] ?? null) ? $candidateInboundContext['entity_result'] : [],
            resolvedContext: is_array($candidateInboundContext['resolved_context'] ?? null) ? $candidateInboundContext['resolved_context'] : [],
        );
        $candidateIdentity = $replyGuard->buildReplyIdentity($conversation, $candidateReply, $candidateInboundFingerprint);

        $latestOutboundConfig = is_array($case['latest_outbound'] ?? null) ? $case['latest_outbound'] : [];
        $latestInboundContext = (($latestOutboundConfig['same_inbound_context'] ?? false) === true)
            ? $candidateInboundContext
            : (is_array($latestOutboundConfig['inbound_context'] ?? null) ? $latestOutboundConfig['inbound_context'] : []);
        $latestInboundFingerprint = $replyGuard->buildInboundContextFingerprint(
            messageText: (string) ($latestInboundContext['message_text'] ?? ''),
            intentResult: is_array($latestInboundContext['intent_result'] ?? null) ? $latestInboundContext['intent_result'] : [],
            entityResult: is_array($latestInboundContext['entity_result'] ?? null) ? $latestInboundContext['entity_result'] : [],
            resolvedContext: is_array($latestInboundContext['resolved_context'] ?? null) ? $latestInboundContext['resolved_context'] : [],
        );

        $latestOutbound = new ConversationMessage([
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => (string) ($latestOutboundConfig['message_text'] ?? ''),
            'raw_payload' => [
                'outbound_fingerprint' => $candidateIdentity['outbound_fingerprint'] ?? null,
                'inbound_context_fingerprint' => $latestInboundFingerprint,
            ],
        ]);

        $skipRepeat = $replyGuard->shouldSkipRepeat(
            conversation: $conversation,
            latestOutbound: $latestOutbound,
            reply: $candidateReply,
            replyIdentity: $candidateIdentity,
            inboundContextFingerprint: $candidateInboundFingerprint,
        );

        [$passed, $failureCategory, $details] = $this->assertReplyGuardExpectations(
            case: $case,
            skipRepeat: $skipRepeat,
        );

        return [
            'id' => $case['id'],
            'description' => $case['description'],
            'pipeline' => $case['pipeline'],
            'tags' => $case['tags'],
            'passed' => $passed,
            'failure_category' => $failureCategory,
            'details' => $details,
            'fallback_used' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $policyMeta
     * @return array{0: bool, 1: string|null, 2: string}
     */
    private function assertUnderstandingPolicyExpectations(
        array $case,
        LlmUnderstandingResult $understanding,
        array $intentResult,
        array $entityResult,
        array $policyMeta,
    ): array {
        $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];

        if (($expected['intent'] ?? null) !== null && ($intentResult['intent'] ?? null) !== $expected['intent']) {
            return [false, 'intent_mismatch', 'Expected intent '.$expected['intent'].' but got '.($intentResult['intent'] ?? 'null').'.'];
        }

        if (($expected['policy_action'] ?? null) !== null && ($policyMeta['action'] ?? null) !== $expected['policy_action']) {
            return [false, 'policy_action_mismatch', 'Expected policy action '.$expected['policy_action'].' but got '.($policyMeta['action'] ?? 'null').'.'];
        }

        foreach ([
            'entity.pickup_location' => 'pickup_location',
            'entity.destination' => 'destination',
            'entity.departure_time' => 'departure_time',
            'entity.departure_date' => 'departure_date',
        ] as $expectationKey => $entityKey) {
            if (! array_key_exists($expectationKey, $expected)) {
                continue;
            }

            if (($entityResult[$entityKey] ?? null) !== $expected[$expectationKey]) {
                return [false, 'entity_mismatch', 'Expected '.$entityKey.' to be '.$expected[$expectationKey].' but got '.($entityResult[$entityKey] ?? 'null').'.'];
            }
        }

        if (array_key_exists('needs_clarification', $expected)
            && (bool) ($intentResult['needs_clarification'] ?? false) !== (bool) $expected['needs_clarification']) {
            return [false, 'clarification_flag_mismatch', 'Expected needs_clarification to be '.($expected['needs_clarification'] ? 'true' : 'false').'.'];
        }

        if (($expected['clarification_contains'] ?? null) !== null) {
            $question = mb_strtolower((string) ($intentResult['clarification_question'] ?? ''), 'UTF-8');
            if (! str_contains($question, mb_strtolower((string) $expected['clarification_contains'], 'UTF-8'))) {
                return [false, 'clarification_text_mismatch', 'Clarification question does not contain expected fragment.'];
            }
        }

        if (array_key_exists('handoff_recommended', $expected)
            && (bool) ($intentResult['handoff_recommended'] ?? false) !== (bool) $expected['handoff_recommended']) {
            return [false, 'handoff_mismatch', 'Expected handoff_recommended to match benchmark expectation.'];
        }

        if (array_key_exists('uses_previous_context', $expected)
            && (bool) ($understanding->usesPreviousContext) !== (bool) $expected['uses_previous_context']) {
            return [false, 'context_carry_over_mismatch', 'Expected uses_previous_context to match benchmark expectation.'];
        }

        if (($expected['hydrated_fields'] ?? null) !== null) {
            $hydratedFields = is_array($policyMeta['hydrated_context_fields'] ?? null) ? $policyMeta['hydrated_context_fields'] : [];
            foreach ((array) $expected['hydrated_fields'] as $field) {
                if (! in_array($field, $hydratedFields, true)) {
                    return [false, 'hydration_mismatch', 'Expected hydrated field '.$field.' was not present.'];
                }
            }
        }

        return [true, null, 'OK'];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{0: bool, 1: string|null, 2: string}
     */
    private function assertGroundedResponseExpectations(
        array $case,
        string $mode,
        string $text,
        bool $isFallback,
    ): array {
        $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];

        if (($expected['mode'] ?? null) !== null && $mode !== $expected['mode']) {
            return [false, 'grounded_mode_mismatch', 'Expected grounded mode '.$expected['mode'].' but got '.$mode.'.'];
        }

        if (array_key_exists('is_fallback', $expected) && $isFallback !== (bool) $expected['is_fallback']) {
            return [false, 'fallback_mismatch', 'Expected fallback flag to match benchmark expectation.'];
        }

        foreach ((array) ($expected['text_contains'] ?? []) as $fragment) {
            if (! str_contains(mb_strtolower($text, 'UTF-8'), mb_strtolower((string) $fragment, 'UTF-8'))) {
                return [false, 'grounded_text_mismatch', 'Grounded response does not contain expected fragment: '.$fragment.'.'];
            }
        }

        return [true, null, 'OK'];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{0: bool, 1: string|null, 2: string}
     */
    private function assertReplyGuardExpectations(array $case, bool $skipRepeat): array
    {
        $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];

        if (array_key_exists('skip_repeat', $expected) && $skipRepeat !== (bool) $expected['skip_repeat']) {
            return [false, 'repeat_guard_mismatch', 'Expected skip_repeat to be '.($expected['skip_repeat'] ? 'true' : 'false').'.'];
        }

        return [true, null, 'OK'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeConversation(array $attributes = []): Conversation
    {
        return new Conversation(array_merge([
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'needs_human' => false,
            'started_at' => now(),
            'last_message_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function makeGroundedFacts(array $facts): GroundedResponseFacts
    {
        return new GroundedResponseFacts(
            conversationId: (int) ($facts['conversation_id'] ?? 0),
            messageId: (int) ($facts['message_id'] ?? 0),
            mode: GroundedResponseMode::tryFrom((string) ($facts['mode'] ?? 'direct_answer')) ?? GroundedResponseMode::DirectAnswer,
            latestMessageText: (string) ($facts['latest_message_text'] ?? ''),
            customerName: isset($facts['customer_name']) ? (string) $facts['customer_name'] : null,
            intentResult: is_array($facts['intent_result'] ?? null) ? $facts['intent_result'] : [],
            entityResult: is_array($facts['entity_result'] ?? null) ? $facts['entity_result'] : [],
            resolvedContext: is_array($facts['resolved_context'] ?? null) ? $facts['resolved_context'] : [],
            conversationSummary: isset($facts['conversation_summary']) ? (string) $facts['conversation_summary'] : null,
            adminTakeover: (bool) ($facts['admin_takeover'] ?? false),
            officialFacts: is_array($facts['official_facts'] ?? null) ? $facts['official_facts'] : [],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{
     *     total_cases: int,
     *     passed: int,
     *     failed: int,
     *     failure_categories: array<string, int>,
     *     tag_breakdown: array<string, array{cases: int, passed: int, failed: int}>,
     *     fallback_metrics: array{relevant_cases: int, fallback_used: int, fallback_rate_percent: float},
     *     cases: array<int, array<string, mixed>>
     * }
     */
    private function summarize(array $results): array
    {
        $summary = [
            'total_cases' => count($results),
            'passed' => 0,
            'failed' => 0,
            'failure_categories' => [],
            'tag_breakdown' => [],
            'fallback_metrics' => [
                'relevant_cases' => 0,
                'fallback_used' => 0,
                'fallback_rate_percent' => 0.0,
            ],
            'cases' => $results,
        ];

        foreach ($results as $result) {
            if ($result['passed']) {
                $summary['passed']++;
            } else {
                $summary['failed']++;
                $category = (string) ($result['failure_category'] ?? 'unknown_failure');
                $summary['failure_categories'][$category] = ($summary['failure_categories'][$category] ?? 0) + 1;
            }

            foreach ((array) ($result['tags'] ?? []) as $tag) {
                if (! isset($summary['tag_breakdown'][$tag])) {
                    $summary['tag_breakdown'][$tag] = ['cases' => 0, 'passed' => 0, 'failed' => 0];
                }

                $summary['tag_breakdown'][$tag]['cases']++;
                $summary['tag_breakdown'][$tag][$result['passed'] ? 'passed' : 'failed']++;
            }

            if (($result['pipeline'] ?? null) === 'grounded_response') {
                $summary['fallback_metrics']['relevant_cases']++;
                if (($result['fallback_used'] ?? false) === true) {
                    $summary['fallback_metrics']['fallback_used']++;
                }
            }
        }

        if ($summary['fallback_metrics']['relevant_cases'] > 0) {
            $summary['fallback_metrics']['fallback_rate_percent'] = round(
                ($summary['fallback_metrics']['fallback_used'] / $summary['fallback_metrics']['relevant_cases']) * 100,
                1,
            );
        }

        ksort($summary['failure_categories']);
        ksort($summary['tag_breakdown']);

        return $summary;
    }
}
