<?php

namespace App\Services\AI;

use App\Models\AiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmClientService
{
    private const PROVIDER = 'openai';
    private const API_URL   = 'https://api.openai.com/v1/chat/completions';

    // -------------------------------------------------------------------------
    // Public task-specific methods
    // Each receives a $context array and returns a parsed array.
    // Expected context keys: system, user, model, conversation_id, message_id.
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function classifyIntent(array $context): array
    {
        $fallback = ['intent' => 'unknown', 'confidence' => 0.0, 'reasoning_short' => 'LLM tidak aktif atau gagal.'];

        return $this->callChat(
            context     : $context,
            taskType    : 'intent_classification',
            model       : $context['model'] ?? config('chatbot.llm.models.intent'),
            expectJson  : true,
            fallback    : $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function extractEntities(array $context): array
    {
        $fallback = [
            'customer_name'    => null,
            'pickup_location'  => null,
            'destination'      => null,
            'departure_date'   => null,
            'departure_time'   => null,
            'passenger_count'  => null,
            'notes'            => null,
            'missing_fields'   => [],
        ];

        return $this->callChat(
            context    : $context,
            taskType   : 'entity_extraction',
            model      : $context['model'] ?? config('chatbot.llm.models.extraction'),
            expectJson : true,
            fallback   : $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generateReply(array $context): array
    {
        $fallback = [
            'text'        => 'Terima kasih atas pesan Anda. Kami akan segera merespons.',
            'is_fallback' => true,
        ];

        return $this->callChat(
            context    : $context,
            taskType   : 'reply_generation',
            model      : $context['model'] ?? config('chatbot.llm.models.reply'),
            expectJson : false,
            fallback   : $fallback,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function summarizeConversation(array $context): array
    {
        $fallback = ['summary' => ''];

        return $this->callChat(
            context    : $context,
            taskType   : 'conversation_summary',
            model      : $context['model'] ?? config('chatbot.llm.models.summary'),
            expectJson : true,
            fallback   : $fallback,
        );
    }

    // -------------------------------------------------------------------------
    // Private core
    // -------------------------------------------------------------------------

    /**
     * Execute a chat completion request against the configured LLM provider.
     * Handles disabled mode, HTTP errors, JSON parsing, and AiLog creation.
     *
     * @param  array<string, mixed>  $context   Must contain: system, user.
     * @param  array<string, mixed>  $fallback  Returned when LLM is off or fails.
     * @return array<string, mixed>
     */
    private function callChat(
        array $context,
        string $taskType,
        string $model,
        bool $expectJson,
        array $fallback,
    ): array {
        $conversationId = $context['conversation_id'] ?? null;
        $messageId      = $context['message_id'] ?? null;
        $system         = $context['system'] ?? '';
        $user           = $context['user'] ?? '';
        // Tahap 10: knowledge hits stored per-log for quality tracing
        $knowledgeHits  = isset($context['knowledge_hits']) && is_array($context['knowledge_hits']) && count($context['knowledge_hits']) > 0
            ? $context['knowledge_hits']
            : null;

        // ── Disabled mode ──────────────────────────────────────────────────
        if (! config('chatbot.llm.enabled', true)) {
            AiLog::writeLog($taskType, 'skipped', [
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
                'provider'        => self::PROVIDER,
                'model'           => $model,
                'prompt_snapshot' => $user,
                'error_message'   => 'LLM disabled via config.',
                'knowledge_hits'  => $knowledgeHits,
            ]);

            return $fallback;
        }

        $apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY');

        if (empty($apiKey)) {
            Log::warning("LlmClientService [{$taskType}]: OPENAI_API_KEY not set, returning fallback.");

            AiLog::writeLog($taskType, 'skipped', [
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
                'provider'        => self::PROVIDER,
                'model'           => $model,
                'prompt_snapshot' => $user,
                'error_message'   => 'API key not configured.',
                'knowledge_hits'  => $knowledgeHits,
            ]);

            return $fallback;
        }

        $startedAt = microtime(true);

        // ── HTTP request ───────────────────────────────────────────────────
        try {
            $body = [
                'model'       => $model,
                'temperature' => $expectJson ? 0.1 : 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ];

            if ($expectJson) {
                $body['response_format'] = ['type' => 'json_object'];
            }

            $response = Http::withToken($apiKey)
                ->timeout((int) config('chatbot.llm.timeout_seconds', 30))
                ->post(self::API_URL, $body);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->failed()) {
                $errorMsg = "HTTP {$response->status()}: " . $response->body();

                Log::error("LlmClientService [{$taskType}]: API error", [
                    'status'  => $response->status(),
                    'body'    => mb_substr($response->body(), 0, 500),
                ]);

                AiLog::writeLog($taskType, 'failed', [
                    'conversation_id'   => $conversationId,
                    'message_id'        => $messageId,
                    'provider'          => self::PROVIDER,
                    'model'             => $model,
                    'prompt_snapshot'   => $user,
                    'response_snapshot' => $response->body(),
                    'latency_ms'        => $latencyMs,
                    'error_message'     => $errorMsg,
                    'knowledge_hits'    => $knowledgeHits,
                ]);

                return $fallback;
            }

            $json        = $response->json();
            $rawContent  = $json['choices'][0]['message']['content'] ?? '';
            $tokenInput  = $json['usage']['prompt_tokens'] ?? null;
            $tokenOutput = $json['usage']['completion_tokens'] ?? null;

            // ── Parse output ───────────────────────────────────────────────
            $parsed = $expectJson
                ? $this->parseJsonContent($rawContent, $fallback)
                : ['text' => trim($rawContent), 'is_fallback' => false];

            AiLog::writeLog($taskType, 'success', [
                'conversation_id'   => $conversationId,
                'message_id'        => $messageId,
                'provider'          => self::PROVIDER,
                'model'             => $model,
                'prompt_snapshot'   => $user,
                'response_snapshot' => $rawContent,
                'parsed_output'     => $parsed,
                'latency_ms'        => $latencyMs,
                'token_input'       => $tokenInput,
                'token_output'      => $tokenOutput,
                'knowledge_hits'    => $knowledgeHits,
            ]);

            return $parsed;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::error("LlmClientService [{$taskType}]: connection timeout/error", ['error' => $e->getMessage()]);

            AiLog::writeLog($taskType, 'failed', [
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
                'provider'        => self::PROVIDER,
                'model'           => $model,
                'prompt_snapshot' => $user,
                'latency_ms'      => $latencyMs,
                'error_message'   => 'Connection error: ' . $e->getMessage(),
                'knowledge_hits'  => $knowledgeHits,
            ]);

            return $fallback;
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::error("LlmClientService [{$taskType}]: unexpected error", ['error' => $e->getMessage()]);

            AiLog::writeLog($taskType, 'failed', [
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
                'provider'        => self::PROVIDER,
                'model'           => $model,
                'prompt_snapshot' => $user,
                'latency_ms'      => $latencyMs,
                'error_message'   => $e->getMessage(),
                'knowledge_hits'  => $knowledgeHits,
            ]);

            return $fallback;
        }
    }

    /**
     * Attempt to JSON-decode the raw LLM content.
     * Returns $fallback if content is not valid JSON.
     *
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function parseJsonContent(string $content, array $fallback): array
    {
        // Strip markdown code fences that some models add despite json_object mode
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $clean = preg_replace('/\s*```$/', '', $clean);

        $decoded = json_decode($clean, associative: true);

        if (! is_array($decoded)) {
            Log::warning('LlmClientService: JSON parse failed', ['raw' => mb_substr($content, 0, 300)]);
            return $fallback;
        }

        return $decoded;
    }
}
