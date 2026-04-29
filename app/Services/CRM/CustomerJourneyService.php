<?php

namespace App\Services\CRM;

use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerJourneyEvent;
use App\Models\CustomerMilestone;
use App\Support\WaLog;
use Carbon\Carbon;
use Throwable;

class CustomerJourneyService
{
    public const BOOKING_MILESTONES = [5, 10, 25, 50, 100];

    public const AT_RISK_TIERS = [
        'at_risk_30d' => 30,
        'at_risk_60d' => 60,
        'at_risk_90d' => 90,
    ];

    public const ANNIVERSARY_TOLERANCE_DAYS = 1;

    /**
     * Sync booking milestones for a customer based on current total_bookings.
     * Idempotent — uses unique constraint on (customer_id, milestone_key).
     *
     * @return array<int, string>
     */
    public function syncBookingMilestones(Customer $customer): array
    {
        $totalBookings = (int) $customer->total_bookings;
        $newlyRecorded = [];

        foreach (self::BOOKING_MILESTONES as $threshold) {
            if ($totalBookings < $threshold) {
                continue;
            }

            $key = $threshold.'_bookings';
            $created = $this->recordMilestoneIfNotExists(
                $customer,
                $key,
                'booking_count',
                ['threshold' => $threshold, 'total_bookings_at_achievement' => $totalBookings]
            );

            if ($created) {
                $newlyRecorded[] = $key;
                $this->recordEvent($customer, 'milestone_reached', $key, [
                    'milestone_key' => $key,
                    'category' => 'booking_count',
                    'threshold' => $threshold,
                ]);
                $this->dispatchAdminNotification(
                    'milestone_booking',
                    "Milestone Booking: Customer #{$customer->id}",
                    "{$customer->name} mencapai {$threshold} bookings."
                        .($threshold >= 50 ? ' 🎉 BIG MILESTONE!' : ''),
                    [
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->name,
                        'milestone_key' => $key,
                        'threshold' => $threshold,
                        'total_bookings' => $totalBookings,
                    ]
                );
            }
        }

        return $newlyRecorded;
    }

    /**
     * Detect customers approaching/passing 1-year anniversary.
     */
    public function detectAnniversaries(?Carbon $referenceDate = null): int
    {
        $reference = $referenceDate ?? now();
        $tolerance = self::ANNIVERSARY_TOLERANCE_DAYS;

        $windowStart = $reference->copy()->subYear()->subDays($tolerance)->startOfDay();
        $windowEnd = $reference->copy()->subYear()->addDays($tolerance)->endOfDay();

        $candidates = Customer::query()
            ->whereNotNull('first_booking_at')
            ->whereBetween('first_booking_at', [$windowStart, $windowEnd])
            ->get();

        $count = 0;
        foreach ($candidates as $customer) {
            $year = $reference->year - $customer->first_booking_at->year;
            if ($year < 1) {
                continue;
            }

            $key = "{$year}_year_anniversary";
            $created = $this->recordMilestoneIfNotExists(
                $customer,
                $key,
                'anniversary',
                [
                    'years' => $year,
                    'first_booking_at' => $customer->first_booking_at->toIso8601String(),
                ]
            );

            if ($created) {
                $count++;
                $this->recordEvent($customer, 'anniversary', $key, [
                    'years' => $year,
                ]);
                $this->dispatchAdminNotification(
                    'milestone_anniversary',
                    "Anniversary: Customer #{$customer->id}",
                    "{$customer->name} sudah {$year} tahun jadi customer JET Travel. 🎂",
                    [
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->name,
                        'years' => $year,
                    ]
                );
            }
        }

        return $count;
    }

    /**
     * Detect customers silent for 30/60/90 days. Each tier triggers once.
     *
     * @return array<string, int>
     */
    public function detectAtRiskCustomers(?Carbon $referenceDate = null): array
    {
        $reference = $referenceDate ?? now();
        $results = [];

        foreach (self::AT_RISK_TIERS as $key => $days) {
            $threshold = $reference->copy()->subDays($days);

            $candidates = Customer::query()
                ->where('status', 'active')
                ->where('total_bookings', '>', 0)
                ->whereNotNull('last_interaction_at')
                ->where('last_interaction_at', '<', $threshold)
                ->get();

            $count = 0;
            foreach ($candidates as $customer) {
                $created = $this->recordMilestoneIfNotExists(
                    $customer,
                    $key,
                    'at_risk',
                    [
                        'days_silent' => $days,
                        'last_interaction_at' => $customer->last_interaction_at?->toIso8601String(),
                    ]
                );

                if ($created) {
                    $count++;
                    $this->recordEvent($customer, 'at_risk_detected', $key, [
                        'days_silent' => $days,
                    ]);
                    $this->dispatchAdminNotification(
                        'milestone_at_risk',
                        "At-Risk: Customer #{$customer->id} silent {$days} hari",
                        "{$customer->name} tidak ada interaksi sejak "
                            .$customer->last_interaction_at->format('d M Y')
                            .'. Mau follow-up?',
                        [
                            'customer_id' => $customer->id,
                            'customer_name' => $customer->name,
                            'days_silent' => $days,
                            'tier' => $key,
                        ]
                    );
                }
            }
            $results[$key] = $count;
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnacknowledgedMilestones(Customer $customer, int $limit = 3): array
    {
        return CustomerMilestone::query()
            ->where('customer_id', $customer->id)
            ->whereNull('acknowledged_at')
            ->orderByDesc('achieved_at')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'key' => $m->milestone_key,
                'category' => $m->milestone_category,
                'achieved_at' => $m->achieved_at->toIso8601String(),
                'metadata' => $m->metadata,
            ])
            ->all();
    }

    public function acknowledgeMilestone(Customer $customer, string $milestoneKey): void
    {
        CustomerMilestone::query()
            ->where('customer_id', $customer->id)
            ->where('milestone_key', $milestoneKey)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordMilestoneIfNotExists(
        Customer $customer,
        string $key,
        string $category,
        array $metadata = [],
    ): bool {
        try {
            CustomerMilestone::create([
                'customer_id' => $customer->id,
                'milestone_key' => $key,
                'milestone_category' => $category,
                'metadata' => $metadata,
                'achieved_at' => now(),
            ]);
            return true;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (
                str_contains($message, 'Duplicate')
                || str_contains($message, 'UNIQUE')
                || str_contains($message, 'Integrity constraint')
            ) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        Customer $customer,
        string $eventType,
        ?string $eventKey,
        array $metadata = [],
    ): void {
        try {
            CustomerJourneyEvent::create([
                'customer_id' => $customer->id,
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'metadata' => $metadata,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            WaLog::warning('[CustomerJourney] Failed to record event', [
                'customer_id' => $customer->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchAdminNotification(
        string $type,
        string $title,
        string $body,
        array $payload = [],
    ): void {
        try {
            AdminNotification::create([
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'payload' => $payload,
                'is_read' => false,
            ]);
        } catch (Throwable $e) {
            WaLog::warning('[CustomerJourney] Failed to create AdminNotification', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
