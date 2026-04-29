<?php

namespace App\Services\AI;

use App\Models\AiLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LlmClientService
{
    private const PROVIDER = 'openai';

    /**
     * ---------------------------------------------------------------------
     * Public task-specific wrappers
     * ---------------------------------------------------------------------
     */

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function classifyIntent(array $context): array
    {
        $fallback = [
            'intent' => 'unknown',
            'confidence' => 0.0,
            'reasoning_short' => 'LLM intent classification gagal atau tidak aktif.',
        ];

        return $this->runTask(
            taskKey: 'intent',
            taskType: 'intent_classification',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function extractEntities(array $context): array
    {
        $fallback = [
            'customer_name' => null,
            'pickup_location' => null,
            'destination' => null,
            'departure_date' => null,
            'departure_time' => null,
            'passenger_count' => null,
            'notes' => null,
            'missing_fields' => [],
        ];

        return $this->runTask(
            taskKey: 'extraction',
            taskType: 'entity_extraction',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateReply(array $context): array
    {
        $fallback = [
            'text' => 'Terima kasih atas pesan Anda. Kami akan segera merespons.',
            'is_fallback' => true,
            '_llm' => $this->fallbackMeta('reply_generation', null, null, 'reply_fallback'),
        ];

        return $this->runTask(
            taskKey: 'reply',
            taskType: 'reply_generation',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function summarizeConversation(array $context): array
    {
        $fallback = [
            'summary' => '',
            '_llm' => $this->fallbackMeta('conversation_summary', null, null, 'summary_fallback'),
        ];

        return $this->runTask(
            taskKey: 'summary',
            taskType: 'conversation_summary',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function understandMessage(array $context): array
    {
        $fallback = [
            'intent' => 'unknown',
            'sub_intent' => null,
            'confidence' => 0.0,
            'uses_previous_context' => false,
            'entities' => [
                'origin' => null,
                'destination' => null,
                'travel_date' => null,
                'departure_time' => null,
                'passenger_count' => null,
                'passenger_name' => null,
                'seat_number' => null,
                'payment_method' => null,
            ],
            'needs_clarification' => true,
            'clarification_question' => 'Boleh dijelaskan lagi kebutuhan perjalanannya?',
            'handoff_recommended' => false,
            'reasoning_summary' => 'LLM understanding gagal atau tidak aktif.',
            '_llm' => $this->fallbackMeta('message_understanding', null, null, 'understanding_fallback'),
        ];

        return $this->runTask(
            taskKey: 'understanding',
            taskType: 'message_understanding',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function composeGroundedResponse(array $context): array
    {
        $fallback = [
            'text' => '',
            'mode' => 'direct_answer',
            '_llm' => $this->fallbackMeta('grounded_response_composition', null, null, 'grounded_response_fallback'),
        ];

        return $this->runTask(
            taskKey: 'grounded_response',
            taskType: 'grounded_response_composition',
            context: $context,
            fallback: $fallback,
        );
    }

    /**
     * ---------------------------------------------------------------------
     * Core runtime
     * ---------------------------------------------------------------------
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function runTask(
        string $taskKey,
        string $taskType,
        array $context,
        array $fallback,
    ): array {
        $conversationId = $context['conversation_id'] ?? null;
        $messageId = $context['message_id'] ?? null;
        $system = (string) ($context['system'] ?? '');
        $user = (string) ($context['user'] ?? '');
        $knowledgeHits = isset($context['knowledge_hits']) && is_array($context['knowledge_hits']) && count($context['knowledge_hits']) > 0
            ? $context['knowledge_hits']
            : null;

        $taskProfile = $this->taskProfile($taskKey, $context);
        $model = (string) ($taskProfile['model'] ?? 'gpt-4o-mini');
        $fallbackModel = $taskProfile['fallback_model'] ?? null;
        $expectJson = (bool) ($taskProfile['expect_json'] ?? false);

        if (! config('openai.enabled', true) || ! config('chatbot.llm.enabled', true)) {
            $result = $this->attachMeta(
                data: $fallback,
                meta: $this->fallbackMeta($taskType, $model, $fallbackModel, 'disabled'),
            );

            $this->writeAiLog($taskType, 'skipped', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'provider' => self::PROVIDER,
                'model' => $model,
                'prompt_snapshot' => $this->truncatePrompt($user),
                'error_message' => 'LLM disabled via config.',
                'knowledge_hits' => $knowledgeHits,
                'parsed_output' => $result,
            ]);

            return $result;
        }

        $apiKey = (string) (config('openai.api_key') ?: config('services.openai.key') ?: env('OPENAI_API_KEY'));

        if ($apiKey === '') {
            $result = $this->attachMeta(
                data: $fallback,
                meta: $this->fallbackMeta($taskType, $model, $fallbackModel, 'missing_api_key'),
            );

            Log::warning("LlmClientService [{$taskType}]: OPENAI_API_KEY not set, returning fallback.");

            $this->writeAiLog($taskType, 'skipped', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'provider' => self::PROVIDER,
                'model' => $model,
                'prompt_snapshot' => $this->truncatePrompt($user),
                'error_message' => 'API key not configured.',
                'knowledge_hits' => $knowledgeHits,
                'parsed_output' => $result,
            ]);

            return $result;
        }

        if ($this->isCircuitOpen()) {
            $result = $this->attachMeta(
                data: $fallback,
                meta: $this->fallbackMeta($taskType, $model, $fallbackModel, 'circuit_open'),
            );

            $this->writeAiLog($taskType, 'skipped', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'provider' => self::PROVIDER,
                'model' => $model,
                'prompt_snapshot' => $this->truncatePrompt($user),
                'error_message' => 'Circuit breaker open.',
                'knowledge_hits' => $knowledgeHits,
                'parsed_output' => $result,
            ]);

            return $result;
        }

        $cacheKey = $this->makeCacheKey($taskKey, $model, $system, $user, $context);
        $cacheTtl = (int) ($taskProfile['cache_ttl'] ?? 0);

        if ($this->cacheEnabled() && $cacheTtl > 0) {
            $cached = $this->cacheStore()->get($cacheKey);

            if (is_array($cached)) {
                $cached = $this->attachMeta(
                    data: $cached,
                    meta: array_merge(
                        $cached['_llm'] ?? [],
                        [
                            'cache_hit' => true,
                            'task_key' => $taskKey,
                            'task_type' => $taskType,
                        ],
                    ),
                );

                $this->writeAiLog($taskType, 'success', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'provider' => self::PROVIDER,
                    'model' => $model,
                    'prompt_snapshot' => $this->truncatePrompt($user),
                    'response_snapshot' => '[cached]',
                    'parsed_output' => $cached,
                    'latency_ms' => 0,
                    'knowledge_hits' => $knowledgeHits,
                ]);

                return $cached;
            }
        }

        $attempt = 0;
        $maxAttempts = max(1, (int) config('openai.retry.max_attempts', 3));
        $allowRetry = (bool) config('openai.retry.enabled', true);

        $primaryError = null;
        $primaryResponseBody = null;
        $primaryLatencyMs = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $result = $this->attemptRequest(
                taskKey: $taskKey,
                taskType: $taskType,
                context: $context,
                taskProfile: $taskProfile,
                model: $model,
                apiKey: $apiKey,
                expectJson: $expectJson,
                attempt: $attempt,
                fallbackModelUsed: false,
            );

            if ($result['ok'] === true) {
                $parsed = $this->attachMeta(
                    data: $result['parsed'],
                    meta: [
                        'provider' => self::PROVIDER,
                        'task_key' => $taskKey,
                        'task_type' => $taskType,
                        'model' => $model,
                        'fallback_model' => $fallbackModel,
                        'used_fallback_model' => false,
                        'status' => 'success',
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'cache_hit' => false,
                        'degraded_mode' => false,
                        'expect_json' => $expectJson,
                        'latency_ms' => $result['latency_ms'],
                        'prompt_tokens' => $result['token_input'],
                        'completion_tokens' => $result['token_output'],
                        'total_tokens' => $result['token_total'],
                        'http_status' => $result['http_status'],
                        'reasoning_effort' => $taskProfile['reasoning_effort'] ?? null,
                        'schema_valid' => $expectJson ? $result['json_valid'] : true,
                    ],
                );

                $this->closeCircuitSuccess();

                if ($this->cacheEnabled() && $cacheTtl > 0) {
                    $this->cacheStore()->put($cacheKey, $parsed, $cacheTtl);
                }

                $this->writeAiLog($taskType, 'success', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'provider' => self::PROVIDER,
                    'model' => $model,
                    'prompt_snapshot' => $this->truncatePrompt($user),
                    'response_snapshot' => $this->truncateResponse($result['raw_content']),
                    'parsed_output' => $parsed,
                    'latency_ms' => $result['latency_ms'],
                    'token_input' => $result['token_input'],
                    'token_output' => $result['token_output'],
                    'knowledge_hits' => $knowledgeHits,
                ]);

                return $parsed;
            }

            $primaryError = $result['error_message'];
            $primaryResponseBody = $result['response_body'];
            $primaryLatencyMs = $result['latency_ms'];

            if (! $allowRetry || ! $result['retryable'] || $attempt >= $maxAttempts) {
                break;
            }

            usleep($this->retryDelayMicros($attempt));
        }

        if (is_string($fallbackModel) && $fallbackModel !== '' && $fallbackModel !== $model) {
            $fallbackAttemptResult = $this->attemptRequest(
                taskKey: $taskKey,
                taskType: $taskType,
                context: $context,
                taskProfile: array_merge($taskProfile, ['model' => $fallbackModel]),
                model: $fallbackModel,
                apiKey: $apiKey,
                expectJson: $expectJson,
                attempt: 1,
                fallbackModelUsed: true,
            );

            if ($fallbackAttemptResult['ok'] === true) {
                $parsed = $this->attachMeta(
                    data: $fallbackAttemptResult['parsed'],
                    meta: [
                        'provider' => self::PROVIDER,
                        'task_key' => $taskKey,
                        'task_type' => $taskType,
                        'model' => $fallbackModel,
                        'primary_model' => $model,
                        'fallback_model' => $fallbackModel,
                        'used_fallback_model' => true,
                        'status' => 'success',
                        'attempt' => 1,
                        'max_attempts' => 1,
                        'cache_hit' => false,
                        'degraded_mode' => true,
                        'expect_json' => $expectJson,
                        'latency_ms' => $fallbackAttemptResult['latency_ms'],
                        'prompt_tokens' => $fallbackAttemptResult['token_input'],
                        'completion_tokens' => $fallbackAttemptResult['token_output'],
                        'total_tokens' => $fallbackAttemptResult['token_total'],
                        'http_status' => $fallbackAttemptResult['http_status'],
                        'reasoning_effort' => $taskProfile['reasoning_effort'] ?? null,
                        'schema_valid' => $expectJson ? $fallbackAttemptResult['json_valid'] : true,
                        'fallback_reason' => $primaryError,
                    ],
                );

                $this->closeCircuitSuccess();

                if ($this->cacheEnabled() && $cacheTtl > 0) {
                    $this->cacheStore()->put($cacheKey, $parsed, $cacheTtl);
                }

                $this->writeAiLog($taskType, 'success', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'provider' => self::PROVIDER,
                    'model' => $fallbackModel,
                    'prompt_snapshot' => $this->truncatePrompt($user),
                    'response_snapshot' => $this->truncateResponse($fallbackAttemptResult['raw_content']),
                    'parsed_output' => $parsed,
                    'latency_ms' => $fallbackAttemptResult['latency_ms'],
                    'token_input' => $fallbackAttemptResult['token_input'],
                    'token_output' => $fallbackAttemptResult['token_output'],
                    'knowledge_hits' => $knowledgeHits,
                ]);

                return $parsed;
            }

            $primaryError = trim(($primaryError ? $primaryError . ' | ' : '') . 'Fallback model failed: ' . $fallbackAttemptResult['error_message']);
            $primaryResponseBody = $fallbackAttemptResult['response_body'] ?: $primaryResponseBody;
            $primaryLatencyMs = $fallbackAttemptResult['latency_ms'] ?: $primaryLatencyMs;
        }

        $this->recordCircuitFailure();

        $final = $this->attachMeta(
            data: $fallback,
            meta: $this->fallbackMeta($taskType, $model, $fallbackModel, 'request_failed', [
                'cache_hit' => false,
                'error_message' => $primaryError,
                'latency_ms' => $primaryLatencyMs,
            ]),
        );

        $this->writeAiLog($taskType, 'failed', [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'provider' => self::PROVIDER,
            'model' => $model,
            'prompt_snapshot' => $this->truncatePrompt($user),
            'response_snapshot' => $this->truncateResponse($primaryResponseBody),
            'parsed_output' => $final,
            'latency_ms' => $primaryLatencyMs,
            'error_message' => $primaryError,
            'knowledge_hits' => $knowledgeHits,
        ]);

        return $final;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $taskProfile
     * @return array<string, mixed>
     */
    private function attemptRequest(
        string $taskKey,
        string $taskType,
        array $context,
        array $taskProfile,
        string $model,
        string $apiKey,
        bool $expectJson,
        int $attempt,
        bool $fallbackModelUsed,
    ): array {
        $system = (string) ($context['system'] ?? '');
        $user = (string) ($context['user'] ?? '');
        $startedAt = microtime(true);

        $body = $this->buildChatCompletionsPayload(
            model: $model,
            system: $system,
            user: $user,
            taskProfile: $taskProfile,
            expectJson: $expectJson,
        );

        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout((int) config('openai.http.connect_timeout', 10))
                ->timeout((int) ($taskProfile['timeout'] ?? config('openai.http.timeout', 90)))
                ->acceptJson()
                ->asJson()
                ->post($this->chatCompletionsUrl(), $body);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $responseBody = (string) $response->body();
            $httpStatus = $response->status();

            if ($response->failed()) {
                return [
                    'ok' => false,
                    'retryable' => $this->isRetryableStatus($httpStatus),
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'response_body' => $responseBody,
                    'raw_content' => null,
                    'parsed' => null,
                    'json_valid' => false,
                    'token_input' => null,
                    'token_output' => null,
                    'token_total' => null,
                    'error_message' => "HTTP {$httpStatus}: " . mb_substr($responseBody, 0, 1000),
                    'attempt' => $attempt,
                    'fallback_model_used' => $fallbackModelUsed,
                ];
            }

            $json = $response->json();
            $rawContent = (string) Arr::get($json, 'choices.0.message.content', '');
            $tokenInput = Arr::get($json, 'usage.prompt_tokens');
            $tokenOutput = Arr::get($json, 'usage.completion_tokens');
            $tokenTotal = Arr::get($json, 'usage.total_tokens');

            if ($expectJson) {
                $parsed = $this->parseJsonContent($rawContent);

                if (! is_array($parsed)) {
                    return [
                        'ok' => false,
                        'retryable' => false,
                        'http_status' => $httpStatus,
                        'latency_ms' => $latencyMs,
                        'response_body' => $responseBody,
                        'raw_content' => $rawContent,
                        'parsed' => null,
                        'json_valid' => false,
                        'token_input' => $tokenInput,
                        'token_output' => $tokenOutput,
                        'token_total' => $tokenTotal,
                        'error_message' => 'Invalid JSON response from model.',
                        'attempt' => $attempt,
                        'fallback_model_used' => $fallbackModelUsed,
                    ];
                }

                return [
                    'ok' => true,
                    'retryable' => false,
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'response_body' => $responseBody,
                    'raw_content' => $rawContent,
                    'parsed' => $parsed,
                    'json_valid' => true,
                    'token_input' => $tokenInput,
                    'token_output' => $tokenOutput,
                    'token_total' => $tokenTotal,
                    'error_message' => null,
                    'attempt' => $attempt,
                    'fallback_model_used' => $fallbackModelUsed,
                ];
            }

            return [
                'ok' => true,
                'retryable' => false,
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'response_body' => $responseBody,
                'raw_content' => $rawContent,
                'parsed' => [
                    'text' => trim($rawContent),
                    'is_fallback' => false,
                ],
                'json_valid' => true,
                'token_input' => $tokenInput,
                'token_output' => $tokenOutput,
                'token_total' => $tokenTotal,
                'error_message' => null,
                'attempt' => $attempt,
                'fallback_model_used' => $fallbackModelUsed,
            ];
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'retryable' => true,
                'http_status' => null,
                'latency_ms' => $latencyMs,
                'response_body' => null,
                'raw_content' => null,
                'parsed' => null,
                'json_valid' => false,
                'token_input' => null,
                'token_output' => null,
                'token_total' => null,
                'error_message' => 'Connection error: ' . $e->getMessage(),
                'attempt' => $attempt,
                'fallback_model_used' => $fallbackModelUsed,
            ];
        } catch (RequestException $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'retryable' => true,
                'http_status' => null,
                'latency_ms' => $latencyMs,
                'response_body' => null,
                'raw_content' => null,
                'parsed' => null,
                'json_valid' => false,
                'token_input' => null,
                'token_output' => null,
                'token_total' => null,
                'error_message' => 'Request exception: ' . $e->getMessage(),
                'attempt' => $attempt,
                'fallback_model_used' => $fallbackModelUsed,
            ];
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'retryable' => false,
                'http_status' => null,
                'latency_ms' => $latencyMs,
                'response_body' => null,
                'raw_content' => null,
                'parsed' => null,
                'json_valid' => false,
                'token_input' => null,
                'token_output' => null,
                'token_total' => null,
                'error_message' => 'Unexpected error: ' . $e->getMessage(),
                'attempt' => $attempt,
                'fallback_model_used' => $fallbackModelUsed,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $taskProfile
     * @return array<string, mixed>
     */
    private function buildChatCompletionsPayload(
        string $model,
        string $system,
        string $user,
        array $taskProfile,
        bool $expectJson,
    ): array {
        $payload = [
            'model' => $model,
            'temperature' => (float) ($taskProfile['temperature'] ?? ($expectJson ? 0.1 : 0.7)),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $maxOutputTokens = (int) ($taskProfile['max_output_tokens'] ?? 0);

        if ($maxOutputTokens > 0) {
            $payload['max_completion_tokens'] = $maxOutputTokens;
        }

        if ($expectJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function taskProfile(string $taskKey, array $context): array
    {
        $profile = (array) config("openai.tasks.{$taskKey}", []);

        if (isset($context['model']) && is_string($context['model']) && $context['model'] !== '') {
            $profile['model'] = $context['model'];
        }

        if (array_key_exists('expect_json', $context)) {
            $profile['expect_json'] = (bool) $context['expect_json'];
        }

        if (isset($context['temperature'])) {
            $profile['temperature'] = (float) $context['temperature'];
        }

        if (isset($context['timeout'])) {
            $profile['timeout'] = (int) $context['timeout'];
        }

        if (isset($context['max_output_tokens'])) {
            $profile['max_output_tokens'] = (int) $context['max_output_tokens'];
        }

        return $profile;
    }

    private function chatCompletionsUrl(): string
    {
        return rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/') . '/chat/completions';
    }

    private function isRetryableStatus(?int $status): bool
    {
        return in_array($status, (array) config('openai.retry.retry_on_statuses', []), true);
    }

    private function retryDelayMicros(int $attempt): int
    {
        $initial = max(1, (int) config('openai.retry.initial_backoff_ms', 700));
        $max = max($initial, (int) config('openai.retry.max_backoff_ms', 5000));
        $jitter = max(0, (int) config('openai.retry.jitter_ms', 350));

        $base = min($max, $initial * (2 ** max(0, $attempt - 1)));
        $randomJitter = $jitter > 0 ? random_int(0, $jitter) : 0;

        return ($base + $randomJitter) * 1000;
    }

    private function isCircuitOpen(): bool
    {
        if (! (bool) config('openai.circuit_breaker.enabled', true)) {
            return false;
        }

        $state = Cache::get($this->circuitBreakerCacheKey(), []);

        if (! is_array($state)) {
            return false;
        }

        $openedUntil = isset($state['opened_until']) ? (int) $state['opened_until'] : 0;

        return $openedUntil > now()->timestamp;
    }

    private function recordCircuitFailure(): void
    {
        if (! (bool) config('openai.circuit_breaker.enabled', true)) {
            return;
        }

        $key = $this->circuitBreakerCacheKey();
        $state = Cache::get($key, []);

        if (! is_array($state)) {
            $state = [];
        }

        $failures = (int) ($state['failures'] ?? 0) + 1;
        $threshold = max(1, (int) config('openai.circuit_breaker.failure_threshold', 5));
        $cooldown = max(1, (int) config('openai.circuit_breaker.cooldown_seconds', 90));

        $payload = [
            'failures' => $failures,
            'opened_until' => $failures >= $threshold ? now()->addSeconds($cooldown)->timestamp : 0,
            'last_failure_at' => now()->timestamp,
        ];

        Cache::put($key, $payload, now()->addSeconds($cooldown));
    }

    private function closeCircuitSuccess(): void
    {
        if (! (bool) config('openai.circuit_breaker.enabled', true)) {
            return;
        }

        Cache::forget($this->circuitBreakerCacheKey());
    }

    private function circuitBreakerCacheKey(): string
    {
        return (string) config('openai.circuit_breaker.cache_key', 'llm:openai:circuit');
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('openai.cache.enabled', false);
    }

    private function cacheStore()
    {
        $store = config('openai.cache.store');

        return $store ? Cache::store($store) : Cache::store();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function makeCacheKey(
        string $taskKey,
        string $model,
        string $system,
        string $user,
        array $context,
    ): string {
        $prefix = (string) config('openai.cache.prefix', 'llm');
        $conversationId = (string) ($context['conversation_id'] ?? '');
        $messageId = (string) ($context['message_id'] ?? '');
        $knowledgeHits = $context['knowledge_hits'] ?? null;

        return $prefix . ':' . $taskKey . ':' . sha1(json_encode([
            'model' => $model,
            'system' => $system,
            'user' => $user,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'knowledge_hits' => $knowledgeHits,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function attachMeta(array $data, array $meta): array
    {
        $data['_llm'] = array_merge($data['_llm'] ?? [], $meta);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fallbackMeta(
        string $taskType,
        ?string $model,
        ?string $fallbackModel,
        string $reason,
        array $extra = [],
    ): array {
        return array_merge([
            'provider' => self::PROVIDER,
            'task_type' => $taskType,
            'model' => $model,
            'fallback_model' => $fallbackModel,
            'used_fallback_model' => false,
            'status' => 'fallback',
            'degraded_mode' => true,
            'cache_hit' => false,
            'fallback_reason' => $reason,
            'schema_valid' => false,
        ], $extra);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonContent(string $content): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $clean = preg_replace('/\s*```$/', '', (string) $clean);

        $decoded = json_decode($clean, true);

        if (! is_array($decoded)) {
            Log::warning('LlmClientService: JSON parse failed', [
                'raw' => mb_substr($content, 0, 500),
            ]);

            return null;
        }

        return $decoded;
    }

    private function truncatePrompt(?string $value): ?string
    {
        if (! (bool) config('openai.telemetry.log_prompts', true)) {
            return null;
        }

        return $value === null
            ? null
            : mb_substr($value, 0, (int) config('openai.telemetry.truncate_prompt_chars', 6000));
    }

    private function truncateResponse(?string $value): ?string
    {
        if (! (bool) config('openai.telemetry.log_responses', true)) {
            return null;
        }

        return $value === null
            ? null
            : mb_substr($value, 0, (int) config('openai.telemetry.truncate_response_chars', 6000));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeAiLog(string $taskType, string $status, array $payload): void
    {
        try {
            AiLog::writeLog($taskType, $status, $payload);
        } catch (Throwable $e) {
            Log::warning('LlmClientService: failed writing AiLog', [
                'task_type' => $taskType,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Function-calling chat completion. Returns finish_reason, content, tool_calls,
     * usage, and the raw assistant message for re-injection in the next iteration.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function callWithTools(array $messages, array $tools, ?string $model = null): array
    {
        $model = $model ?? config('chatbot.agent.model', 'gpt-5.4-mini');
        $temperature = (float) config('chatbot.agent.temperature', 0.7);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => $temperature,
        ];

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->timeout((int) config('openai.http.timeout', 30))
                ->acceptJson()
                ->post($this->chatCompletionsUrl(), $payload);

            if (! $response->successful()) {
                Log::error('[LlmAgent:callWithTools] HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('OpenAI API error: '.$response->status());
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? [];
            $message = $choice['message'] ?? [];

            return [
                'finish_reason' => $choice['finish_reason'] ?? 'stop',
                'content' => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'] ?? [],
                'usage' => $data['usage'] ?? [],
                'raw_message' => $message,
            ];
        } catch (Throwable $e) {
            Log::error('[LlmAgent:callWithTools] Exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
