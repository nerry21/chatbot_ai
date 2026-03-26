<?php

namespace App\Enums;

enum MessageDeliveryStatus: string
{
    case Pending   = 'pending';
    case Sent      = 'sent';
    case Failed    = 'failed';
    case Skipped   = 'skipped';
    case Delivered = 'delivered'; // Reserved for future provider webhook confirmation

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Sent      => 'Terkirim',
            self::Failed    => 'Gagal',
            self::Skipped   => 'Dilewati',
            self::Delivered => 'Diterima',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::Skipped, self::Delivered => true,
            self::Pending => false,
        };
    }
}
