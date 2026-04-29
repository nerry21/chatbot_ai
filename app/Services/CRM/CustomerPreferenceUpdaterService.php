<?php

namespace App\Services\CRM;

use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\Customer;
use App\Support\WaLog;
use Illuminate\Support\Facades\DB;

class CustomerPreferenceUpdaterService
{
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
}
