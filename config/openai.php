<?php

return [
    'enabled' => (bool) env('OPENAI_ENABLED', true),
    'seed_on_webhook' => (bool) env('OPENAI_SEED_ON_WEBHOOK', true),

    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'timeout' => (int) env('OPENAI_TIMEOUT', 90),

    'models' => [
        'intent' => env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini'),
        'extraction' => env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini'),
        'reply' => env('OPENAI_MODEL_REPLY', 'gpt-5.4'),
        'summary' => env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini'),
    ],

    'reasoning_effort' => [
        'intent' => env('OPENAI_REASONING_EFFORT_INTENT', 'low'),
        'extraction' => env('OPENAI_REASONING_EFFORT_EXTRACTION', 'low'),
        'reply' => env('OPENAI_REASONING_EFFORT_REPLY', 'medium'),
        'summary' => env('OPENAI_REASONING_EFFORT_SUMMARY', 'low'),
    ],

    'max_output_tokens' => [
        'intent' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_INTENT', 300),
        'extraction' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_EXTRACTION', 500),
        'reply' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_REPLY', 700),
        'summary' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_SUMMARY', 250),
    ],
];
