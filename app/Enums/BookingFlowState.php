<?php

namespace App\Enums;

enum BookingFlowState: string
{
    case Idle = 'idle';
    case CollectingRoute = 'collecting_route';
    case CollectingPassenger = 'collecting_passenger';
    case CollectingSchedule = 'collecting_schedule';
    case RouteUnavailable = 'route_unavailable';
    case ReadyToConfirm = 'ready_to_confirm';
    case Confirmed = 'confirmed';
    case Closed = 'closed';

    public function isCollecting(): bool
    {
        return in_array($this, [
            self::CollectingRoute,
            self::CollectingPassenger,
            self::CollectingSchedule,
            self::RouteUnavailable,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Closed,
        ], true);
    }
}
