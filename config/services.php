<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'reengagement_template_enabled' => (bool) env('WHATSAPP_REENGAGEMENT_TEMPLATE_ENABLED', true),
        'reengagement_template_name' => env('WHATSAPP_REENGAGEMENT_TEMPLATE_NAME', ''),
        'reengagement_template_language' => env('WHATSAPP_REENGAGEMENT_TEMPLATE_LANGUAGE', 'id'),
        'reengagement_template_components_json' => env('WHATSAPP_REENGAGEMENT_TEMPLATE_COMPONENTS_JSON', ''),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 90),

        'model_intent' => env('OPENAI_MODEL_INTENT', 'gpt-5.4-mini'),
        'model_extraction' => env('OPENAI_MODEL_EXTRACTION', 'gpt-5.4-mini'),
        'model_reply' => env('OPENAI_MODEL_REPLY', 'gpt-5.4'),
        'model_summary' => env('OPENAI_MODEL_SUMMARY', 'gpt-5.4-mini'),

        'reasoning_effort_intent' => env('OPENAI_REASONING_EFFORT_INTENT', 'low'),
        'reasoning_effort_extraction' => env('OPENAI_REASONING_EFFORT_EXTRACTION', 'low'),
        'reasoning_effort_reply' => env('OPENAI_REASONING_EFFORT_REPLY', 'medium'),
        'reasoning_effort_summary' => env('OPENAI_REASONING_EFFORT_SUMMARY', 'low'),

        'max_output_tokens_intent' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_INTENT', 300),
        'max_output_tokens_extraction' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_EXTRACTION', 500),
        'max_output_tokens_reply' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_REPLY', 700),
        'max_output_tokens_summary' => (int) env('OPENAI_MAX_OUTPUT_TOKENS_SUMMARY', 250),
    ],

];
