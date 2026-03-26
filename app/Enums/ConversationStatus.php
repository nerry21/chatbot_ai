<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Active    = 'active';
    case Closed    = 'closed';
    case Escalated = 'escalated';
    case Archived  = 'archived';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Closed    => 'Closed',
            self::Escalated => 'Escalated',
            self::Archived  => 'Archived',
        };
    }

    public function isTerminal(): bool
    {
        return match($this) {
            self::Closed, self::Archived => true,
            default                      => false,
        };
    }
}
