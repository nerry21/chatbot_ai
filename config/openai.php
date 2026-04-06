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
    // Legacy root keys (tetap dipertahankan untuk kompatibilitas lama)
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
    | Default (Fallback Global)
    |--------------------------------------------------------------------------
    | Patch 1: fallback global jika task tertentu belum diisi.
    */
    'default' => [
        'provider' => env('OPENAI_DEFAULT_PROVIDER', 'openai'),
        'model' => env('OPENAI_MODEL_DEFAULT', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'timeout' => (int) env('OPENAI_TIMEOUT', 45),
        'max_retries' => (int) env('OPENAI_MAX_RETRIES', 2),
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
    | Default Models (Legacy Compatibility)
    |--------------------------------------------------------------------------
    | Patch 11: JANGAN dihapus. Kompatibilitas lama tetap dijaga.
    | Catatan: Service/engine baru SEBAIKNYA membaca: openai.tasks.*
    | BUKAN lagi: openai.models.*
    */
    'models' => [
        // Tambahan default map untuk arah baru (tanpa mengubah key lama)
        'default' => env('OPENAI_MODEL_DEFAULT', env('OPENAI_MODEL', 'gpt-4o-mini')),

        // Legacy mappings (dipertahankan apa adanya)
        'intent' => env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini'),
        'extraction' => env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini'),
        'understanding' => env('OPENAI_MODEL_UNDERSTANDING', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'grounded_response' => env('OPENAI_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_REPLY', 'gpt-5.4')),
        'reply' => env('OPENAI_MODEL_REPLY', 'gpt-5.4'),
        'summary' => env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Profiles (Pusat konfigurasi per task)
    |--------------------------------------------------------------------------
    | Patch 2–6: Normalisasi blok tasks. Tambahkan reply_draft & reply_guard_fallback.
    | Catatan:
    | - Field baru (provider, max_retries, max_tokens, runtime_label) ditambahkan.
    | - Field lama (fallback_model, cache_ttl, max_output_tokens) dipertahankan.
    | - Task 'reply' lama tetap ada untuk kompatibilitas; task baru 'reply_draft'
    |   dipakai oleh service/engine baru.
    */
    'tasks' => [
        // LEGACY: intent (dipertahankan)
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

        // LEGACY: extraction (dipertahankan)
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

        // Patch 2: understanding
        'understanding' => [
            'provider' => env('OPENAI_PROVIDER_UNDERSTANDING', env('OPENAI_DEFAULT_PROVIDER', 'openai')),
            'model' => env('OPENAI_MODEL_UNDERSTANDING', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT_UNDERSTANDING', 45),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES_UNDERSTANDING', 2),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS_UNDERSTANDING', 800),
            'temperature' => (float) env('OPENAI_TEMPERATURE_UNDERSTANDING', 0.1),
            'expect_json' => true,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_UNDERSTANDING', 'low'),
            'runtime_label' => 'message_understanding',
            // Kompatibilitas lama (dipertahankan)
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_UNDERSTANDING', env('OPENAI_MODEL_UNDERSTANDING', 'gpt-4o-mini')),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_UNDERSTANDING', 700),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_UNDERSTANDING', 45),
        ],

        // Patch 4: grounded_response
        'grounded_response' => [
            'provider' => env('OPENAI_PROVIDER_GROUNDED_RESPONSE', env('OPENAI_DEFAULT_PROVIDER', 'openai')),
            'model' => env('OPENAI_MODEL_GROUNDED_RESPONSE', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT_GROUNDED_RESPONSE', 60),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES_GROUNDED_RESPONSE', 2),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS_GROUNDED_RESPONSE', 1000),
            'temperature' => (float) env('OPENAI_TEMPERATURE_GROUNDED_RESPONSE', 0.2),
            'expect_json' => true,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_GROUNDED_RESPONSE', 'medium'),
            'runtime_label' => 'grounded_reply_candidate',
            // Kompatibilitas lama (dipertahankan)
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_GROUNDED_RESPONSE', 'gpt-4o-mini')),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_GROUNDED_RESPONSE', env('OPENAI_MAX_OUTPUT_TOKENS_REPLY', 700)),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_GROUNDED_RESPONSE', 20),
        ],

        // LEGACY: reply (dipertahankan; gunakan reply_draft untuk yang baru)
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

        // Patch 3: reply_draft (baru, dipisahkan dari reply)
        'reply_draft' => [
            'provider' => env('OPENAI_PROVIDER_REPLY_DRAFT', env('OPENAI_DEFAULT_PROVIDER', 'openai')),
            'model' => env('OPENAI_MODEL_REPLY_DRAFT', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini')),
            'timeout' => (int) env('OPENAI_TIMEOUT_REPLY_DRAFT', 60),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES_REPLY_DRAFT', 2),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS_REPLY_DRAFT', 1000),
            'temperature' => (float) env('OPENAI_TEMPERATURE_REPLY_DRAFT', 0.3),
            'expect_json' => true,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_REPLY_DRAFT', 'medium'),
            'runtime_label' => 'reply_draft_generation',
        ],

        // Patch 5: summary
        'summary' => [
            'provider' => env('OPENAI_PROVIDER_SUMMARY', env('OPENAI_DEFAULT_PROVIDER', 'openai')),
            'model' => env('OPENAI_MODEL_SUMMARY', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini')),
            'timeout' => (int) env('OPENAI_TIMEOUT_SUMMARY', 45),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES_SUMMARY', 2),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS_SUMMARY', 500),
            'temperature' => (float) env('OPENAI_TEMPERATURE_SUMMARY', 0.2),
            'expect_json' => true,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_SUMMARY', 'low'),
            'runtime_label' => 'conversation_summary',
            // Kompatibilitas lama (dipertahankan)
            'fallback_model' => env('OPENAI_FALLBACK_MODEL_SUMMARY', env('OPENAI_MODEL_SUMMARY', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini'))),
            'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_SUMMARY', 250),
            'cache_ttl' => (int) env('OPENAI_CACHE_TTL_SUMMARY', 300),
        ],

        // Patch 6: reply_guard_fallback (opsional)
        'reply_guard_fallback' => [
            'provider' => env('OPENAI_PROVIDER_REPLY_GUARD_FALLBACK', env('OPENAI_DEFAULT_PROVIDER', 'openai')),
            'model' => env('OPENAI_MODEL_REPLY_GUARD_FALLBACK', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini')),
            'timeout' => (int) env('OPENAI_TIMEOUT_REPLY_GUARD_FALLBACK', 30),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES_REPLY_GUARD_FALLBACK', 1),
            'max_tokens' => (int) env('OPENAI_MAX_TOKENS_REPLY_GUARD_FALLBACK', 400),
            'temperature' => (float) env('OPENAI_TEMPERATURE_REPLY_GUARD_FALLBACK', 0.2),
            'expect_json' => true,
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT_REPLY_GUARD_FALLBACK', 'low'),
            'runtime_label' => 'reply_guard_fallback',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Models (Root)
    |--------------------------------------------------------------------------
    | Patch 7: fallback model terpusat per task.
    | Catatan: entry legacy dipertahankan; 'reply_draft' ditambahkan.
    */
    'fallback_models' => [
        // Legacy
        'intent' => env('OPENAI_FALLBACK_MODEL_INTENT', env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini')),
        'extraction' => env('OPENAI_FALLBACK_MODEL_EXTRACTION', env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini')),
        'understanding' => env('OPENAI_FALLBACK_MODEL_UNDERSTANDING', env('OPENAI_MODEL_UNDERSTANDING', 'gpt-4o-mini')),
        'grounded_response' => env('OPENAI_FALLBACK_MODEL_GROUNDED_RESPONSE', env('OPENAI_MODEL_GROUNDED_RESPONSE', 'gpt-4o-mini')),
        'reply' => env('OPENAI_FALLBACK_MODEL_REPLY', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini')),
        'summary' => env('OPENAI_FALLBACK_MODEL_SUMMARY', env('OPENAI_MODEL_SUMMARY', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini'))),

        // Baru
        'reply_draft' => env('OPENAI_FALLBACK_MODEL_REPLY_DRAFT', env('OPENAI_MODEL_REPLY_DRAFT', env('OPENAI_MODEL_REPLY', 'gpt-4o-mini'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Aliases (Kompatibilitas Lama)
    |--------------------------------------------------------------------------
    | Patch 8: bantu transisi nama task.
    */
    'task_aliases' => [
        'intent' => 'understanding',
        'reply' => 'reply_draft',
        'grounded' => 'grounded_response',
        'summary' => 'summary',
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability / Telemetry
    |--------------------------------------------------------------------------
    | Patch 9: tambahkan include_* flags untuk metadata runtime standar.
    | Key lama tetap dipertahankan.
    */
    'telemetry' => [
        // Lama
        'enabled' => (bool) env('OPENAI_TELEMETRY_ENABLED', true),
        'log_prompts' => (bool) env('OPENAI_LOG_PROMPTS', true),
        'log_responses' => (bool) env('OPENAI_LOG_RESPONSES', true),
        'truncate_prompt_chars' => (int) env('OPENAI_TRUNCATE_PROMPT_CHARS', 6000),
        'truncate_response_chars' => (int) env('OPENAI_TRUNCATE_RESPONSE_CHARS', 6000),

        // Baru (metadata standar)
        'include_provider' => env('OPENAI_TELEMETRY_INCLUDE_PROVIDER', true),
        'include_model' => env('OPENAI_TELEMETRY_INCLUDE_MODEL', true),
        'include_latency' => env('OPENAI_TELEMETRY_INCLUDE_LATENCY', true),
        'include_http_status' => env('OPENAI_TELEMETRY_INCLUDE_HTTP_STATUS', true),
        'include_reasoning_effort' => env('OPENAI_TELEMETRY_INCLUDE_REASONING_EFFORT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Contract
    |--------------------------------------------------------------------------
    | Patch 10: ekspektasi format output per task.
    */
    'json_contract' => [
        'understanding_expect_json' => env('OPENAI_JSON_UNDERSTANDING', true),
        'reply_draft_expect_json' => env('OPENAI_JSON_REPLY_DRAFT', true),
        'grounded_response_expect_json' => env('OPENAI_JSON_GROUNDED_RESPONSE', true),
        'summary_expect_json' => env('OPENAI_JSON_SUMMARY', true),
    ],
];
