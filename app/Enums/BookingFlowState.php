<?php

namespace App\Enums;

enum BookingFlowState: string
{
    case Idle = 'idle';
    case AskingPassengerCount = 'asking_passenger_count';
    case AskingDepartureDate = 'asking_departure_date';
    case AskingDepartureTime = 'asking_departure_time';
    case ShowingAvailableSeats = 'showing_available_seats';
    case AskingPickupPoint = 'asking_pickup_point';
    case AskingPickupAddress = 'asking_pickup_address';
    case AskingDropoffPoint = 'asking_dropoff_point';
    case AskingPassengerNames = 'asking_passenger_names';
    case AskingContactConfirmation = 'asking_contact_confirmation';
    case ShowingReview = 'showing_review';
    case AwaitingFinalConfirmation = 'awaiting_final_confirmation';
    case WaitingAdminTakeover = 'waiting_admin_takeover';
    case Completed = 'completed';

    // Legacy values retained for backward compatibility.
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
            self::AskingPassengerCount,
            self::AskingDepartureDate,
            self::AskingDepartureTime,
            self::ShowingAvailableSeats,
            self::AskingPickupPoint,
            self::AskingPickupAddress,
            self::AskingDropoffPoint,
            self::AskingPassengerNames,
            self::AskingContactConfirmation,
            self::ShowingReview,
            self::AwaitingFinalConfirmation,
            self::CollectingRoute,
            self::CollectingPassenger,
            self::CollectingSchedule,
            self::RouteUnavailable,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::WaitingAdminTakeover,
            self::Completed,
            self::Confirmed,
            self::Closed,
        ], true);
    }
}
