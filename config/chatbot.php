<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM Provider Settings
    |--------------------------------------------------------------------------
    */

    'llm' => [
        // Set to false to disable all LLM calls and use safe mock responses.
        'enabled'         => (bool) env('LLM_ENABLED', true),
        'provider'        => env('LLM_PROVIDER', 'openai'),
        'timeout_seconds' => (int) env('LLM_TIMEOUT_SECONDS', 30),

        'models' => [
            'intent'     => env('OPENAI_MODEL_INTENT',     'gpt-4o-mini'),
            'extraction' => env('OPENAI_MODEL_EXTRACTION', 'gpt-4o-mini'),
            'reply'      => env('OPENAI_MODEL_REPLY',      'gpt-4o-mini'),
            'summary'    => env('OPENAI_MODEL_SUMMARY',    'gpt-4o-mini'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory / Context Window
    |--------------------------------------------------------------------------
    */

    'memory' => [
        'max_recent_messages' => (int) env('CHATBOT_MAX_RECENT_MESSAGES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Behaviour
    |--------------------------------------------------------------------------
    */

    'prompts' => [
        'language' => env('CHATBOT_PROMPT_LANGUAGE', 'id'),
        'style'    => env('CHATBOT_PROMPT_STYLE', 'sopan_ringkas'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Guards
    |--------------------------------------------------------------------------
    |
    | Small deterministic rules that protect the bot from awkward loops:
    | - close_intents              — short acknowledgements treated as a polite
    |                                close when the conversation is stuck on an
    |                                unavailable route/slot.
    | - unavailable_state_key      — conversation_states key used to remember the
    |                                last unavailable/unsupported-route reply.
    | - unavailable_state_ttl_hours — automatically expire stale unavailable state.
    | - unavailable_followup_reply — sent instead of repeating the same
    |                                unavailable reply when the customer has not
    |                                provided any new relevant booking data.
    | - unavailable_close_reply    — sent when a close intent is detected while
    |                                unavailable state is active.
    |
    */

    'guards' => [
        'close_intents' => [
            'oke',
            'ok',
            'baik',
            'siap',
            'tidak ada',
            'ga ada',
            'nggak ada',
            'tidak',
            'ya sudah',
            'makasih',
            'terima kasih',
        ],
        'close_intent_courtesy_tails' => [
            'ya',
            'yah',
            'kak',
            'kakak',
            'min',
            'admin',
            'mas',
            'mba',
            'mbak',
            'bang',
            'bro',
            'sis',
        ],
        'unavailable_state_key'       => 'route_unavailable_context',
        'unavailable_state_ttl_hours' => (int) env('CHATBOT_UNAVAILABLE_STATE_TTL_HOURS', 24),
        'unavailable_followup_reply'  => 'Baik, kalau ingin saya cek lagi, silakan kirim rute atau detail perjalanan baru yang ingin dicoba.',
        'unavailable_close_reply'     => 'Baik, terima kasih. Jika nanti Anda ingin cek rute atau jadwal lain, silakan kirim detail barunya ya.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking Engine
    |--------------------------------------------------------------------------
    |
    | Route table format:
    |   'normalized_pickup (lowercase)' => [
    |       'normalized_destination (lowercase)' => base_price_per_seat (IDR integer)
    |   ]
    |
    | To move routes to database in Tahap 5+, replace this array with a DB query
    | in RouteValidationService / PricingService and remove this key.
    |
    */

    'booking' => [

        // Fields that must be non-null before a booking can be sent for confirmation.
        'required_fields' => [
            'pickup_location',
            'destination',
            'passenger_name',
            'passenger_count',
        ],

        // Base price per seat in Indonesian Rupiah (IDR).
        // Key: lowercase normalized pickup → lowercase normalized destination → IDR.
        'routes' => [
            'ujung batu' => [
                'pekanbaru' => 150_000,
            ],
            'pasir pengaraian' => [
                'pekanbaru' => 120_000,
            ],
            'kabun' => [
                'pekanbaru' => 100_000,
            ],
            'pekanbaru' => [
                'ujung batu'       => 150_000,
                'pasir pengaraian' => 120_000,
                'kabun'            => 100_000,
            ],
        ],

        // Passenger multiplier applied when passenger_count > 1.
        // 1.0 = full price per seat (no group discount).
        // 0.9 = 10% group discount per seat.
        'passenger_multiplier' => (float) env('BOOKING_PASSENGER_MULTIPLIER', 1.0),

    ],

    /*
    |--------------------------------------------------------------------------
    | CRM Engine
    |--------------------------------------------------------------------------
    |
    | crm.enabled  — master switch for all CRM operations.
    | hubspot.*    — HubSpot-specific settings.
    |               Set hubspot.enabled=false (or leave token empty) to run
    |               in local-only mode without any external API calls.
    |
    */

    'crm' => [

        'enabled' => (bool) env('CRM_ENABLED', true),

        'hubspot' => [
            'enabled' => (bool) env('HUBSPOT_ENABLED', false),
            'token'   => env('HUBSPOT_ACCESS_TOKEN', ''),
            // HubSpot portal/account ID — used for building deep links if needed.
            'portal_id' => env('HUBSPOT_PORTAL_ID', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Security — Admin Area Access (Tahap 8)
    |--------------------------------------------------------------------------
    |
    | require_chatbot_admin  — When true, only users with is_chatbot_admin = true
    |                           or is_chatbot_operator = true (+ allow_operator)
    |                           can access /admin/chatbot/*.
    |                           Set to false during development to allow any
    |                           authenticated user.
    |
    | allow_operator_actions — When true, users with is_chatbot_operator = true
    |                           are granted access alongside full admins.
    |
    */

    'security' => [
        'require_chatbot_admin'   => (bool) env('CHATBOT_REQUIRE_ADMIN', true),
        'allow_operator_actions'  => (bool) env('CHATBOT_ALLOW_OPERATOR_ACTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational Notifications (Tahap 8)
    |--------------------------------------------------------------------------
    |
    | enabled                            — Master switch for all notification creation.
    | create_on_inbound_during_takeover  — Create a notification when a customer sends
    |                                       a message while admin takeover is active.
    | create_on_message_failed           — Create a notification when outbound WhatsApp
    |                                       delivery fails.
    | create_on_new_escalation           — Handled unconditionally by EscalateConversationToAdminJob.
    | create_on_takeover                 — Create a notification when admin takes over.
    | create_on_release                  — Create a notification when admin releases to bot.
    |
    */

    'notifications' => [
        'enabled'                           => (bool) env('CHATBOT_NOTIFICATIONS_ENABLED', true),
        'create_on_inbound_during_takeover' => true,
        'create_on_message_failed'          => true,
        'create_on_new_escalation'          => true,  // Currently enforced in EscalateConversationToAdminJob
        'create_on_takeover'                => true,
        'create_on_release'                 => false, // Off by default — enable if you want audit trail for releases
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Log (Tahap 8)
    |--------------------------------------------------------------------------
    |
    | enabled — Master switch. Set to false to disable all audit_logs writes.
    |            Useful for local dev or if you want to minimise DB writes.
    |
    */

    'audit' => [
        'enabled' => (bool) env('CHATBOT_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability & Retry Strategy (Tahap 9)
    |--------------------------------------------------------------------------
    |
    | max_send_attempts      — Hard cap on how many times a single outbound message
    |                           may be attempted. Enforced in SendWhatsAppMessageJob
    |                           and validated before manual resend from dashboard.
    |
    | retry_failed_messages  — Allow resend of messages with delivery_status=failed
    |                           from the admin dashboard.
    |
    | resend_cooldown_minutes — Minimum gap (minutes) required between consecutive
    |                           manual resend attempts for the same message.
    |
    | create_notification_on_health_issue — Create an AdminNotification when the
    |                           health check command finds a warning or critical issue.
    |                           Simple deduplication: skipped if an unread health_issue
    |                           notification was created within the last 30 minutes.
    |
    */

    'reliability' => [

        'max_send_attempts'                   => (int)  env('CHATBOT_MAX_SEND_ATTEMPTS',           3),
        'retry_failed_messages'               => (bool) env('CHATBOT_RETRY_FAILED_MESSAGES',       true),
        'resend_cooldown_minutes'             => (int)  env('CHATBOT_RESEND_COOLDOWN_MINUTES',     5),
        'create_notification_on_health_issue' => (bool) env('CHATBOT_HEALTH_NOTIFY',               true),

        // ── Health check thresholds ────────────────────────────────────────
        'health' => [
            // Alert if the jobs table has more pending rows than this.
            'queue_backlog_threshold'    => (int) env('CHATBOT_HEALTH_QUEUE_BACKLOG_THRESHOLD',    50),
            // Alert if outbound messages with delivery_status=failed in last 24 h exceed this.
            'failed_message_threshold'   => (int) env('CHATBOT_HEALTH_FAILED_MESSAGE_THRESHOLD',   10),
            // Alert if open escalations exceed this count.
            'open_escalation_threshold'  => (int) env('CHATBOT_HEALTH_OPEN_ESCALATION_THRESHOLD',  20),
        ],

        // ── Cleanup thresholds ────────────────────────────────────────────
        'cleanup' => [
            // Delete read admin_notifications older than X days.
            'delete_old_read_notifications_days' => (int)  env('CHATBOT_CLEANUP_READ_NOTIFICATIONS_DAYS', 30),
            // Delete audit_logs older than X days.
            'delete_old_audit_logs_days'         => (int)  env('CHATBOT_CLEANUP_AUDIT_LOGS_DAYS',         90),
            // Delete ai_logs older than X days.
            'delete_old_ai_logs_days'            => (int)  env('CHATBOT_CLEANUP_AI_LOGS_DAYS',            60),
            // Delete escalations in resolved/closed status older than X days.
            'delete_old_closed_escalations_days' => (int)  env('CHATBOT_CLEANUP_CLOSED_ESCALATIONS_DAYS', 90),
            // Prune conversation_states with a past expires_at timestamp.
            'prune_expired_conversation_states'  => (bool) env('CHATBOT_CLEANUP_PRUNE_STATES',            true),
            // Default dry-run mode for chatbot:cleanup command.
            // Set to false in production scheduler entry (--dry-run=0).
            'dry_run_default'                    => (bool) env('CHATBOT_CLEANUP_DRY_RUN_DEFAULT',          true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge Base Retrieval (Tahap 10)
    |--------------------------------------------------------------------------
    |
    | enabled               — Master switch for knowledge retrieval.
    |                          When false, KnowledgeBaseService returns [] on every call.
    |
    | max_candidates        — Maximum number of active articles fetched from DB
    |                          before scoring.  Keep low to avoid heavy queries.
    |
    | max_in_prompt         — How many top-scored articles are injected into prompts.
    |                          Fewer = shorter prompts; more = richer context.
    |
    | min_keyword_match_score — Minimum score an article must reach to be included
    |                          in results.  Raising this makes retrieval stricter.
    |
    | include_in_reply_tasks      — Inject knowledge context into reply prompt.
    | include_in_intent_tasks     — Inject a compact knowledge hint into intent prompt.
    | include_in_extraction_tasks — Inject knowledge hint (e.g. location names) into
    |                               extraction prompt.
    |
    */

    'knowledge' => [
        'enabled'                   => (bool) env('CHATBOT_KNOWLEDGE_ENABLED', true),
        'max_candidates'            => (int)  env('CHATBOT_KNOWLEDGE_MAX_CANDIDATES', 30),
        'max_in_prompt'             => (int)  env('CHATBOT_KNOWLEDGE_MAX_IN_PROMPT', 3),
        'min_keyword_match_score'   => (float) env('CHATBOT_KNOWLEDGE_MIN_SCORE', 2.0),
        'include_in_reply_tasks'    => true,
        'include_in_intent_tasks'   => false,  // Keep intent prompt lean by default
        'include_in_extraction_tasks' => false, // Enable only if location names help
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Quality Evaluation (Tahap 10)
    |--------------------------------------------------------------------------
    |
    | enabled                        — Master switch for quality tracking writes.
    |
    | store_knowledge_hits           — After each pipeline run, update the relevant
    |                                   ai_log rows with knowledge_hits and quality_label.
    |
    | reply_fallback_on_low_confidence — When the intent confidence is below
    |                                   low_confidence_threshold AND no knowledge
    |                                   hit is available, use a more helpful
    |                                   contextual fallback reply instead of the
    |                                   raw LLM output.
    |
    | low_confidence_threshold       — Float 0–1.  Intent confidence at or below
    |                                   this value is flagged as low_confidence.
    |
    | dashboard_days_window          — Number of past days the AI quality overview
    |                                   card on the dashboard covers.
    |
    | sample_recent_logs_limit       — How many recent problematic logs are shown
    |                                   in the quality overview and admin pages.
    |
    */

    'ai_quality' => [
        'enabled'                        => (bool)  env('CHATBOT_AI_QUALITY_ENABLED', true),
        'store_knowledge_hits'           => (bool)  env('CHATBOT_AI_QUALITY_STORE_HITS', true),
        'reply_fallback_on_low_confidence' => (bool) env('CHATBOT_AI_LOW_CONF_FALLBACK', false),
        'low_confidence_threshold'       => (float) env('CHATBOT_AI_LOW_CONFIDENCE_THRESHOLD', 0.40),
        'dashboard_days_window'          => (int)   env('CHATBOT_AI_DASHBOARD_DAYS_WINDOW', 7),
        'sample_recent_logs_limit'       => (int)   env('CHATBOT_AI_SAMPLE_LOGS_LIMIT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Tahap 7)
    |--------------------------------------------------------------------------
    |
    | enabled             — master switch; set false to disable all outbound sends.
    | graph_base_url      — Facebook Graph API base URL (override for testing).
    | phone_number_id     — WhatsApp Business Phone Number ID from Meta dashboard.
    | access_token        — Permanent or temporary access token from Meta dashboard.
    | default_country_code — E.g. "62" for Indonesia; used for phone normalisation.
    | send_timeout_seconds — HTTP timeout for each send request.
    |
    | If phone_number_id or access_token is empty, isEnabled() returns false and
    | all sends are skipped gracefully — no fatal errors.
    |
    */

    'whatsapp' => [

        'enabled'              => (bool) env('WHATSAPP_ENABLED', false),
        'graph_base_url'       => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com/v19.0'),
        'phone_number_id'      => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token'         => env('WHATSAPP_ACCESS_TOKEN', ''),
        'verify_token'         => env('WHATSAPP_VERIFY_TOKEN', ''),
        'webhook_secret'       => env('WHATSAPP_WEBHOOK_SECRET', ''),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '62'),
        'send_timeout_seconds' => (int) env('WHATSAPP_SEND_TIMEOUT_SECONDS', 15),

    ],

];
