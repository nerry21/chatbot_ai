<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Global Switches
    |--------------------------------------------------------------------------
    */

    'enabled' => (bool) env('OPENAI_ENABLED', true),
    'seed_on_webhook' => (bool) env('OPENAI_SEED_ON_WEBHOOK', true),

    /*
    |--------------------------------------------------------------------------
    | Provider / Transport
    |--------------------------------------------------------------------------
    */

    'provider' => env('OPENAI_PROVIDER', 'openai'),
    'api_mode' => env('OPENAI_API_MODE', 'chat_completions'),
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),

    'http' => [
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('OPENAI_TIMEOUT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Safety
    |--------------------------------------------------------------------------
    */

    'retry' => [
        'enabled' => (bool) env('OPENAI_RETRY_ENABLED', true),
        'max_attempts' => (int) env('OPENAI_RETRY_MAX_ATTEMPTS', 3),
        'initial_backoff_ms' => (int) env('OPENAI_RETRY_INITIAL_BACKOFF_MS', 700),
        'max_backoff_ms' => (int) env('OPENAI_RETRY_MAX_BACKOFF_MS', 5000),
        'jitter_ms' => (int) env('OPENAI_RETRY_JITTER_MS', 350),
        'retry_on_statuses' => [408, 409, 423, 425, 429, 500, 502, 503, 504],
    ],

    'circuit_breaker' => [
        'enabled' => (bool) env('OPENAI_CIRCUIT_BREAKER_ENABLED', true),
        'cache_key' => env('OPENAI_CIRCUIT_BREAKER_CACHE_KEY', 'llm:openai:circuit'),
        'failure_threshold' => (int) env('OPENAI_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('OPENAI_CIRCUIT_BREAKER_COOLDOWN_SECONDS', 90),
    ],

    'cache' => [
        'enabled' => (bool) env('OPENAI_CACHE_ENABLED', false),
        'store' => env('OPENAI_CACHE_STORE', null),
        'prefix' => env('OPENAI_CACHE_PREFIX', 'llm'),
        'ttl_seconds' => [
            'intent' => (int) env('OPENAI_CACHE_TTL_INTENT', 60),
            'extraction' => (int) env('OPENAI_CACHE_TTL_EXTRACTION', 60),
            'understanding' => (int) env('OPENAI_CACHE_TTL_UNDERSTANDING', 45),
            'grounded_response' => (int) env('OPENAI_CACHE_TTL_GROUNDED_RESPONSE', 20),
            'reply' => (int) env('OPENAI_CACHE_TTL_REPLY', 20),
            'summary' => (int) env('OPENAI_CACHE_TTL_SUMMARY', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Models
    |--------------------------------------------------------------------------
    */

    'models' => [
        'intent' => env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini'),
        'extraction' => env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini'),
        'understanding' => env('OPENAI_MODEL_UNDERSTANDING', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'grounded_response' => env('OPENAI_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_REPLY', 'gpt-5.4')),
        'reply' => env('OPENAI_MODEL_REPLY', 'gpt-5.4'),
        'summary' => env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini'),
    ],

    'fallback_models' => [
        'intent' => env('OPENAI_FALLBACK_MODEL_INTENT', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'extraction' => env('OPENAI_FALLBACK_MODEL_EXTRACTION', env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini')),
        'understanding' => env('OPENAI_FALLBACK_MODEL_UNDERSTANDING', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'grounded_response' => env('OPENAI_FALLBACK_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'reply' => env('OPENAI_FALLBACK_MODEL_REPLY', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'summary' => env('OPENAI_FALLBACK_MODEL_SUMMARY', env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Profiles
    |--------------------------------------------------------------------------
    */

    'tasks' => [
        'intent' => [
            'model' => env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini'),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_INTENT', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_INTENT', 0.1),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_INTENT', 'low'),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_INTENT', 300),
            'expect_json' => true,
            'timeout' => (int) env('OPENAI_TIMEOUT_INTENT', 30),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_INTENT', 60),
        ],

        'extraction' => [
            'model' => env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini'),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_EXTRACTION', env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_EXTRACTION', 0.1),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_EXTRACTION', 'low'),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_EXTRACTION', 500),
            'expect_json' => true,
            'timeout' => (int) env('OPENAI_TIMEOUT_EXTRACTION', 35),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_EXTRACTION', 60),
        ],

        'understanding' => [
            'model' => env('OPENAI_MODEL_UNDERSTANDING', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_UNDERSTANDING', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_UNDERSTANDING', 0.1),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_UNDERSTANDING', env('OPENAI_REASONING_EFFORT_INTENT', 'low')),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_UNDERSTANDING', 700),
            'expect_json' => true,
            'timeout' => (int) env('OPENAI_TIMEOUT_UNDERSTANDING', 40),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_UNDERSTANDING', 45),
        ],

        'grounded_response' => [
            'model' => env('OPENAI_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_REPLY', 'gpt-5.4')),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_GROUNDED_RESPONSE', 0.2),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_GROUNDED_RESPONSE', env('OPENAI_REASONING_EFFORT_REPLY', 'medium')),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_GROUNDED_RESPONSE', env('OPENAI_MAX_OUTPUT_TOKENS_REPLY', 700)),
            'expect_json' => true,
            'timeout' => (int) env('OPENAI_TIMEOUT_GROUNDED_RESPONSE', 55),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_GROUNDED_RESPONSE', 20),
        ],

        'reply' => [
            'model' => env('OPENAI_MODEL_REPLY', 'gpt-5.4'),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_REPLY', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_REPLY', 0.4),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_REPLY', 'medium'),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_REPLY', 700),
            'expect_json' => false,
            'timeout' => (int) env('OPENAI_TIMEOUT_REPLY', 55),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_REPLY', 20),
        ],

        'summary' => [
            'model' => env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini'),
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_SUMMARY', env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini')),
            'temperature' => (float) env('OPENAI_TEMPERATURE_SUMMARY', 0.1),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_SUMMARY', 'low'),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_SUMMARY', 250),
            'expect_json' => true,
            'timeout' => (int) env('OPENAI_TIMEOUT_SUMMARY', 30),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_SUMMARY', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    */

    'telemetry' => [
        'enabled' => (bool) env('OPENAI_TELEMETRY_ENABLED', true),
        'log_prompts' => (bool) env('OPENAI_LOG_PROMPTS', true),
        'log_responses' => (bool) env('OPENAI_LOG_RESPONSES', true),
        'truncate_prompt_chars' => (int) env('OPENAI_TRUNCATE_PROMPT_CHARS', 6000),
        'truncate_response_chars' => (int) env('OPENAI_TRUNCATE_RESPONSE_CHARS', 6000),
    ],
];
