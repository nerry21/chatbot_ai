<?php

$legacyWhatsAppGraphUrl = rtrim((string) env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com/v19.0'), '/');
$legacyWhatsAppGraphApiVersion = 'v19.0';

if (preg_match('#/(v\d+\.\d+)$#', $legacyWhatsAppGraphUrl, $legacyWhatsAppGraphVersionMatch) === 1) {
    $legacyWhatsAppGraphApiVersion = $legacyWhatsAppGraphVersionMatch[1];
}

$legacyWhatsAppGraphBaseUrl = preg_replace('#/v\d+\.\d+$#', '', $legacyWhatsAppGraphUrl) ?: 'https://graph.facebook.com';

return [

    /*
    |--------------------------------------------------------------------------
    | Global Auto-Reply Kill Switch
    |--------------------------------------------------------------------------
    |
    | Master toggle untuk SEMUA auto-reply chatbot. Saat false, pesan
    | customer tetap diterima dan disimpan ke conversation log, tapi
    | bot tidak akan generate auto-reply. Admin balas manual via dashboard.
    | Digunakan saat refaktor besar atau maintenance window.
    |
    */
    'global_auto_reply_enabled' => (bool) env('CHATBOT_GLOBAL_AUTO_REPLY_ENABLED', true),

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
            'grounded_response' => env('OPENAI_MODEL_GROUNDED_RESPONSE', 'gpt-4o-mini'),
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
        'max_recent_messages' => (int) env('CHATBOT_MAX_RECENT_MESSAGES', 6),
        'history_max_age_minutes' => (int) env('CHATBOT_HISTORY_MAX_AGE_MINUTES', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Agent (Track A — agentic loop with tool calling)
    |--------------------------------------------------------------------------
    | When enabled=true, ProcessIncomingWhatsAppMessage routes inbound
    | messages to LlmAgentOrchestratorService instead of the rule-based
    | pipeline. Defaults to false in prod; turn on per-environment.
    */

    'agent' => [
        'enabled'        => (bool) env('CHATBOT_USE_LLM_AGENT', false),
        'model'          => env('OPENAI_MODEL_AGENT', 'gpt-5.4-mini'),
        'max_iterations' => (int) env('CHATBOT_AGENT_MAX_ITERATIONS', 5),
        'history_size'   => (int) env('CHATBOT_AGENT_HISTORY_SIZE', 10),
        'temperature'    => (float) env('CHATBOT_AGENT_TEMPERATURE', 0.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Behaviour
    |--------------------------------------------------------------------------
    */

    'prompts' => [
        'language' => env('CHATBOT_PROMPT_LANGUAGE', 'id'),
        'style'    => env('CHATBOT_PROMPT_STYLE', 'sopan_ringkas'),
        'business_domain' => env('CHATBOT_BUSINESS_DOMAIN', 'travel'),
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
    | - repair_corrupted_state     — if conversation state becomes inconsistent,
    |                                recompute the most sensible next step
    |                                instead of letting the bot get stuck.
    |
    */

    'guards' => [
        'close_intents' => [
            'oke',
            'ok',
            'baik',
            'siap',
            'sip',
            'mantap',
            'sudah',
            'benar',
            'iya',
            'ya',
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
        'unavailable_followup_reply'  => 'Baik, jika ingin saya bantu cek lagi, silakan kirim rute, jadwal, atau detail perjalanan baru yang ingin dicoba.',
        'unavailable_close_reply'     => 'Baik, terima kasih. Jika nanti Anda ingin cek jadwal, rute, atau booking travel lainnya, silakan kirim detail barunya ya.',
        'repair_corrupted_state'      => (bool) env('CHATBOT_REPAIR_CORRUPTED_STATE', true),
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
            'passenger_count',
            'departure_date',
            'departure_time',
            'selected_seats',
            'pickup_location',
            'pickup_full_address',
            'destination',
            'destination_full_address',
            'passenger_name',
            'contact_number',
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
    |----------------------------------------------------------------------
    | JET (Jasa Executive Travel) Business Rules
    |----------------------------------------------------------------------
    */

    'jet' => [
        'business_name' => 'JET (Jasa Executive Travel)',
        'timezone'      => 'Asia/Jakarta',
        'admin_phone'   => env('JET_ADMIN_PHONE', '6281267975175'),

        // Daftar nomor admin yang menerima notifikasi booking review.
        // Semua nomor di array ini akan menerima review reguler, dropping, rental, dan paket.
        // Format: E.164 tanpa tanda + (contoh: 6281267975175)
        'admin_phones'  => array_filter(array_map('trim', explode(',', env('JET_ADMIN_PHONES', '6281267975175,6282364210642')))),

        'passenger' => [
            'standard_max'        => 5,
            'manual_confirm_max'  => 6,
        ],

        'seat_hold_minutes' => (int) env('JET_SEAT_HOLD_MINUTES', 30),

        'departure_slots' => [
            [
                'id'      => 'slot_1',
                'order'   => 1,
                'label'   => 'Subuh (05.30 WIB)',
                'time'    => '05:30',
                'aliases' => ['1', 'subuh', '05', '05.30', '05:30', 'jam 5', 'jam 05'],
            ],
            [
                'id'      => 'slot_2',
                'order'   => 2,
                'label'   => 'Pagi (07.00 WIB)',
                'time'    => '07:00',
                'aliases' => ['2', 'pagi 7', '07', '07.00', '07:00', 'jam 7', 'jam 07', '07 pagi'],
            ],
            [
                'id'      => 'slot_3',
                'order'   => 3,
                'label'   => 'Pagi (09.00 WIB)',
                'time'    => '09:00',
                'aliases' => ['3', 'pagi 9', '09', '09.00', '09:00', 'jam 9', 'jam 09', '09 pagi'],
            ],
            [
                'id'      => 'slot_4',
                'order'   => 4,
                'label'   => 'Siang (13.00 WIB)',
                'time'    => '13:00',
                'aliases' => ['4', 'siang', '13', '13.00', '13:00', 'jam 13', 'jam 1 siang'],
            ],
            [
                'id'      => 'slot_5',
                'order'   => 5,
                'label'   => 'Sore (16.00 WIB)',
                'time'    => '16:00',
                'aliases' => ['5', 'sore', '16', '16.00', '16:00', 'jam 16'],
            ],
            [
                'id'      => 'slot_6',
                'order'   => 6,
                'label'   => 'Malam (19.00 WIB)',
                'time'    => '19:00',
                'aliases' => ['6', 'malam', '19', '19.00', '19:00', 'jam 19'],
            ],
        ],

        'payment_methods' => [
            [
                'id' => 'transfer',
                'label' => 'Transfer Bank',
                'aliases' => ['transfer', 'tf', 'transfer bank', 'bank transfer'],
            ],
            [
                'id' => 'qris',
                'label' => 'QRIS',
                'aliases' => ['qris', 'qr', 'scan qris'],
            ],
            [
                'id' => 'cash',
                'label' => 'Cash',
                'aliases' => ['cash', 'tunai', 'bayar cash', 'bayar tunai'],
            ],
        ],

        'locations' => [
            // Section 1: Rambah Hilir / Rambah / Rambah Samo
            ['order' => 1,  'label' => 'SKPD',             'menu' => true,  'aliases' => ['skpd']],
            ['order' => 2,  'label' => 'Simpang D',        'menu' => true,  'aliases' => ['simpang d', 'simpangd']],
            ['order' => 3,  'label' => 'SKPC',             'menu' => true,  'aliases' => ['skpc']],
            ['order' => 4,  'label' => 'SKPA',             'menu' => true,  'aliases' => ['skpa']],
            ['order' => 5,  'label' => 'SKPB',             'menu' => true,  'aliases' => ['skpb']],
            ['order' => 6,  'label' => 'Simpang Kumu',     'menu' => true,  'aliases' => ['simpang kumu', 'simpangkumu']],
            ['order' => 7,  'label' => 'Muara Rumbai',     'menu' => true,  'aliases' => ['muara rumbai', 'muararumbai']],
            ['order' => 8,  'label' => 'Surau Tinggi',     'menu' => true,  'aliases' => ['surau tinggi', 'surautinggi']],
            ['order' => 9,  'label' => 'Pasir Pengaraian', 'menu' => true,  'aliases' => ['pasirpengaraian', 'pasir pengaraian']],

            // Section 2: Ujung Batu / Tandun
            ['order' => 10, 'label' => 'Ujung Batu',       'menu' => true,  'aliases' => ['ujung batu', 'ujungbatu']],
            ['order' => 11, 'label' => 'Tandun',           'menu' => true,  'aliases' => ['tandun']],

            // Section 3: Kampar / Pekanbaru
            ['order' => 12, 'label' => 'Petapahan',        'menu' => true,  'aliases' => ['petapahan']],
            ['order' => 13, 'label' => 'Suram',            'menu' => true,  'aliases' => ['suram']],
            ['order' => 14, 'label' => 'Aliantan',         'menu' => true,  'aliases' => ['aliantan']],
            ['order' => 15, 'label' => 'Kuok',             'menu' => true,  'aliases' => ['kuok']],
            ['order' => 16, 'label' => 'Kabun',            'menu' => true,  'aliases' => ['kabun']],
            ['order' => 17, 'label' => 'Bangkinang',       'menu' => true,  'aliases' => ['bangkinang']],
            ['order' => 18, 'label' => 'Silam',            'menu' => true,  'aliases' => ['silam']],
            ['order' => 19, 'label' => 'Pekanbaru',        'menu' => true,  'aliases' => ['pekanbaru', 'pku']],
        ],

        'fare_rules' => [
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Pekanbaru'],
                'amount'       => 150000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Kabun'],
                'amount'       => 120000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Tandun'],
                'amount'       => 100000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Petapahan'],
                'amount'       => 130000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Suram'],
                'amount'       => 120000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Aliantan'],
                'amount'       => 120000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['SKPD', 'Simpang D', 'SKPC', 'SKPA', 'SKPB', 'Simpang Kumu', 'Muara Rumbai', 'Surau Tinggi', 'Pasir Pengaraian'],
                'b'            => ['Bangkinang'],
                'amount'       => 130000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['Bangkinang'],
                'b'            => ['Pekanbaru'],
                'amount'       => 100000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['Ujung Batu'],
                'b'            => ['Pekanbaru'],
                'amount'       => 130000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['Suram'],
                'b'            => ['Pekanbaru'],
                'amount'       => 120000,
                'bidirectional'=> true,
            ],
            [
                'a'            => ['Petapahan'],
                'b'            => ['Pekanbaru'],
                'amount'       => 100000,
                'bidirectional'=> true,
            ],
        ],

        'seat_labels' => [
            'CC',
            'BS Kiri',
            'BS Kanan',
            'BS Tengah',
            'Belakang Kiri',
            'Belakang Kanan',
        ],

        // Seats that require admin confirmation before booking can proceed.
        'seat_requires_admin_confirmation' => [
            'BS Tengah',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile Live Chat (Tahap 1)
    |--------------------------------------------------------------------------
    |
    | enabled                — master switch for Flutter/mobile live chat API.
    | poll_interval_ms       — recommended polling interval returned to clients.
    | max_messages_per_fetch — hard cap per conversation detail/poll request.
    | max_conversations      — hard cap for conversation list responses.
    |
    */

    'mobile_live_chat' => [
        'enabled' => (bool) env('MOBILE_LIVE_CHAT_ENABLED', true),
        'poll_interval_ms' => (int) env('MOBILE_LIVE_CHAT_POLL_INTERVAL_MS', 3000),
        'max_messages_per_fetch' => (int) env('MOBILE_LIVE_CHAT_MAX_MESSAGES_PER_FETCH', 120),
        'max_conversations' => (int) env('MOBILE_LIVE_CHAT_MAX_CONVERSATIONS', 20),
        'auth_token_ttl_days' => (int) env('MOBILE_LIVE_CHAT_AUTH_TOKEN_TTL_DAYS', 30),
        'default_source_app' => env('MOBILE_LIVE_CHAT_SOURCE_APP', 'flutter'),
    ],

    'admin_mobile' => [
        'poll_interval_ms' => (int) env('ADMIN_MOBILE_POLL_INTERVAL_MS', 3000),
        'max_messages_per_fetch' => (int) env('ADMIN_MOBILE_MAX_MESSAGES_PER_FETCH', 120),
        'default_per_page' => (int) env('ADMIN_MOBILE_DEFAULT_PER_PAGE', 18),
        'max_per_page' => (int) env('ADMIN_MOBILE_MAX_PER_PAGE', 50),
        'auth_token_ttl_days' => (int) env('ADMIN_MOBILE_AUTH_TOKEN_TTL_DAYS', 30),
        'bot_auto_resume_after_minutes' => (int) env('ADMIN_MOBILE_BOT_AUTO_RESUME_AFTER_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM Engine
    |--------------------------------------------------------------------------
    |
    | crm.enabled  — master switch for all CRM operations.
    | jet_crm.*    — JET in-house CRM adapter (local-only). Always-on;
    |               all reads/writes go to MariaDB via Eloquent models.
    |
    */

    'crm' => [

        'enabled' => (bool) env('CHATBOT_CRM_ENABLED', env('CRM_ENABLED', true)),

        'jet_crm' => [
            'enabled' => true,
        ],

        'ai_context' => [
            'enabled' => (bool) env('CRM_AI_CONTEXT_ENABLED', true),
            'ttl_seconds' => (int) env('CRM_AI_CONTEXT_TTL_SECONDS', 600),
            'include_in_intent_tasks' => (bool) env('CRM_AI_CONTEXT_INCLUDE_IN_INTENT', true),
            'include_in_extraction_tasks' => (bool) env('CRM_AI_CONTEXT_INCLUDE_IN_EXTRACTION', true),
            'include_in_reply_tasks' => (bool) env('CRM_AI_CONTEXT_INCLUDE_IN_REPLY', true),
        ],

        'decision_trace' => [
            'enabled' => (bool) env('CRM_DECISION_TRACE_ENABLED', true),
            'include_understanding_meta' => (bool) env('CRM_DECISION_TRACE_INCLUDE_UNDERSTANDING_META', true),
            'include_grounding_meta' => (bool) env('CRM_DECISION_TRACE_INCLUDE_GROUNDING_META', true),
            'include_policy_meta' => (bool) env('CRM_DECISION_TRACE_INCLUDE_POLICY_META', true),
            'include_hallucination_meta' => (bool) env('CRM_DECISION_TRACE_INCLUDE_HALLUCINATION_META', true),
        ],

        'notes' => [
            'include_technical_runtime' => env('CHATBOT_CRM_NOTES_INCLUDE_TECHNICAL_RUNTIME', false),
        ],

        'writeback' => [
            'append_summary_note' => (bool) env('CRM_WRITEBACK_APPEND_SUMMARY_NOTE', true),
            'append_decision_note' => (bool) env('CRM_WRITEBACK_APPEND_DECISION_NOTE', true),
            'sync_contact_snapshot' => (bool) env('CRM_WRITEBACK_SYNC_CONTACT_SNAPSHOT', true),
            'sync_lead_pipeline' => (bool) env('CRM_WRITEBACK_SYNC_LEAD_PIPELINE', true),

            'contact_sync_enabled' => env('CHATBOT_CRM_CONTACT_SYNC_ENABLED', true),
            'summary_sync_enabled' => env('CHATBOT_CRM_SUMMARY_SYNC_ENABLED', true),
            'decision_note_sync_enabled' => env('CHATBOT_CRM_DECISION_NOTE_SYNC_ENABLED', true),
            'lead_sync_enabled' => env('CHATBOT_CRM_LEAD_SYNC_ENABLED', true),
            'escalation_sync_enabled' => env('CHATBOT_CRM_ESCALATION_SYNC_ENABLED', true),
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
        'console_login' => [
            'email' => env('CHATBOT_ADMIN_LOGIN_EMAIL'),
            'password' => env('CHATBOT_ADMIN_LOGIN_PASSWORD'),
            'name' => env('CHATBOT_ADMIN_LOGIN_NAME', 'Chatbot Admin'),
        ],
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
    | Continuous Improvement Foundation
    |--------------------------------------------------------------------------
    |
    | enabled                    - Master switch for learning signal logging.
    | store_case_memory          - Persist successful/corrected cases for later retrieval.
    | case_memory_min_confidence - Minimum LLM understanding confidence before a
    |                              successful turn may become reusable memory.
    | case_memory_max_candidates - Upper bound on lightweight in-app retrieval scan.
    | case_memory_retrieval_limit - How many examples to return per retrieval call.
    | correction_window_hours    - Max age of the linked inbound/bot turn for
    |                              admin correction capture.
    |
    */

    'continuous_improvement' => [
        'enabled' => (bool) env('CHATBOT_CONTINUOUS_IMPROVEMENT_ENABLED', true),
        'store_case_memory' => (bool) env('CHATBOT_STORE_CASE_MEMORY', true),
        'case_memory_min_confidence' => (float) env('CHATBOT_CASE_MEMORY_MIN_CONFIDENCE', 0.75),
        'case_memory_max_candidates' => (int) env('CHATBOT_CASE_MEMORY_MAX_CANDIDATES', 30),
        'case_memory_retrieval_limit' => (int) env('CHATBOT_CASE_MEMORY_RETRIEVAL_LIMIT', 3),
        'correction_window_hours' => (int) env('CHATBOT_CORRECTION_WINDOW_HOURS', 24),
    ],

    // Centralised behavior and safety configuration
    'understanding' => [
        'mode' => env('CHATBOT_UNDERSTANDING_MODE', 'llm_first_with_crm_hints_only'),

        'confidence' => [
            'low_threshold' => (float) env('CHATBOT_UNDERSTANDING_CONFIDENCE_LOW', 0.30),
            'clarify_threshold' => (float) env('CHATBOT_UNDERSTANDING_CONFIDENCE_CLARIFY', 0.45),
            'high_threshold' => (float) env('CHATBOT_UNDERSTANDING_CONFIDENCE_HIGH', 0.80),
        ],

        'clarification' => [
            'force_for_unknown_low_confidence' => env('CHATBOT_UNDERSTANDING_FORCE_CLARIFY_UNKNOWN', true),
            'force_for_ambiguous_short_reply' => env('CHATBOT_UNDERSTANDING_FORCE_CLARIFY_SHORT_REPLY', true),
        ],
    ],

    'runtime_health' => [
        'fallback_actions' => [
            'force_handoff' => env('CHATBOT_RUNTIME_FALLBACK_FORCE_HANDOFF', true),
        ],

        'schema_invalid_actions' => [
            'force_handoff' => env('CHATBOT_RUNTIME_SCHEMA_INVALID_FORCE_HANDOFF', true),
        ],

        'degraded_actions' => [
            'force_clarification' => env('CHATBOT_RUNTIME_DEGRADED_FORCE_CLARIFY', true),
            'max_confidence' => (float) env('CHATBOT_RUNTIME_DEGRADED_MAX_CONFIDENCE', 0.55),
        ],
    ],

    'policy_guard' => [
        'admin_takeover_blocks_reply' => env('CHATBOT_POLICY_ADMIN_TAKEOVER_BLOCKS_REPLY', true),
        'bot_paused_blocks_reply' => env('CHATBOT_POLICY_BOT_PAUSED_BLOCKS_REPLY', true),

        'clarification' => [
            'enabled' => env('CHATBOT_POLICY_CLARIFICATION_ENABLED', true),
        ],

        'handoff' => [
            'enabled' => env('CHATBOT_POLICY_HANDOFF_ENABLED', true),
        ],
    ],

    'grounding_guard' => [
        'enabled' => env('CHATBOT_GROUNDING_GUARD_ENABLED', true),

        'risk_thresholds' => [
            'high_requires_handoff' => env('CHATBOT_GROUNDING_HIGH_HANDOFF', true),
            'medium_requires_clarification' => env('CHATBOT_GROUNDING_MEDIUM_CLARIFY', true),
        ],

        'allow_candidate_grounded_reply' => env('CHATBOT_GROUNDING_ALLOW_CANDIDATE_REPLY', true),
    ],

    'final_guard' => [
        'reply_max_length' => (int) env('CHATBOT_FINAL_REPLY_MAX_LENGTH', 1500),

        'safe_fallback' => [
            'enabled' => env('CHATBOT_FINAL_SAFE_FALLBACK_ENABLED', true),
            'text' => env(
                'CHATBOT_FINAL_SAFE_FALLBACK_TEXT',
                'Baik, agar saya tidak keliru, boleh dijelaskan lagi detail kebutuhan atau pertanyaannya ya?'
            ),
        ],

        'actions' => [
            'allow_send_reply' => env('CHATBOT_FINAL_ALLOW_SEND_REPLY', true),
            'allow_ask_clarification' => env('CHATBOT_FINAL_ALLOW_ASK_CLARIFICATION', true),
            'allow_escalate_to_human' => env('CHATBOT_FINAL_ALLOW_ESCALATE_TO_HUMAN', true),
        ],
    ],

    'reply_orchestrator' => [
        'candidate_only' => env('CHATBOT_REPLY_ORCHESTRATOR_CANDIDATE_ONLY', true),
        'delegate_final_decision_to' => env('CHATBOT_REPLY_ORCHESTRATOR_FINAL_DECIDER', 'conversation_reply_guard'),
    ],

    'grounded_response' => [
        'candidate_only' => env('CHATBOT_GROUNDED_RESPONSE_CANDIDATE_ONLY', true),
        'allow_faq' => env('CHATBOT_GROUNDED_RESPONSE_ALLOW_FAQ', true),
        'allow_knowledge_hits' => env('CHATBOT_GROUNDED_RESPONSE_ALLOW_KNOWLEDGE', true),
        'allow_crm_grounding' => env('CHATBOT_GROUNDED_RESPONSE_ALLOW_CRM', true),
    ],

    'webhook' => [
        'dedup_enabled' => env('CHATBOT_WEBHOOK_DEDUP_ENABLED', true),
        'log_payload_summary' => env('CHATBOT_WEBHOOK_LOG_PAYLOAD_SUMMARY', true),
    ],

    'decision_trace' => [
        'enabled' => env('CHATBOT_DECISION_TRACE_ENABLED', true),
        'version' => env('CHATBOT_DECISION_TRACE_VERSION', 'v2'),
        'include_legacy_projection' => env('CHATBOT_DECISION_TRACE_INCLUDE_LEGACY', true),
    ],

    'feature_flags' => [
        'llm_first_understanding' => env('CHATBOT_FEATURE_LLM_FIRST_UNDERSTANDING', true),
        'crm_hints_only_for_understanding' => env('CHATBOT_FEATURE_CRM_HINTS_ONLY_UNDERSTANDING', true),
        'policy_structured_verdict' => env('CHATBOT_FEATURE_POLICY_STRUCTURED_VERDICT', true),
        'grounding_structured_verdict' => env('CHATBOT_FEATURE_GROUNDING_STRUCTURED_VERDICT', true),
        'final_guard_structured_action' => env('CHATBOT_FEATURE_FINAL_GUARD_STRUCTURED_ACTION', true),
        'crm_structured_writeback_report' => env('CHATBOT_FEATURE_CRM_STRUCTURED_WRITEBACK_REPORT', true),
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
    | interactive_text_fallback_enabled — if provider rejects an interactive
    |                                     payload, retry once as plain text
    |                                     using the built-in numbered fallback.
    |
    | If phone_number_id or access_token is empty, isEnabled() returns false and
    | all sends are skipped gracefully — no fatal errors.
    |
    */

    'whatsapp' => [

        'enabled'              => (bool) env('WHATSAPP_ENABLED', false),
        'graph_base_url'       => $legacyWhatsAppGraphUrl,
        'phone_number_id'      => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token'         => env('WHATSAPP_ACCESS_TOKEN', ''),
        'verify_token'         => env('WHATSAPP_VERIFY_TOKEN', ''),
        'webhook_secret'       => env('WHATSAPP_WEBHOOK_SECRET', ''),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '62'),
        'send_timeout_seconds' => (int) env('WHATSAPP_SEND_TIMEOUT_SECONDS', 15),
        'interactive_enabled'  => (bool) env('WHATSAPP_INTERACTIVE_ENABLED', true),
        'interactive_text_fallback_enabled' => (bool) env('WHATSAPP_INTERACTIVE_TEXT_FALLBACK_ENABLED', true),
        'calling' => [
            'enabled' => (bool) env('WHATSAPP_CALLING_ENABLED', env('WHATSAPP_ENABLED', false)),
            'base_url' => rtrim((string) env('WHATSAPP_CALLING_BASE_URL', env('WHATSAPP_GRAPH_API_BASE_URL', $legacyWhatsAppGraphBaseUrl)), '/'),
            'api_version' => (string) env('WHATSAPP_CALLING_API_VERSION', env('WHATSAPP_GRAPH_API_VERSION', $legacyWhatsAppGraphApiVersion)),
            'access_token' => (string) env('WHATSAPP_CALLING_ACCESS_TOKEN', env('WHATSAPP_ACCESS_TOKEN', '')),
            'phone_number_id' => (string) env('WHATSAPP_CALLING_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID', '')),
            'waba_id' => (string) env('WHATSAPP_CALLING_WABA_ID', env('WHATSAPP_WABA_ID', '')),
            'timeout_seconds' => (int) env('WHATSAPP_CALL_TIMEOUT_SECONDS', 20),
            'verify_ssl' => (bool) env('WHATSAPP_CALL_VERIFY_SSL', true),
            'permission_request_enabled' => (bool) env('WHATSAPP_CALL_PERMISSION_REQUEST_ENABLED', true),
            'default_permission_ttl_minutes' => (int) env('WHATSAPP_CALL_DEFAULT_PERMISSION_TTL_MINUTES', 1440),
            'permission_cooldown_seconds' => (int) env('WHATSAPP_CALL_PERMISSION_COOLDOWN_SECONDS', 120),
            'start_cooldown_seconds' => (int) env('WHATSAPP_CALL_START_COOLDOWN_SECONDS', 15),
            'rate_limit_backoff_seconds' => (int) env('WHATSAPP_CALL_RATE_LIMIT_BACKOFF_SECONDS', 60),
            'rate_limit_cooldown_seconds' => (int) env('WHATSAPP_CALL_RATE_LIMIT_COOLDOWN_SECONDS', 180),
            'retry_enabled' => (bool) env('WHATSAPP_CALL_RETRY_ENABLED', true),
            'max_retries' => (int) env('WHATSAPP_CALL_MAX_RETRIES', 2),
            'retry_backoff_ms' => (int) env('WHATSAPP_CALL_RETRY_BACKOFF_MS', 350),
            'dedup_enabled' => (bool) env('WHATSAPP_CALL_DEDUP_ENABLED', true),
            'webhook_signature_enabled' => (bool) env('WHATSAPP_CALL_WEBHOOK_SIGNATURE_ENABLED', false),
            'action_lock_seconds' => (int) env('WHATSAPP_CALL_ACTION_LOCK_SECONDS', 8),
            'eligibility_cache_enabled' => env('WHATSAPP_CALLING_ELIGIBILITY_CACHE_ENABLED', true),
            'eligibility_cache_ttl_seconds' => (int) env('WHATSAPP_CALLING_ELIGIBILITY_CACHE_TTL_SECONDS', 600),
            'log_verbose' => (bool) env('WHATSAPP_CALL_LOG_VERBOSE', false),
        ],

    ],

];