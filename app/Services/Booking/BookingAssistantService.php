<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\IntentType;
use App\Models\BookingIntent;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Log;

class BookingAssistantService
{
    public function __construct(
        private readonly PricingService            $pricing,
        private readonly RouteValidationService    $routeValidator,
        private readonly AvailabilityService       $availability,
        private readonly BookingConfirmationService $confirmation,
    ) {}

    // -------------------------------------------------------------------------
    // Draft management
    // -------------------------------------------------------------------------

    /**
     * Find an existing non-terminal booking for this conversation, or create
     * a fresh draft.  This is the primary entry point for the booking engine.
     */
    public function findOrCreateDraft(Conversation $conversation): BookingRequest
    {
        return $this->findExistingDraft($conversation) ?? BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id'     => $conversation->customer_id,
            'booking_status'  => BookingStatus::Draft,
        ]);
    }

    /**
     * Return the latest active (draft or awaiting_confirmation) booking for
     * this conversation without creating a new one.
     */
    public function findExistingDraft(Conversation $conversation): ?BookingRequest
    {
        return BookingRequest::query()
            ->forConversation($conversation->id)
            ->active()
            ->latest()
            ->first();
    }

    // -------------------------------------------------------------------------
    // Entity application
    // -------------------------------------------------------------------------

    /**
     * Apply AI-extracted entities to the booking draft and persist a snapshot
     * into booking_intents for audit purposes.
     *
     * @param  array<string, mixed>  $entities      Output of EntityExtractorService.
     * @param  array<string, mixed>  $rawAiPayload  Full intent + entity context snapshot.
     */
    public function applyExtraction(
        BookingRequest $booking,
        array $entities,
        ?ConversationMessage $message = null,
        array $rawAiPayload = [],
    ): BookingRequest {
        // 1. Merge extracted entities into the booking (non-destructive)
        $booking->fillFromEntities($entities);

        // 2. Refresh price estimate if both locations are now available
        if ($booking->pickup_location !== null && $booking->destination !== null) {
            $estimate = $this->pricing->estimate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );

            if ($estimate !== null) {
                $booking->price_estimate = $estimate;
            }
        }

        $booking->save();

        // 3. Record intent snapshot for audit
        $this->recordIntent($booking, $rawAiPayload, $entities, $message);

        return $booking;
    }

    // -------------------------------------------------------------------------
    // Decision engine
    // -------------------------------------------------------------------------

    /**
     * Evaluate the current booking state + intent and decide what to do next.
     *
     * @param  string  $intent  Raw intent string (IntentType value).
     * @return array{action: string, missing_fields: array<int,string>, booking_status: string}
     */
    public function decideNextStep(BookingRequest $booking, string $intent): array
    {
        $intentEnum = IntentType::tryFrom($intent);

        // ── Handle cancellation ────────────────────────────────────────────
        if ($intentEnum === IntentType::BookingCancel) {
            $booking->markCancelled();
            $booking->save();

            return $this->decision('booking_cancelled', [], $booking->booking_status);
        }

        // ── Handle confirmation ────────────────────────────────────────────
        if (
            ($intentEnum === IntentType::Confirmation || $intentEnum === IntentType::BookingConfirm)
            && $booking->isAwaitingConfirmation()
        ) {
            $this->confirmation->confirm($booking);

            return $this->decision('confirmed', [], $booking->booking_status);
        }

        // ── Handle rejection of pending confirmation ───────────────────────
        if ($intentEnum === IntentType::Rejection && $booking->isAwaitingConfirmation()) {
            $booking->resetToDraft();
            $booking->save();

            Log::info('BookingAssistantService: customer rejected confirmation, reset to draft', [
                'booking_id' => $booking->id,
            ]);

            return $this->decision('general_reply', [], $booking->booking_status);
        }

        // ── Validate route (only when both locations present) ─────────────
        if ($booking->pickup_location !== null && $booking->destination !== null) {
            if (! $this->routeValidator->isSupported($booking->pickup_location, $booking->destination)) {
                return $this->decision('unsupported_route', [], $booking->booking_status);
            }
        }

        // ── Check missing required fields ──────────────────────────────────
        $missingFields = $booking->missingFields();

        if (! empty($missingFields)) {
            return $this->decision('ask_missing_fields', $missingFields, $booking->booking_status);
        }

        // ── All required fields present — check availability (stub) ───────
        $availData = [
            'pickup_location' => $booking->pickup_location,
            'destination'     => $booking->destination,
            'departure_date'  => $booking->departure_date?->toDateString(),
            'departure_time'  => $booking->departure_time,
            'passenger_count' => $booking->passenger_count,
        ];

        if (! $this->availability->canSchedule($availData)) {
            $reason = $this->availability->explainUnavailability($availData);
            return $this->decision('unavailable', [], $booking->booking_status, ['reason' => $reason]);
        }

        // ── Ready → ask for confirmation ───────────────────────────────────
        $this->confirmation->requestConfirmation($booking);

        return $this->decision('ask_confirmation', [], $booking->booking_status);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Build a consistent decision array.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function decision(
        string $action,
        array $missingFields,
        BookingStatus $status,
        array $extra = [],
    ): array {
        return array_merge([
            'action'         => $action,
            'missing_fields' => $missingFields,
            'booking_status' => $status->value,
        ], $extra);
    }

    /**
     * Persist a BookingIntent snapshot for audit and debugging.
     *
     * @param  array<string, mixed>  $rawAiPayload
     * @param  array<string, mixed>  $entities
     */
    private function recordIntent(
        BookingRequest $booking,
        array $rawAiPayload,
        array $entities,
        ?ConversationMessage $message,
    ): void {
        $intent     = $rawAiPayload['intent_result']['intent'] ?? 'unknown';
        $confidence = (float) ($rawAiPayload['intent_result']['confidence'] ?? 0);

        BookingIntent::create([
            'booking_request_id'      => $booking->id,
            'conversation_message_id' => $message?->id,
            'detected_intent'         => $intent,
            'confidence'              => $confidence,
            'extracted_entities'      => $entities,
            'raw_ai_payload'          => $rawAiPayload,
        ]);
    }
}
