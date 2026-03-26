<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Draft                = 'draft';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed            = 'confirmed';
    case Paid                 = 'paid';
    case Cancelled            = 'cancelled';
    case Completed            = 'completed';

    public function label(): string
    {
        return match($this) {
            self::Draft                => 'Draft',
            self::AwaitingConfirmation => 'Menunggu Konfirmasi',
            self::Confirmed            => 'Dikonfirmasi',
            self::Paid                 => 'Lunas',
            self::Cancelled            => 'Dibatalkan',
            self::Completed            => 'Selesai',
        };
    }

    /**
     * Terminal statuses can no longer be acted upon by the bot.
     */
    public function isTerminal(): bool
    {
        return match($this) {
            self::Confirmed,
            self::Paid,
            self::Cancelled,
            self::Completed => true,
            default         => false,
        };
    }

    /**
     * Statuses that require staff or system attention.
     */
    public function requiresAttention(): bool
    {
        return match($this) {
            self::AwaitingConfirmation,
            self::Confirmed            => true,
            default                    => false,
        };
    }

    /**
     * Whether this status means the booking is still in progress / editable.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
