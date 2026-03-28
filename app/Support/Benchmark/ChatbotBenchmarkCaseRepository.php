<?php

namespace App\Support\Benchmark;

class ChatbotBenchmarkCaseRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $category = null): array
    {
        $cases = [
            [
                'id' => 'schedule_formal_greeting',
                'description' => 'Pertanyaan jadwal formal dengan salam.',
                'pipeline' => 'understanding_policy',
                'tags' => ['intent_understanding', 'entity_extraction'],
                'latest_message' => 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?',
                'recent_history' => [],
                'conversation_state' => [],
                'resolved_context' => [],
                'llm_output' => [
                    'intent' => 'schedule',
                    'confidence' => '93%',
                    'uses_previous_context' => false,
                    'entities' => [
                        'destination' => 'Pekanbaru',
                        'travel_date' => '2026-03-29',
                        'departure_time' => '10',
                    ],
                    'needs_clarification' => false,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'User menanyakan jadwal ke Pekanbaru.',
                ],
                'expected' => [
                    'intent' => 'schedule_inquiry',
                    'policy_action' => 'allow',
                    'entity.destination' => 'Pekanbaru',
                    'entity.departure_time' => '10:00',
                    'needs_clarification' => false,
                ],
            ],
            [
                'id' => 'schedule_typo_santai',
                'description' => 'Pertanyaan jadwal dengan typo, singkatan, dan bahasa santai.',
                'pipeline' => 'understanding_policy',
                'tags' => ['intent_understanding', 'entity_extraction'],
                'latest_message' => 'bg besok jm 10 k pku msh ada ga?',
                'recent_history' => [],
                'conversation_state' => [],
                'resolved_context' => [],
                'llm_output' => <<<TEXT
intent: tanya_jam
confidence: 0.82
destination: Pekanbaru
travel_date: 2026-03-29
departure_time: 10
needs_clarification: false
handoff_recommended: false
reasoning_summary: User menanyakan jadwal ke Pekanbaru.
TEXT,
                'expected' => [
                    'intent' => 'tanya_jam',
                    'policy_action' => 'allow',
                    'entity.destination' => 'Pekanbaru',
                    'entity.departure_time' => '10:00',
                    'needs_clarification' => false,
                ],
            ],
            [
                'id' => 'followup_destination_carry_over',
                'description' => 'Follow-up tanpa menyebut ulang tujuan harus memakai konteks sebelumnya.',
                'pipeline' => 'understanding_policy',
                'tags' => ['context_carry_over', 'intent_understanding'],
                'latest_message' => 'besok jam 10 ada?',
                'recent_history' => [
                    ['role' => 'user', 'text' => 'saya ingin ke Pekanbaru', 'sent_at' => '2026-03-28 08:00:00'],
                    ['role' => 'bot', 'text' => 'Baik, saya catat tujuan Pekanbaru ya.', 'sent_at' => '2026-03-28 08:00:30'],
                ],
                'conversation_state' => [],
                'resolved_context' => [
                    'last_destination' => 'Pekanbaru',
                    'current_topic' => 'schedule_inquiry',
                    'context_dependency_detected' => true,
                ],
                'llm_output' => [
                    'intent' => 'tanya_jam',
                    'confidence' => 0.87,
                    'uses_previous_context' => true,
                    'entities' => [
                        'destination' => null,
                        'travel_date' => '2026-03-29',
                        'departure_time' => '10:00',
                    ],
                    'needs_clarification' => false,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'User menanyakan jadwal lanjutan dan memakai konteks sebelumnya.',
                ],
                'expected' => [
                    'intent' => 'tanya_jam',
                    'policy_action' => 'allow',
                    'entity.destination' => 'Pekanbaru',
                    'entity.departure_time' => '10:00',
                    'uses_previous_context' => true,
                    'hydrated_fields' => ['destination'],
                ],
            ],
            [
                'id' => 'price_inquiry_complete_route',
                'description' => 'Pertanyaan harga dengan rute lengkap.',
                'pipeline' => 'understanding_policy',
                'tags' => ['intent_understanding', 'entity_extraction'],
                'latest_message' => 'berapa harga dari Ujung Batu ke Pekanbaru?',
                'recent_history' => [],
                'conversation_state' => [],
                'resolved_context' => [],
                'llm_output' => [
                    'intent' => 'price',
                    'confidence' => 0.90,
                    'uses_previous_context' => false,
                    'entities' => [
                        'origin' => 'Ujung Batu',
                        'destination' => 'Pekanbaru',
                    ],
                    'needs_clarification' => false,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'User menanyakan ongkos rute tertentu.',
                ],
                'expected' => [
                    'intent' => 'price_inquiry',
                    'policy_action' => 'allow',
                    'entity.pickup_location' => 'Ujung Batu',
                    'entity.destination' => 'Pekanbaru',
                ],
            ],
            [
                'id' => 'price_inquiry_ambiguous',
                'description' => 'Pertanyaan harga yang seharusnya diklarifikasi.',
                'pipeline' => 'understanding_policy',
                'tags' => ['clarification_quality', 'intent_understanding'],
                'latest_message' => 'berapa harganya?',
                'recent_history' => [],
                'conversation_state' => [],
                'resolved_context' => [],
                'llm_output' => [
                    'intent' => 'tanya_harga',
                    'confidence' => 0.84,
                    'uses_previous_context' => false,
                    'entities' => [],
                    'needs_clarification' => false,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'User bertanya harga tetapi rute belum disebut.',
                ],
                'expected' => [
                    'intent' => 'tanya_harga',
                    'policy_action' => 'clarify',
                    'needs_clarification' => true,
                    'clarification_contains' => 'titik jemput',
                ],
            ],
            [
                'id' => 'ambiguous_schedule_needs_clarification',
                'description' => 'Pertanyaan ambigu tentang jadwal harus menghasilkan klarifikasi yang layak.',
                'pipeline' => 'understanding_policy',
                'tags' => ['clarification_quality'],
                'latest_message' => 'besok ada?',
                'recent_history' => [],
                'conversation_state' => [],
                'resolved_context' => [],
                'llm_output' => [
                    'intent' => 'schedule_inquiry',
                    'confidence' => 0.58,
                    'uses_previous_context' => false,
                    'entities' => [],
                    'needs_clarification' => true,
                    'clarification_question' => null,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'User menanyakan jadwal tetapi rute belum jelas.',
                ],
                'expected' => [
                    'intent' => 'schedule_inquiry',
                    'policy_action' => 'clarify',
                    'needs_clarification' => true,
                    'clarification_contains' => 'rute',
                ],
            ],
            [
                'id' => 'admin_takeover_case',
                'description' => 'Admin takeover aktif harus memblokir auto-reply.',
                'pipeline' => 'understanding_policy',
                'tags' => ['admin_takeover_case'],
                'latest_message' => 'halo min, saya mau tanya jadwal',
                'recent_history' => [],
                'conversation_state' => [
                    'admin_takeover' => true,
                ],
                'resolved_context' => [],
                'conversation' => [
                    'handoff_mode' => 'admin',
                    'needs_human' => true,
                ],
                'llm_output' => [
                    'intent' => 'greeting',
                    'confidence' => 0.95,
                    'uses_previous_context' => false,
                    'entities' => [],
                    'needs_clarification' => false,
                    'handoff_recommended' => false,
                    'reasoning_summary' => 'Greeting biasa.',
                ],
                'expected' => [
                    'intent' => 'human_handoff',
                    'policy_action' => 'blocked_takeover',
                    'handoff_recommended' => true,
                ],
            ],
            [
                'id' => 'grounded_available_schedule_response',
                'description' => 'Grounded response untuk jadwal tersedia harus tetap benar saat fallback template dipakai.',
                'pipeline' => 'grounded_response',
                'tags' => ['grounded_response_correctness', 'fallback_rate'],
                'llm_response' => [
                    'text' => '',
                    'mode' => 'direct_answer',
                ],
                'facts' => [
                    'conversation_id' => 100,
                    'message_id' => 200,
                    'mode' => 'direct_answer',
                    'latest_message_text' => 'Assalamualaikum, besok jam 10 ke Pekanbaru ada?',
                    'customer_name' => 'Nerry',
                    'intent_result' => [
                        'intent' => 'tanya_jam',
                        'confidence' => 0.93,
                    ],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                        'departure_date' => '2026-03-29',
                        'departure_time' => '10:00',
                    ],
                    'resolved_context' => [
                        'last_destination' => 'Pekanbaru',
                    ],
                    'conversation_summary' => 'Customer menanyakan jadwal ke Pekanbaru.',
                    'admin_takeover' => false,
                    'official_facts' => [
                        'requested_schedule' => [
                            'travel_date' => '2026-03-29',
                            'travel_time' => '10:00',
                            'available' => true,
                        ],
                        'route' => [
                            'destination' => 'Pekanbaru',
                        ],
                        'suggested_follow_up' => 'Jika ingin, saya bisa bantu lanjut bookingnya.',
                    ],
                ],
                'expected' => [
                    'mode' => 'direct_answer',
                    'is_fallback' => true,
                    'text_contains' => ['Waalaikumsalam', '10.00 tersedia', 'lanjut booking'],
                ],
            ],
            [
                'id' => 'grounded_unavailable_schedule_response',
                'description' => 'Grounded response untuk jadwal tidak tersedia harus sopan dan tidak mengarang.',
                'pipeline' => 'grounded_response',
                'tags' => ['grounded_response_correctness', 'fallback_rate'],
                'llm_response' => [
                    'text' => '',
                    'mode' => 'polite_refusal',
                ],
                'facts' => [
                    'conversation_id' => 101,
                    'message_id' => 201,
                    'mode' => 'polite_refusal',
                    'latest_message_text' => 'besok jam 10 ke Pekanbaru ada?',
                    'customer_name' => null,
                    'intent_result' => [
                        'intent' => 'tanya_jam',
                        'confidence' => 0.90,
                    ],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                        'departure_date' => '2026-03-29',
                        'departure_time' => '10:00',
                    ],
                    'resolved_context' => [
                        'last_destination' => 'Pekanbaru',
                    ],
                    'conversation_summary' => 'Customer menanyakan jadwal ke Pekanbaru.',
                    'admin_takeover' => false,
                    'official_facts' => [
                        'requested_schedule' => [
                            'travel_date' => '2026-03-29',
                            'travel_time' => '10:00',
                            'available' => false,
                        ],
                        'route' => [
                            'destination' => 'Pekanbaru',
                            'supported' => true,
                        ],
                    ],
                ],
                'expected' => [
                    'mode' => 'polite_refusal',
                    'is_fallback' => true,
                    'text_contains' => ['belum tersedia', 'jam keberangkatan lain'],
                ],
            ],
            [
                'id' => 'repeat_prevention_same_context_blocked',
                'description' => 'Pertanyaan lanjutan dengan konteks sama tidak boleh memicu balasan identik berulang.',
                'pipeline' => 'reply_guard',
                'tags' => ['repetitive_reply_prevention'],
                'conversation_state' => [
                    'booking_intent_status' => 'collecting',
                    'booking_expected_input' => 'travel_date',
                ],
                'candidate_reply' => [
                    'text' => 'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
                    'is_fallback' => false,
                    'message_type' => 'text',
                    'meta' => [
                        'source' => 'booking_engine',
                        'action' => 'collect_travel_date',
                    ],
                ],
                'candidate_inbound_context' => [
                    'message_text' => 'besok jam 10 ada?',
                    'intent_result' => [
                        'intent' => 'tanya_jam',
                        'uses_previous_context' => false,
                    ],
                    'entity_result' => [
                        'destination' => 'Pekanbaru',
                    ],
                    'resolved_context' => [],
                ],
                'latest_outbound' => [
                    'message_text' => 'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
                    'same_inbound_context' => true,
                ],
                'expected' => [
                    'skip_repeat' => true,
                ],
            ],
            [
                'id' => 'repeat_prevention_followup_context_changed_allowed',
                'description' => 'Wording mirip tetapi konteks berubah harus tetap boleh dibalas.',
                'pipeline' => 'reply_guard',
                'tags' => ['repetitive_reply_prevention'],
                'conversation_state' => [
                    'booking_intent_status' => 'collecting',
                    'booking_expected_input' => 'travel_date',
                ],
                'candidate_reply' => [
                    'text' => 'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
                    'is_fallback' => false,
                    'message_type' => 'text',
                    'meta' => [
                        'source' => 'booking_engine',
                        'action' => 'collect_travel_date',
                    ],
                ],
                'candidate_inbound_context' => [
                    'message_text' => 'besok jam 10 ada?',
                    'intent_result' => [
                        'intent' => 'tanya_jam',
                        'uses_previous_context' => true,
                    ],
                    'entity_result' => [
                        'destination' => 'Bangkinang',
                    ],
                    'resolved_context' => [
                        'last_destination' => 'Bangkinang',
                    ],
                ],
                'latest_outbound' => [
                    'message_text' => 'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
                    'same_inbound_context' => false,
                    'inbound_context' => [
                        'message_text' => 'besok jam 10 ada?',
                        'intent_result' => [
                            'intent' => 'tanya_jam',
                            'uses_previous_context' => true,
                        ],
                        'entity_result' => [
                            'destination' => 'Pekanbaru',
                        ],
                        'resolved_context' => [
                            'last_destination' => 'Pekanbaru',
                        ],
                    ],
                ],
                'expected' => [
                    'skip_repeat' => false,
                ],
            ],
        ];

        if ($category === null || trim($category) === '') {
            return $cases;
        }

        $wanted = trim($category);

        return array_values(array_filter(
            $cases,
            static fn (array $case): bool => in_array($wanted, $case['tags'] ?? [], true),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $case) {
            if (($case['id'] ?? null) === $id) {
                return $case;
            }
        }

        return null;
    }
}
