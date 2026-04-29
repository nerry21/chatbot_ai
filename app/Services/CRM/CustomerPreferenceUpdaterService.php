<?php

namespace App\Services\CRM;

use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Support\WaLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerPreferenceUpdaterService
{
    public const RELIABLE_CONFIDENCE_THRESHOLD = 0.5;
    public const DECAY_INTERVAL_DAYS = 90;
    public const DECAY_AMOUNT = 0.1;

    public const SOURCE_INFERRED_DEFAULT_CONFIDENCE = 0.5;
    public const SOURCE_EXPLICIT_DEFAULT_CONFIDENCE = 1.0;
    public const SOURCE_IMPORTED_DEFAULT_CONFIDENCE = 0.7;
    public const SOURCE_MANUAL_DEFAULT_CONFIDENCE = 1.0;

    public const SOURCE_EXPLICIT = 'explicit';
    public const SOURCE_INFERRED = 'inferred';
    public const SOURCE_IMPORTED = 'imported';
    public const SOURCE_MANUAL = 'manual';

    private const MILESTONE_THRESHOLDS = [5, 10, 25, 50, 100];

    /**
     * Called from BookingConfirmationService::confirm(). Increments counters
     * and recomputes preferences from the customer's confirmed booking history.
     */
    public function updateFromBooking(BookingRequest $booking): void
    {
        $customer = $booking->customer;

        if ($customer === null && $booking->customer_id !== null) {
            $customer = Customer::find($booking->customer_id);
        }

        if ($customer === null) {
            WaLog::info('[CustomerPreferenceUpdater] Skipped — booking has no customer', [
                'booking_id' => $booking->id,
            ]);
            return;
        }

        DB::transaction(function () use ($customer): void {
            $this->applyAggregates($customer);
            $customer->last_interaction_at = now();
            $customer->save();
        });

        WaLog::info('[CustomerPreferenceUpdater] Updated from booking', [
            'customer_id' => $customer->id,
            'booking_id' => $booking->id,
            'total_bookings' => $customer->total_bookings,
        ]);
    }

    /**
     * Recompute total_bookings, total_spent, and the three preferred_* fields
     * from the customer's confirmed booking history. Idempotent.
     */
    public function recomputePreferences(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $this->applyAggregates($customer);
            $customer->save();
        });

        WaLog::info('[CustomerPreferenceUpdater] Recomputed preferences', [
            'customer_id' => $customer->id,
            'total_bookings' => $customer->total_bookings,
        ]);
    }

    /**
     * Lightweight: only bumps last_interaction_at. Safe to call on every
     * inbound message — no aggregation, no joins.
     */
    public function touchInteraction(Customer $customer): void
    {
        $customer->forceFill(['last_interaction_at' => now()])->save();
    }

    private function applyAggregates(Customer $customer): void
    {
        $confirmed = BookingRequest::query()
            ->where('customer_id', $customer->id)
            ->where('booking_status', BookingStatus::Confirmed->value);

        $totalBookings = (clone $confirmed)->count();
        $totalSpent = (clone $confirmed)->sum('price_estimate');

        $customer->total_bookings = $totalBookings;
        $customer->total_spent = $totalSpent;

        $customer->preferred_pickup = $this->modeColumn($customer->id, 'pickup_location');
        $customer->preferred_destination = $this->modeColumn($customer->id, 'destination');
        $customer->preferred_departure_time = $this->modeColumn($customer->id, 'departure_time');

        $this->recomputeAllPreferences($customer);
    }

    /**
     * Return the most-frequent non-null value for $column among the customer's
     * confirmed bookings. Ties broken by most-recent created_at.
     */
    private function modeColumn(int $customerId, string $column): ?string
    {
        $row = BookingRequest::query()
            ->select($column, DB::raw('COUNT(*) as cnt'), DB::raw('MAX(created_at) as latest'))
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('cnt')
            ->orderByDesc('latest')
            ->limit(1)
            ->first();

        if ($row === null) {
            return null;
        }

        $value = $row->{$column};

        return $value === null ? null : (string) $value;
    }

    // -------------------------------------------------------------------------
    // PR-CRM-2: Detailed key-value preferences
    // -------------------------------------------------------------------------

    /**
     * Iterate all 20 auto-detectable keys, derive value, and upsert.
     */
    public function recomputeAllPreferences(Customer $customer): void
    {
        $derivations = [
            'preferred_pickup_area'             => fn () => $this->derivePickupArea($customer),
            'preferred_destination_area'        => fn () => $this->deriveDestinationArea($customer),
            'preferred_route_cluster'           => fn () => $this->deriveRouteCluster($customer->id),
            'frequent_route_pair'               => fn () => $this->deriveRoutePair($customer->id),
            'preferred_departure_time'          => fn () => $this->deriveDepartureTime($customer),
            'preferred_travel_day'              => fn () => $this->deriveTravelDay($customer->id),
            'travel_frequency'                  => fn () => $this->deriveTravelFrequency($customer->id),
            'preferred_seat_position'           => fn () => $this->deriveSeatPosition($customer->id),
            'preferred_seat_specific'           => fn () => $this->deriveSeatSpecific($customer->id),
            'preferred_service_type'            => fn () => $this->deriveServiceType($customer->id),
            'preferred_payment_method'          => fn () => $this->derivePaymentMethod($customer->id),
            'payment_timing_pattern'            => fn () => $this->derivePaymentTiming($customer->id),
            'customer_tier'                     => fn () => $this->deriveCustomerTier($customer),
            'cancellation_rate'                 => fn () => $this->deriveCancellationRate($customer->id),
            'total_lifetime_bookings_milestone' => fn () => $this->deriveBookingsMilestone($customer),
            'cancellation_pattern'              => fn () => $this->deriveCancellationPattern($customer->id),
            'late_arrival_pattern'              => fn () => $this->deriveLateArrivalPattern($customer->id),
            'prefers_specific_driver'           => fn () => $this->deriveSpecificDriver($customer->id),
            'prefers_specific_vehicle'          => fn () => $this->deriveSpecificVehicle($customer->id),
            'frequent_companion'                => fn () => $this->deriveFrequentCompanion($customer->id),
        ];

        foreach ($derivations as $key => $callback) {
            $derived = $callback();

            if ($derived === null) {
                continue;
            }

            $value = $derived['value'] ?? null;
            $valueType = $derived['value_type'] ?? 'string';
            $metadata = $derived['metadata'] ?? [];
            $confidence = isset($derived['confidence']) ? (float) $derived['confidence'] : null;

            if ($value === null || $value === '') {
                continue;
            }

            $this->upsertPreference(
                customer:           $customer,
                key:                $key,
                value:              $value,
                valueType:          $valueType,
                source:             self::SOURCE_INFERRED,
                metadata:           $metadata,
                confidenceOverride: $confidence,
            );
        }
    }

    /**
     * Insert or reinforce a preference row. Returns the saved model, or null
     * when the value is empty.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function upsertPreference(
        Customer $customer,
        string $key,
        mixed $value,
        string $valueType,
        string $source,
        array $metadata = [],
        ?float $confidenceOverride = null,
    ): ?CustomerPreference {
        if ($value === null || $value === '') {
            return null;
        }

        $stringValue = $this->stringifyValue($value, $valueType);

        $existing = CustomerPreference::query()
            ->where('customer_id', $customer->id)
            ->where('key', $key)
            ->first();

        $defaultConfidence = $this->defaultConfidenceForSource($source);
        $now = now();

        if ($existing === null) {
            $confidence = $confidenceOverride ?? $defaultConfidence;
            $merged = array_merge([
                'first_seen_at'        => $now->toIso8601String(),
                'reinforcement_count'  => 1,
            ], $metadata);

            $pref = new CustomerPreference([
                'customer_id'   => $customer->id,
                'key'           => $key,
                'value'         => $stringValue,
                'value_type'    => $valueType,
                'confidence'    => $this->clampConfidence($confidence),
                'source'        => $source,
                'metadata'      => $merged,
                'last_seen_at'  => $now,
            ]);
            $pref->save();
            return $pref;
        }

        $existingMetadata = is_array($existing->metadata) ? $existing->metadata : [];
        $reinforcement = (int) ($existingMetadata['reinforcement_count'] ?? 1);

        if ($existing->value === $stringValue && $existing->value_type === $valueType) {
            // Reinforce: same value seen again
            $reinforcement += 1;
            $newConfidence = $confidenceOverride
                ?? min(1.0, (float) $existing->confidence + 0.1);

            $existing->confidence = $this->clampConfidence($newConfidence);
            $existing->metadata = array_merge($existingMetadata, $metadata, [
                'reinforcement_count' => $reinforcement,
            ]);
            $existing->last_seen_at = $now;
            $existing->save();

            return $existing;
        }

        // Replace: value differs
        $existing->value = $stringValue;
        $existing->value_type = $valueType;
        $existing->confidence = $this->clampConfidence($confidenceOverride ?? $defaultConfidence);
        $existing->source = $source;
        $existing->metadata = array_merge($existingMetadata, $metadata, [
            'reinforcement_count' => 1,
            'first_seen_at'       => $now->toIso8601String(),
        ]);
        $existing->last_seen_at = $now;
        $existing->save();

        return $existing;
    }

    /**
     * Decay all stale preferences for a customer. Returns the number of rows
     * affected (decremented or removed).
     */
    public function applyConfidenceDecay(Customer $customer): int
    {
        $threshold = now()->subDays(self::DECAY_INTERVAL_DAYS);

        $stale = CustomerPreference::query()
            ->where('customer_id', $customer->id)
            ->where('last_seen_at', '<', $threshold)
            ->get();

        $touched = 0;

        foreach ($stale as $pref) {
            $newConfidence = max(0.0, (float) $pref->confidence - self::DECAY_AMOUNT);

            if ($newConfidence <= 0.0) {
                $pref->delete();
                $touched++;
                continue;
            }

            $metadata = is_array($pref->metadata) ? $pref->metadata : [];
            $metadata['last_decay_at'] = now()->toIso8601String();

            $pref->confidence = $newConfidence;
            $pref->metadata = $metadata;
            $pref->save();
            $touched++;
        }

        return $touched;
    }

    // -------------------------------------------------------------------------
    // Derivation helpers — each returns ['value', 'value_type', 'metadata', 'confidence'] or null
    // -------------------------------------------------------------------------

    private function derivePickupArea(Customer $customer): ?array
    {
        $value = $customer->preferred_pickup;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $count = (int) ($customer->total_bookings ?? 0);

        return [
            'value'      => $value,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $count],
            'confidence' => $count >= 3 ? 0.8 : 0.6,
        ];
    }

    private function deriveDestinationArea(Customer $customer): ?array
    {
        $value = $customer->preferred_destination;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $count = (int) ($customer->total_bookings ?? 0);

        return [
            'value'      => $value,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $count],
            'confidence' => $count >= 3 ? 0.8 : 0.6,
        ];
    }

    private function deriveDepartureTime(Customer $customer): ?array
    {
        $raw = $customer->preferred_departure_time;
        if ($raw === null) {
            return null;
        }

        $formatted = $raw instanceof Carbon ? $raw->format('H:i') : (string) $raw;
        if ($formatted === '') {
            return null;
        }

        $count = (int) ($customer->total_bookings ?? 0);

        return [
            'value'      => $formatted,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $count],
            'confidence' => $count >= 3 ? 0.8 : 0.6,
        ];
    }

    private function deriveTravelDay(int $customerId): ?array
    {
        $rows = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull('departure_date')
            ->get(['departure_date']);

        if ($rows->isEmpty()) {
            return null;
        }

        $tally = [];
        foreach ($rows as $row) {
            $date = $row->departure_date instanceof Carbon
                ? $row->departure_date
                : Carbon::parse((string) $row->departure_date);
            $day = $date->englishDayOfWeek;
            $tally[$day] = ($tally[$day] ?? 0) + 1;
        }

        if ($tally === []) {
            return null;
        }

        arsort($tally);
        $top = array_key_first($tally);
        $count = $tally[$top];
        $sample = $rows->count();

        return [
            'value'      => strtolower($top),
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $sample, 'tally' => $tally],
            'confidence' => $sample >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveTravelFrequency(int $customerId): ?array
    {
        $sixMonthsAgo = now()->subMonths(6);

        $bookings = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->orderBy('created_at')
            ->get(['created_at']);

        $count = $bookings->count();

        if ($count < 2) {
            return null;
        }

        $gaps = [];
        $previous = null;
        foreach ($bookings as $booking) {
            $current = $booking->created_at;
            if ($previous !== null && $current !== null) {
                $gaps[] = $previous->diffInDays($current);
            }
            $previous = $current;
        }

        if ($gaps === []) {
            return null;
        }

        $avgGap = array_sum($gaps) / count($gaps);

        $bucket = match (true) {
            $avgGap < 10  => 'weekly',
            $avgGap <= 20 => 'biweekly',
            $avgGap <= 45 => 'monthly',
            default       => 'occasional',
        };

        return [
            'value'      => $bucket,
            'value_type' => 'string',
            'metadata'   => [
                'sample_size'    => $count,
                'avg_gap_days'   => round($avgGap, 1),
            ],
            'confidence' => $count >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveSeatPosition(int $customerId): ?array
    {
        $bookings = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull('selected_seats')
            ->get(['selected_seats']);

        if ($bookings->isEmpty()) {
            return null;
        }

        $tally = ['front' => 0, 'middle' => 0, 'back' => 0];

        foreach ($bookings as $booking) {
            $seats = is_array($booking->selected_seats) ? $booking->selected_seats : [];
            foreach ($seats as $seat) {
                $position = $this->classifySeatPosition((string) $seat);
                if ($position === null) {
                    continue;
                }
                $tally[$position]++;
            }
        }

        $tally = array_filter($tally);
        if ($tally === []) {
            return null;
        }

        arsort($tally);
        $top = array_key_first($tally);
        $sample = array_sum($tally);

        return [
            'value'      => $top,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $sample, 'tally' => $tally],
            'confidence' => $sample >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveSeatSpecific(int $customerId): ?array
    {
        $bookings = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull('selected_seats')
            ->get(['selected_seats']);

        if ($bookings->isEmpty()) {
            return null;
        }

        $tally = [];
        foreach ($bookings as $booking) {
            $seats = is_array($booking->selected_seats) ? $booking->selected_seats : [];
            foreach ($seats as $seat) {
                $code = trim((string) $seat);
                if ($code === '') {
                    continue;
                }
                $tally[$code] = ($tally[$code] ?? 0) + 1;
            }
        }

        if ($tally === []) {
            return null;
        }

        arsort($tally);
        $top = array_key_first($tally);
        $count = $tally[$top];

        if ($count < 2) {
            return null;
        }

        return [
            'value'      => $top,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => array_sum($tally), 'tally' => $tally],
            'confidence' => $count >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveServiceType(int $customerId): ?array
    {
        // No `service_type` column on booking_requests — cannot derive.
        return null;
    }

    private function derivePaymentMethod(int $customerId): ?array
    {
        $row = BookingRequest::query()
            ->select('payment_method', DB::raw('COUNT(*) as cnt'))
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull('payment_method')
            ->where('payment_method', '!=', '')
            ->groupBy('payment_method')
            ->orderByDesc('cnt')
            ->limit(1)
            ->first();

        if ($row === null || $row->payment_method === null) {
            return null;
        }

        $count = (int) $row->cnt;

        return [
            'value'      => (string) $row->payment_method,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $count],
            'confidence' => $count >= 3 ? 0.7 : 0.5,
        ];
    }

    private function derivePaymentTiming(int $customerId): ?array
    {
        // No payment-timestamp data captured on booking_requests — skip.
        return null;
    }

    private function deriveRouteCluster(int $customerId): ?array
    {
        $bookings = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->get(['pickup_location', 'destination', 'pickup_full_address', 'destination_full_address']);

        if ($bookings->isEmpty()) {
            return null;
        }

        $tally = ['BANGKINANG' => 0, 'PETAPAHAN' => 0];

        foreach ($bookings as $booking) {
            $blob = strtolower(implode(' ', [
                (string) $booking->pickup_location,
                (string) $booking->destination,
                (string) $booking->pickup_full_address,
                (string) $booking->destination_full_address,
            ]));

            if (str_contains($blob, 'bangkinang')) {
                $tally['BANGKINANG']++;
            }
            if (str_contains($blob, 'petapahan')) {
                $tally['PETAPAHAN']++;
            }
        }

        $tally = array_filter($tally);
        if ($tally === []) {
            return null;
        }

        arsort($tally);
        $top = array_key_first($tally);
        $count = $tally[$top];

        return [
            'value'      => $top,
            'value_type' => 'string',
            'metadata'   => ['sample_size' => $count, 'tally' => $tally],
            'confidence' => $count >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveRoutePair(int $customerId): ?array
    {
        $row = BookingRequest::query()
            ->select(
                'pickup_location',
                'destination',
                DB::raw('COUNT(*) as cnt'),
            )
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Confirmed->value)
            ->whereNotNull('pickup_location')
            ->whereNotNull('destination')
            ->groupBy('pickup_location', 'destination')
            ->orderByDesc('cnt')
            ->limit(1)
            ->first();

        if ($row === null) {
            return null;
        }

        $count = (int) $row->cnt;
        if ($count < 2) {
            return null;
        }

        $pair = sprintf('%s → %s', $row->pickup_location, $row->destination);

        return [
            'value'      => $pair,
            'value_type' => 'string',
            'metadata'   => [
                'sample_size' => $count,
                'pickup'      => $row->pickup_location,
                'destination' => $row->destination,
            ],
            'confidence' => $count >= 3 ? 0.7 : 0.5,
        ];
    }

    private function deriveCustomerTier(Customer $customer): ?array
    {
        $count = (int) ($customer->total_bookings ?? 0);

        $tier = match (true) {
            $count >= 25 => 'platinum',
            $count >= 10 => 'gold',
            $count >= 3  => 'silver',
            default      => 'regular',
        };

        return [
            'value'      => $tier,
            'value_type' => 'string',
            'metadata'   => ['total_bookings' => $count],
            'confidence' => 1.0,
        ];
    }

    private function deriveCancellationRate(int $customerId): ?array
    {
        $totals = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Cancelled->value,
                BookingStatus::Paid->value,
                BookingStatus::Completed->value,
            ])
            ->count();

        if ($totals === 0) {
            return null;
        }

        $cancelled = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Cancelled->value)
            ->count();

        $rate = (int) round(($cancelled / max($totals, 1)) * 100);

        return [
            'value'      => (string) $rate,
            'value_type' => 'int',
            'metadata'   => [
                'sample_size'      => $totals,
                'cancelled_count'  => $cancelled,
            ],
            'confidence' => $totals >= 5 ? 0.7 : 0.5,
        ];
    }

    private function deriveBookingsMilestone(Customer $customer): ?array
    {
        $count = (int) ($customer->total_bookings ?? 0);

        if (! in_array($count, self::MILESTONE_THRESHOLDS, true)) {
            return null;
        }

        return [
            'value'      => $count . '_bookings',
            'value_type' => 'string',
            'metadata'   => ['threshold' => $count],
            'confidence' => 1.0,
        ];
    }

    private function deriveCancellationPattern(int $customerId): ?array
    {
        $totals = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Cancelled->value,
                BookingStatus::Paid->value,
                BookingStatus::Completed->value,
            ])
            ->count();

        if ($totals === 0) {
            return null;
        }

        $cancelled = BookingRequest::query()
            ->where('customer_id', $customerId)
            ->where('booking_status', BookingStatus::Cancelled->value)
            ->count();

        $rate = ($cancelled / max($totals, 1)) * 100;

        if ($cancelled >= 3 && $rate > 30) {
            return [
                'value'      => 'frequent_canceller',
                'value_type' => 'string',
                'metadata'   => ['rate' => round($rate, 1), 'sample_size' => $totals],
                'confidence' => 0.7,
            ];
        }

        if ($totals >= 5 && $rate < 10) {
            return [
                'value'      => 'reliable',
                'value_type' => 'string',
                'metadata'   => ['rate' => round($rate, 1), 'sample_size' => $totals],
                'confidence' => 0.7,
            ];
        }

        return null;
    }

    private function deriveLateArrivalPattern(int $customerId): ?array
    {
        // No arrival-time tracking on booking_requests — cannot derive.
        return null;
    }

    private function deriveSpecificDriver(int $customerId): ?array
    {
        // No driver attribution on booking_requests — cannot derive.
        return null;
    }

    private function deriveSpecificVehicle(int $customerId): ?array
    {
        // No vehicle attribution on booking_requests — cannot derive.
        return null;
    }

    private function deriveFrequentCompanion(int $customerId): ?array
    {
        // Companion vs primary passenger cannot be reliably distinguished from
        // the existing schema — skip.
        return null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function classifySeatPosition(string $seat): ?string
    {
        $upper = strtoupper($seat);

        if (str_contains($upper, 'BL') || str_contains($upper, 'BELAKANG')) {
            return 'back';
        }
        if (str_contains($upper, 'CC')) {
            return 'front';
        }
        if (str_contains($upper, 'BS')) {
            return 'middle';
        }

        return null;
    }

    private function defaultConfidenceForSource(string $source): float
    {
        return match ($source) {
            self::SOURCE_EXPLICIT => self::SOURCE_EXPLICIT_DEFAULT_CONFIDENCE,
            self::SOURCE_IMPORTED => self::SOURCE_IMPORTED_DEFAULT_CONFIDENCE,
            self::SOURCE_MANUAL   => self::SOURCE_MANUAL_DEFAULT_CONFIDENCE,
            default               => self::SOURCE_INFERRED_DEFAULT_CONFIDENCE,
        };
    }

    private function clampConfidence(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function stringifyValue(mixed $value, string $valueType): string
    {
        if ($valueType === 'json') {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($valueType === 'bool') {
            return $value ? '1' : '0';
        }
        if ($valueType === 'int') {
            return (string) (int) $value;
        }

        return (string) $value;
    }
}
