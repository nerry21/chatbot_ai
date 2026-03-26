<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LeadPipeline;
use Illuminate\Support\Facades\Log;

class LeadPipelineService
{
    /**
     * Valid pipeline stages in lifecycle order.
     *
     * Transitions should generally move forward, but complaint/cancelled
     * can be reached from any non-terminal stage.
     *
     * new_lead → engaged → awaiting_confirmation → confirmed → paid → completed
     *                                            → cancelled
     *                    → complaint (any stage where customer has an issue)
     */
    public const STAGES = [
        'new_lead',
        'engaged',
        'awaiting_confirmation',
        'confirmed',
        'paid',
        'completed',
        'cancelled',
        'complaint',
    ];

    /** Stages from which we do NOT auto-advance via syncFromContext. */
    private const TERMINAL_STAGES = ['completed', 'cancelled', 'paid'];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Find the most recent non-terminal lead for this customer/conversation,
     * or create a new one at stage new_lead.
     */
    public function findOrCreateLead(
        Customer $customer,
        ?Conversation $conversation = null,
        ?BookingRequest $booking = null,
    ): LeadPipeline {
        $query = LeadPipeline::where('customer_id', $customer->id)
            ->whereNotIn('stage', self::TERMINAL_STAGES)
            ->latest();

        if ($conversation !== null) {
            $query->where('conversation_id', $conversation->id);
        }

        $lead = $query->first();

        if ($lead === null) {
            $lead = LeadPipeline::create([
                'customer_id'        => $customer->id,
                'conversation_id'    => $conversation?->id,
                'booking_request_id' => $booking?->id,
                'stage'              => 'new_lead',
            ]);
        }

        return $lead;
    }

    /**
     * Move a lead to a specific stage.
     * Silently ignores unknown stage names.
     */
    public function moveToStage(LeadPipeline $lead, string $stage): LeadPipeline
    {
        if (! in_array($stage, self::STAGES, true)) {
            Log::warning('[LeadPipeline] Attempted to move to unknown stage', [
                'lead_id' => $lead->id,
                'stage'   => $stage,
            ]);

            return $lead;
        }

        $lead->update(['stage' => $stage]);

        return $lead->fresh();
    }

    /**
     * Full sync: find/create lead, derive target stage from context, advance if needed.
     * Always graceful — returns null on unrecoverable error.
     */
    public function syncFromContext(
        Customer $customer,
        ?Conversation $conversation = null,
        ?BookingRequest $booking = null,
        ?string $intent = null,
    ): ?LeadPipeline {
        try {
            $lead = $this->findOrCreateLead($customer, $conversation, $booking);

            // Attach booking reference if it was not set on creation.
            if ($booking !== null && $lead->booking_request_id === null) {
                $lead->update(['booking_request_id' => $booking->id]);
            }

            $targetStage = $this->resolveStage($lead->stage, $booking, $intent);

            if ($targetStage !== $lead->stage) {
                $lead = $this->moveToStage($lead, $targetStage);

                Log::info('[LeadPipeline] Stage advanced', [
                    'lead_id'      => $lead->id,
                    'customer_id'  => $customer->id,
                    'from'         => $lead->getOriginal('stage') ?? '?',
                    'to'           => $targetStage,
                ]);
            }

            return $lead;
        } catch (\Throwable $e) {
            Log::error('[LeadPipeline] syncFromContext failed', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the best next stage from the current booking status and intent.
     *
     * Rules (applied in priority order):
     * 1. Terminal stages are never downgraded.
     * 2. complaint intent → complaint stage.
     * 3. Booking status drives confirmed / awaiting_confirmation / cancelled.
     * 4. Any active booking engagement moves new_lead → engaged.
     * 5. No booking, but customer replied → new_lead → engaged.
     */
    private function resolveStage(
        string $currentStage,
        ?BookingRequest $booking,
        ?string $intent,
    ): string {
        if (in_array($currentStage, self::TERMINAL_STAGES, true)) {
            return $currentStage;
        }

        // Intent override: complaint
        if ($intent !== null) {
            $intentEnum = IntentType::tryFrom($intent);

            if ($intentEnum === IntentType::Support) {
                return 'complaint';
            }
        }

        // Booking status drives the stage
        if ($booking !== null) {
            return match ($booking->booking_status->value) {
                'confirmed'            => 'confirmed',
                'awaiting_confirmation' => 'awaiting_confirmation',
                'paid'                 => 'paid',
                'completed'            => 'completed',
                'cancelled'            => 'cancelled',
                // Draft booking = customer is actively engaging
                default                => $currentStage === 'new_lead' ? 'engaged' : $currentStage,
            };
        }

        // No booking — customer is engaging but has not started a booking yet
        if ($currentStage === 'new_lead') {
            return 'engaged';
        }

        return $currentStage;
    }
}
