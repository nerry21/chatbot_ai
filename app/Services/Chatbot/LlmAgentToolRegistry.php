<?php

namespace App\Services\Chatbot;

use App\Models\Customer;
use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;
use App\Services\Booking\SeatAvailabilityService;
use App\Services\CRM\CustomerPreferenceUpdaterService;
use App\Services\CRM\JetCrmContextService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Support\WaLog;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class LlmAgentToolRegistry
{
    public const WRITABLE_PREFERENCE_KEYS = [
        'language_style',
        'preferred_greeting_style',
        'child_traveler',
        'elderly_traveler',
        'luggage_pattern',
        'frequent_companion',
        'preferred_service_type',
        'vip_indicator',
        'notes_freeform',
        'internal_tags',
    ];

    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly RouteValidationService $routeValidator,
        private readonly CustomerPreferenceUpdaterService $preferenceUpdater,
        private readonly JetCrmContextService $crmContext,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getToolsSchema(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_fare_for_route',
                    'description' => 'Dapatkan tarif untuk rute tertentu. Selalu pakai tool ini, jangan mengira-ngira tarif.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'pickup' => [
                                'type' => 'string',
                                'description' => 'Lokasi penjemputan (e.g., Pasir Pengaraian)',
                            ],
                            'dropoff' => [
                                'type' => 'string',
                                'description' => 'Lokasi tujuan (e.g., Pekanbaru)',
                            ],
                        ],
                        'required' => ['pickup', 'dropoff'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'check_seat_availability',
                    'description' => 'Cek seat tersedia untuk tanggal dan jam keberangkatan tertentu.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'date' => [
                                'type' => 'string',
                                'description' => 'Tanggal keberangkatan (YYYY-MM-DD)',
                            ],
                            'time' => [
                                'type' => 'string',
                                'description' => 'Jam keberangkatan (HH:MM)',
                            ],
                        ],
                        'required' => ['date', 'time'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_knowledge_base',
                    'description' => 'Cari artikel di knowledge base untuk pertanyaan umum (carter, paket, rute baru, dll).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Kata kunci atau pertanyaan customer',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_route_info',
                    'description' => 'Extract known location dari teks customer. Gunakan untuk infer pickup/dropoff dari alamat.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query_text' => [
                                'type' => 'string',
                                'description' => 'Alamat atau lokasi customer',
                            ],
                        ],
                        'required' => ['query_text'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'escalate_to_admin',
                    'description' => 'Eskalasi ke admin manusia. Pakai untuk: refund, komplain serius, multi-issue, customer marah, di luar pengetahuan.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Alasan kenapa perlu admin',
                            ],
                        ],
                        'required' => ['reason'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_customer_preferences',
                    'description' => 'Baca preferensi customer yang sudah tercatat (gaya bahasa, sapaan, dll). Pakai untuk personalisasi reply. Hanya return preferensi dengan confidence >= 0.5.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Optional. Filter by specific keys (e.g. ["language_style", "preferred_greeting_style"]). Kosongkan untuk dapat semua.',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'record_customer_preference',
                    'description' => 'Catat preferensi baru customer saat detect dari conversation. Pakai HANYA untuk hal yang relevan untuk relationship-building. Whitelist key: language_style, preferred_greeting_style, child_traveler, elderly_traveler, luggage_pattern, frequent_companion, preferred_service_type, vip_indicator, notes_freeform, internal_tags.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => [
                                'type' => 'string',
                                'description' => 'Key preferensi (harus dari whitelist).',
                            ],
                            'value' => [
                                'type' => 'string',
                                'description' => 'Nilai preferensi (e.g. "formal", "Mbak", "true").',
                            ],
                            'confidence_level' => [
                                'type' => 'string',
                                'enum' => ['explicit', 'inferred'],
                                'description' => 'explicit = customer eksplisit bilang (confidence 1.0). inferred = LLM nebak dari pola (confidence 0.5).',
                            ],
                        ],
                        'required' => ['key', 'value', 'confidence_level'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $args, ?Customer $customer = null): array
    {
        return match ($toolName) {
            'get_fare_for_route'         => $this->executeGetFareForRoute($args),
            'check_seat_availability'    => $this->executeCheckSeatAvailability($args),
            'search_knowledge_base'      => $this->executeSearchKnowledgeBase($args),
            'get_route_info'             => $this->executeGetRouteInfo($args),
            'escalate_to_admin'          => $this->executeEscalateToAdmin($args),
            'get_customer_preferences'   => $this->executeGetCustomerPreferences($args, $customer),
            'record_customer_preference' => $this->executeRecordCustomerPreference($args, $customer),
            default                      => throw new InvalidArgumentException(
                "Unknown tool: {$toolName}",
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeGetFareForRoute(array $args): array
    {
        $pickup = (string) ($args['pickup'] ?? '');
        $dropoff = (string) ($args['dropoff'] ?? '');

        $normalizedPickup = $this->routeValidator->knownLocation($pickup) ?? $pickup;
        $normalizedDropoff = $this->routeValidator->knownLocation($dropoff) ?? $dropoff;

        $breakdown = $this->fareCalculator->fareBreakdown($normalizedPickup, $normalizedDropoff, 1);

        if ($breakdown === null) {
            return [
                'error' => 'Rute tidak didukung',
                'pickup' => $normalizedPickup,
                'dropoff' => $normalizedDropoff,
                'supported' => false,
            ];
        }

        $amount = (int) ($breakdown['total_fare'] ?? $breakdown['unit_fare'] ?? 0);

        return [
            'amount' => $amount,
            'formatted' => $this->fareCalculator->formatRupiah($amount),
            'pickup' => $normalizedPickup,
            'dropoff' => $normalizedDropoff,
            'supported' => $this->routeValidator->isSupported($normalizedPickup, $normalizedDropoff),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeCheckSeatAvailability(array $args): array
    {
        $date = (string) ($args['date'] ?? '');
        $time = (string) ($args['time'] ?? '');

        $available = $this->seatAvailability->availableSeats($date, $time);

        return [
            'available_seats' => $available,
            'count' => count($available),
            'has_capacity' => count($available) > 0,
            'date' => $date,
            'time' => $time,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeSearchKnowledgeBase(array $args): array
    {
        $query = (string) ($args['query'] ?? '');

        $hits = $this->knowledgeBase->search($query, ['max_in_prompt' => 3]);

        $formatted = array_map(
            static fn (array $hit): array => [
                'title' => (string) ($hit['title'] ?? ''),
                'content_excerpt' => mb_substr((string) ($hit['excerpt'] ?? $hit['content'] ?? ''), 0, 200),
                'category' => (string) ($hit['category'] ?? ''),
            ],
            $hits,
        );

        return [
            'hits' => $formatted,
            'count' => count($formatted),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeGetRouteInfo(array $args): array
    {
        $queryText = (string) ($args['query_text'] ?? '');

        $location = $this->routeValidator->findLocationInText($queryText);

        if ($location === null) {
            return [
                'location' => null,
                'message' => 'Lokasi tidak terdeteksi',
            ];
        }

        return [
            'location' => $location,
            'cluster' => null,
            'supported_destinations' => $this->routeValidator->supportedDestinations($location),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeEscalateToAdmin(array $args): array
    {
        $reason = (string) ($args['reason'] ?? '');

        WaLog::info('[LlmAgent] Escalation triggered', ['reason' => $reason]);

        return [
            'handoff_triggered' => true,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeGetCustomerPreferences(array $args, ?Customer $customer): array
    {
        if ($customer === null) {
            return [
                'error'       => 'Customer context unavailable',
                'preferences' => [],
                'count'       => 0,
            ];
        }

        $filterKeys = is_array($args['keys'] ?? null) ? $args['keys'] : [];

        $query = $customer->preferences()
            ->where('confidence', '>=', 0.5);

        if ($filterKeys !== []) {
            $query->whereIn('key', $filterKeys);
        }

        $prefs = $query->get();

        $formatted = [];
        foreach ($prefs as $pref) {
            $formatted[$pref->key] = [
                'value'      => $pref->getTypedValue(),
                'confidence' => (float) $pref->confidence,
                'source'     => $pref->source,
            ];
        }

        return [
            'preferences' => $formatted,
            'count'       => count($formatted),
            'customer_id' => $customer->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeRecordCustomerPreference(array $args, ?Customer $customer): array
    {
        if ($customer === null) {
            return [
                'error'    => 'Customer context unavailable',
                'recorded' => false,
            ];
        }

        $key = trim((string) ($args['key'] ?? ''));
        $value = $args['value'] ?? '';
        $confidenceLevel = (string) ($args['confidence_level'] ?? 'inferred');

        if (! in_array($key, self::WRITABLE_PREFERENCE_KEYS, true)) {
            WaLog::warning('[LlmAgent] Rejected non-whitelisted preference key', [
                'key'         => $key,
                'customer_id' => $customer->id,
            ]);

            return [
                'error'    => "Key '{$key}' not in whitelist. Allowed keys: ".implode(', ', self::WRITABLE_PREFERENCE_KEYS),
                'recorded' => false,
            ];
        }

        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return [
                'error'    => 'Value cannot be empty',
                'recorded' => false,
            ];
        }

        $source = $confidenceLevel === 'explicit'
            ? CustomerPreferenceUpdaterService::SOURCE_EXPLICIT
            : CustomerPreferenceUpdaterService::SOURCE_INFERRED;

        $valueType = $this->inferValueType($value);

        $saved = $this->preferenceUpdater->upsertPreference(
            customer:  $customer,
            key:       $key,
            value:     $value,
            valueType: $valueType,
            source:    $source,
            metadata:  ['recorded_by' => 'llm_agent'],
        );

        if ($saved === null) {
            return [
                'error'    => 'Failed to save preference',
                'recorded' => false,
            ];
        }

        Cache::forget('jet_crm_profile_customer_'.$customer->id);

        WaLog::info('[LlmAgent] Recorded preference', [
            'customer_id'      => $customer->id,
            'key'              => $key,
            'value'            => is_string($value) ? mb_substr($value, 0, 100) : $value,
            'confidence_level' => $confidenceLevel,
        ]);

        return [
            'recorded'   => true,
            'key'        => $saved->key,
            'value'      => $saved->getTypedValue(),
            'confidence' => (float) $saved->confidence,
            'source'     => $saved->source,
        ];
    }

    private function inferValueType(mixed $value): string
    {
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_array($value)) {
            return 'json';
        }

        $strLower = is_string($value) ? strtolower(trim($value)) : '';
        if (in_array($strLower, ['true', 'false', 'yes', 'no'], true)) {
            return 'bool';
        }

        return 'string';
    }
}
