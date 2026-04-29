<?php

namespace App\Services\CRM;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\LeadPipeline;

class CRMContextService
{
    public function __construct(
        private readonly JetCrmContextService $jetCrmContextService,
    ) {}

    /**
     * Bangun konteks CRM terpadu untuk AI.
     *
     * CRM tidak lagi hanya menjadi "tambahan" atau writeback di akhir,
     * tetapi menjadi fakta bisnis utama yang dibaca AI sebelum menjawab.
     *
     * @return array<string, mixed>
     */
    public function build(
        Customer $customer,
        ?Conversation $conversation = null,
        ?BookingRequest $booking = null,
    ): array {
        $customer->loadMissing('crmContact', 'tags');

        $hubspot = $this->jetCrmContextService->resolveForCustomer($customer);
        $lead = $this->resolveLead($customer, $conversation, $booking);
        $openEscalation = $this->resolveOpenEscalation($conversation);
        $customerTags = $customer->tags()
            ->latest('id')
            ->limit(20)
            ->pluck('tag')
            ->filter()
            ->values()
            ->all();

        $context = [
            'customer' => $this->clean([
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone_e164' => $customer->phone_e164,
                'email' => $customer->email,
                'total_bookings' => $customer->total_bookings,
                'preferred_pickup' => $customer->preferred_pickup,
                'preferred_destination' => $customer->preferred_destination,
                'last_interaction_at' => $customer->last_interaction_at?->toIso8601String(),
                'tags' => $customerTags,
            ]),

            'hubspot' => $this->clean($hubspot),

            'lead_pipeline' => $this->clean([
                'lead_id' => $lead?->id,
                'stage' => $lead?->stage,
                'owner_admin_id' => $lead?->owner_admin_id,
                'notes' => $lead?->notes,
                'updated_at' => $lead?->updated_at?->toIso8601String(),
            ]),

            'conversation' => $this->clean([
                'conversation_id' => $conversation?->id,
                'status' => $conversation?->status?->value,
                'current_intent' => $conversation?->current_intent,
                'summary' => $conversation?->summary,
                'needs_human' => $conversation?->needs_human,
                'handoff_mode' => $conversation?->handoff_mode,
                'assigned_admin_id' => $conversation?->assigned_admin_id,
                'bot_paused' => $conversation?->bot_paused,
                'last_message_at' => $conversation?->last_message_at?->toIso8601String(),
            ]),

            'booking' => $this->clean([
                'booking_id' => $booking?->id,
                'booking_status' => $booking?->booking_status?->value,
                'pickup_location' => $booking?->pickup_location,
                'destination' => $booking?->destination,
                'departure_date' => $booking?->departure_date?->toDateString(),
                'departure_time' => $booking?->departure_time,
                'passenger_count' => $booking?->passenger_count,
                'payment_method' => $booking?->payment_method,
                'ready_for_confirmation' => $booking?->isReadyForConfirmation(),
                'missing_fields' => $booking?->missingFields() ?? [],
            ]),

            'escalation' => $this->clean([
                'has_open_escalation' => $openEscalation !== null,
                'escalation_id' => $openEscalation?->id,
                'status' => $openEscalation?->status,
                'priority' => $openEscalation?->priority,
                'reason' => $openEscalation?->reason,
                'assigned_admin_id' => $openEscalation?->assigned_admin_id,
            ]),

            'business_flags' => $this->clean([
                'customer_is_new' => (int) $customer->total_bookings === 0,
                'customer_is_returning' => (int) $customer->total_bookings > 0,
                'needs_human_followup' => (bool) ($conversation?->needs_human ?? false) || $openEscalation !== null,
                'admin_takeover_active' => (bool) ($conversation?->isAdminTakeover() ?? false),
                'bot_paused' => (bool) ($conversation?->bot_paused ?? false),
            ]),
        ];

        return $context;
    }

    private function resolveLead(
        Customer $customer,
        ?Conversation $conversation = null,
        ?BookingRequest $booking = null,
    ): ?LeadPipeline {
        $query = LeadPipeline::query()
            ->where('customer_id', $customer->id)
            ->latest();

        if ($conversation !== null) {
            $query->where(function ($q) use ($conversation): void {
                $q->where('conversation_id', $conversation->id)
                    ->orWhereNull('conversation_id');
            });
        }

        if ($booking !== null) {
            $query->where(function ($q) use ($booking): void {
                $q->where('booking_request_id', $booking->id)
                    ->orWhereNull('booking_request_id');
            });
        }

        return $query->first();
    }

    private function resolveOpenEscalation(?Conversation $conversation): ?Escalation
    {
        if ($conversation === null) {
            return null;
        }

        return Escalation::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['open', 'assigned'])
            ->latest()
            ->first();
    }

    /**
     * Bersihkan null / string kosong dari array rekursif,
     * tetapi tetap pertahankan boolean false dan angka 0.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clean(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->clean($value);

                if ($payload[$key] === []) {
                    unset($payload[$key]);
                }

                continue;
            }

            if ($value === null) {
                unset($payload[$key]);

                continue;
            }

            if (is_string($value) && trim($value) === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}
