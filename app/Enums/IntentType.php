<?php

namespace App\Enums;

enum IntentType: string
{
    // ── Existing cases (Tahap 1 — backward-compatible) ──────────────────────
    case Greeting          = 'greeting';
    case Booking           = 'booking';
    case BookingConfirm    = 'booking_confirm';
    case BookingCancel     = 'booking_cancel';
    case ScheduleInquiry   = 'schedule_inquiry';
    case PriceInquiry      = 'price_inquiry';
    case LocationInquiry   = 'location_inquiry';
    case Support           = 'support';
    case HumanHandoff      = 'human_handoff';
    case Farewell          = 'farewell';
    case OutOfScope        = 'out_of_scope';
    case Unknown           = 'unknown';

    // ── New cases (Tahap 3) ──────────────────────────────────────────────────

    /** Customer replies yes/ok/benar/setuju to a bot question. */
    case Confirmation      = 'confirmation';

    /** Customer replies no/tidak jadi/batal to a bot question or offer. */
    case Rejection         = 'rejection';

    // -------------------------------------------------------------------------

    public function label(): string
    {
        return match($this) {
            self::Greeting        => 'Greeting',
            self::Booking         => 'Booking Request',
            self::BookingConfirm  => 'Booking Confirmation',
            self::BookingCancel   => 'Booking Cancellation',
            self::ScheduleInquiry => 'Schedule Inquiry',
            self::PriceInquiry    => 'Price Inquiry',
            self::LocationInquiry => 'Location Inquiry',
            self::Support         => 'Support',
            self::HumanHandoff    => 'Human Handoff Request',
            self::Farewell        => 'Farewell',
            self::OutOfScope      => 'Out of Scope',
            self::Unknown         => 'Unknown',
            self::Confirmation    => 'Confirmation',
            self::Rejection       => 'Rejection',
        };
    }

    public function requiresHuman(): bool
    {
        return match($this) {
            self::HumanHandoff, self::Support => true,
            default                           => false,
        };
    }

    /**
     * Whether this intent should trigger the booking engine.
     *
     * Rejection is included because the booking engine must handle the case
     * where a customer rejects a pending confirmation summary.
     * PriceInquiry and ScheduleInquiry are included so the engine can show
     * pricing/availability context when a draft booking already exists.
     */
    public function isBookingRelated(): bool
    {
        return match($this) {
            self::Booking,
            self::BookingConfirm,
            self::BookingCancel,
            self::Confirmation,
            self::Rejection,
            self::PriceInquiry,
            self::ScheduleInquiry => true,
            default               => false,
        };
    }

    /**
     * Whether this intent is a clear terminal signal for the current conversation turn.
     */
    public function isConversationEnder(): bool
    {
        return match($this) {
            self::Farewell, self::HumanHandoff => true,
            default                            => false,
        };
    }
}
