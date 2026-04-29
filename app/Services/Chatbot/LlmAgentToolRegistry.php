<?php

namespace App\Services\Chatbot;

use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;
use App\Services\Booking\SeatAvailabilityService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Support\WaLog;
use InvalidArgumentException;

class LlmAgentToolRegistry
{
    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly RouteValidationService $routeValidator,
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
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $args): array
    {
        return match ($toolName) {
            'get_fare_for_route'      => $this->executeGetFareForRoute($args),
            'check_seat_availability' => $this->executeCheckSeatAvailability($args),
            'search_knowledge_base'   => $this->executeSearchKnowledgeBase($args),
            'get_route_info'          => $this->executeGetRouteInfo($args),
            'escalate_to_admin'       => $this->executeEscalateToAdmin($args),
            default                   => throw new InvalidArgumentException(
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
}
