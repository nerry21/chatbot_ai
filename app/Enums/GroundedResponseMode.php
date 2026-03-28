<?php

namespace App\Enums;

enum GroundedResponseMode: string
{
    case DirectAnswer = 'direct_answer';
    case ClarificationQuestion = 'clarification_question';
    case BookingContinuation = 'booking_continuation';
    case PoliteRefusal = 'polite_refusal';
    case HandoffMessage = 'handoff_message';
}
