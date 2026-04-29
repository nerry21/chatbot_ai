<?php

namespace App\Services\Chatbot;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\LlmClientService;
use App\Support\WaLog;
use Throwable;

class LlmAgentOrchestratorService
{
    private const DEFAULT_MAX_ITERATIONS = 5;
    private const DEFAULT_HISTORY_SIZE = 10;
    private const HANDOFF_REPLY = 'Mohon maaf kak, saya akan teruskan pertanyaan ini ke admin ya 🙏';

    public function __construct(
        private readonly LlmClientService $llm,
        private readonly LlmAgentToolRegistry $tools,
        private readonly LlmAgentPromptBuilder $promptBuilder,
        private readonly LlmAgentTierRouter $tierRouter,
    ) {}

    /**
     * @return array{
     *     reply_text: string,
     *     tools_called: array<int, string>,
     *     iterations: int,
     *     should_handoff: bool,
     *     meta: array<string, mixed>
     * }
     */
    public function handle(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer,
    ): array {
        $maxIterations = (int) config('chatbot.agent.max_iterations', self::DEFAULT_MAX_ITERATIONS);
        $historySize = (int) config('chatbot.agent.history_size', self::DEFAULT_HISTORY_SIZE);

        $messages = $this->buildInitialMessages($message, $conversation, $customer, $historySize);
        $toolsSchema = $this->tools->getToolsSchema();

        $tierDecision = $this->tierRouter->decide($message, $conversation, $customer);

        $toolsCalled = [];
        $shouldHandoff = false;
        $replyText = '';
        $iterations = 0;
        $usageTotals = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        for ($i = 1; $i <= $maxIterations; $i++) {
            $iterations = $i;

            $response = $this->llm->callWithTools($messages, $toolsSchema, $tierDecision['model']);

            $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                if (isset($usage[$key]) && is_numeric($usage[$key])) {
                    $usageTotals[$key] += (int) $usage[$key];
                }
            }

            WaLog::info('[LlmAgent] Iteration', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'iteration' => $i,
                'finish_reason' => $response['finish_reason'] ?? null,
                'tool_calls_count' => is_array($response['tool_calls'] ?? null) ? count($response['tool_calls']) : 0,
            ]);

            $finishReason = $response['finish_reason'] ?? 'stop';
            $toolCalls = is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];

            if ($finishReason === 'tool_calls' && $toolCalls !== []) {
                $messages[] = is_array($response['raw_message'] ?? null)
                    ? $response['raw_message']
                    : [
                        'role' => 'assistant',
                        'content' => $response['content'] ?? null,
                        'tool_calls' => $toolCalls,
                    ];

                foreach ($toolCalls as $call) {
                    $callId = (string) ($call['id'] ?? '');
                    $name = (string) ($call['function']['name'] ?? '');
                    $rawArgs = (string) ($call['function']['arguments'] ?? '{}');
                    $args = $this->decodeArgs($rawArgs);

                    if ($name === '') {
                        continue;
                    }

                    $toolsCalled[] = $name;

                    try {
                        $result = $this->tools->execute($name, $args, $customer);
                    } catch (Throwable $e) {
                        WaLog::error('[LlmAgent] Tool execution failed', [
                            'tool' => $name,
                            'error' => $e->getMessage(),
                        ]);
                        $result = ['error' => $e->getMessage()];
                    }

                    if ($name === 'escalate_to_admin') {
                        $shouldHandoff = true;
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'name' => $name,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                continue;
            }

            $replyText = trim((string) ($response['content'] ?? ''));
            break;
        }

        if ($iterations >= $maxIterations && $replyText === '') {
            WaLog::warning('[LlmAgent] Max iterations reached, forcing handoff', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'iterations' => $iterations,
            ]);
            $replyText = self::HANDOFF_REPLY;
            $shouldHandoff = true;
        }

        try {
            \App\Models\AiLog::writeLog('llm_agent', 'success', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'provider' => 'openai',
                'model' => $tierDecision['model'],
                'token_input' => $usageTotals['prompt_tokens'] ?? null,
                'token_output' => $usageTotals['completion_tokens'] ?? null,
                'latency_ms' => null,
                'parsed_output' => [
                    'tier' => $tierDecision['tier'],
                    'tier_score' => $tierDecision['score'],
                    'tier_reasons' => $tierDecision['reasons'],
                    'iterations' => $iterations,
                    'tools_called' => $toolsCalled,
                    'should_handoff' => $shouldHandoff,
                    'reply_text_preview' => mb_substr($replyText, 0, 200),
                ],
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[LlmAgent] Failed to write ai_logs', [
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'reply_text' => $replyText !== '' ? $replyText : self::HANDOFF_REPLY,
            'tools_called' => $toolsCalled,
            'iterations' => $iterations,
            'should_handoff' => $shouldHandoff || $replyText === '',
            'meta' => [
                'usage' => $usageTotals,
                'history_size' => $historySize,
                'max_iterations' => $maxIterations,
                'tier' => $tierDecision['tier'],
                'tier_model' => $tierDecision['model'],
                'tier_score' => $tierDecision['score'],
                'tier_reasons' => $tierDecision['reasons'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInitialMessages(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer,
        int $historySize,
    ): array {
        $messages = [
            [
                'role' => 'system',
                'content' => $this->promptBuilder->buildSystemPrompt($customer),
            ],
        ];

        $history = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '!=', $message->id)
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($historySize)
            ->get(['id', 'direction', 'message_text']);

        foreach ($history->reverse()->values() as $past) {
            $text = (string) ($past->message_text ?? '');
            if ($text === '') {
                continue;
            }
            $messages[] = [
                'role' => $past->direction === MessageDirection::Inbound ? 'user' : 'assistant',
                'content' => $text,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => (string) ($message->message_text ?? ''),
        ];

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArgs(string $rawArgs): array
    {
        $decoded = json_decode($rawArgs, true);

        return is_array($decoded) ? $decoded : [];
    }
}
