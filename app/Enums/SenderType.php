<?php

namespace App\Enums;

enum SenderType: string
{
    case Customer = 'customer';
    case Bot      = 'bot';
    case Admin    = 'admin';
    case Agent    = 'agent';
    case System   = 'system';

    public function label(): string
    {
        return match($this) {
            self::Customer => 'Customer',
            self::Bot      => 'Bot',
            self::Admin    => 'Admin',
            self::Agent    => 'Agent',
            self::System   => 'System',
        };
    }

    public function isHuman(): bool
    {
        return match($this) {
            self::Customer, self::Admin, self::Agent => true,
            default                     => false,
        };
    }
}
