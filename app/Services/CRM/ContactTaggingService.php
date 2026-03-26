<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactTaggingService
{
    /**
     * Derive and persist tags for a customer based on current context signals.
     *
     * Tags are idempotent — applying the same tag twice is safe due to the
     * unique composite index on (customer_id, tag).
     *
     * @return array<int, string>  Tags that were successfully written.
     */
    public function applyBasicTags(
        Customer $customer,
        ?BookingRequest $booking = null,
        ?string $intent = null,
    ): array {
        $tags    = $this->resolveTags($customer, $booking, $intent);
        $applied = [];

        foreach ($tags as $tag) {
            try {
                DB::table('customer_tags')->insertOrIgnore([
                    'customer_id' => $customer->id,
                    'tag'         => $tag,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                $applied[] = $tag;
            } catch (\Throwable $e) {
                Log::warning('[ContactTagging] Failed to apply tag', [
                    'customer_id' => $customer->id,
                    'tag'         => $tag,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        Log::debug('[ContactTagging] Tags applied', [
            'customer_id' => $customer->id,
            'tags'        => $applied,
        ]);

        return $applied;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine which tags are relevant given the current context.
     *
     * Tag catalogue:
     *   pelanggan_baru       — first time interacting (total_bookings == 0)
     *   pelanggan_lama       — has at least one past booking
     *   pernah_booking       — has booked at least once (complementary to lama)
     *   booking_draft        — active draft booking in progress
     *   booking_confirmed    — booking just confirmed
     *   butuh_followup       — awaiting confirmation or support-related intent
     *   human_handoff        — customer requested or triggered human escalation
     *
     * @return array<int, string>
     */
    private function resolveTags(
        Customer $customer,
        ?BookingRequest $booking,
        ?string $intent,
    ): array {
        $tags = [];

        // ── Customer lifecycle ──────────────────────────────────────────────
        if ($customer->total_bookings > 0) {
            $tags[] = 'pelanggan_lama';
            $tags[] = 'pernah_booking';
        } else {
            $tags[] = 'pelanggan_baru';
        }

        // ── Booking-based signals ───────────────────────────────────────────
        if ($booking !== null) {
            $statusValue = $booking->booking_status->value;

            if ($statusValue === 'draft') {
                $tags[] = 'booking_draft';
            }

            if ($statusValue === 'confirmed') {
                $tags[] = 'booking_confirmed';
            }

            if ($statusValue === 'awaiting_confirmation') {
                $tags[] = 'butuh_followup';
            }
        }

        // ── Intent-based signals ────────────────────────────────────────────
        if ($intent !== null) {
            $intentEnum = IntentType::tryFrom($intent);

            if ($intentEnum === IntentType::HumanHandoff) {
                $tags[] = 'human_handoff';
            }

            if ($intentEnum === IntentType::Support) {
                $tags[] = 'butuh_followup';
            }
        }

        return array_unique($tags);
    }
}
