<?php

namespace App\Enums;

enum LearningFailureType: string
{
    case WrongIntent = 'wrong_intent';
    case WrongContextResolution = 'wrong_context_resolution';
    case MissingEntity = 'missing_entity';
    case TooEarlyFallback = 'too_early_fallback';
    case RepetitiveReply = 'repetitive_reply';
    case UnsafeHallucinationAttempt = 'unsafe_hallucination_attempt';
    case PoorClarification = 'poor_clarification';
    case TakeoverConflict = 'takeover_conflict';
}
