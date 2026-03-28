<?php

namespace Tests\Unit\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;
use App\Services\Booking\Guardrails\ActionEligibilityValidatorService;
use Tests\TestCase;

class ActionEligibilityValidatorServiceTest extends TestCase
{
    public function test_it_clarifies_confirmation_without_active_review(): void
    {
        $result = app(ActionEligibilityValidatorService::class)->validate(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::KonfirmasiBooking->value,
                'confidence' => 0.93,
            ],
            slots: [
                'review_sent' => false,
                'booking_confirmed' => false,
                'booking_intent_status' => 'idle',
                'waiting_admin_takeover' => false,
            ],
        );

        $this->assertSame('clarify', $result['meta']['action']);
        $this->assertSame('guard.action_eligibility', $result['reply']['meta']['source']);
        $this->assertTrue($result['intent_result']['needs_clarification']);
    }

    public function test_it_routes_booking_cancellation_to_admin_handoff(): void
    {
        $result = app(ActionEligibilityValidatorService::class)->validate(
            conversation: new Conversation(['handoff_mode' => 'bot']),
            intentResult: [
                'intent' => IntentType::BookingCancel->value,
                'confidence' => 0.95,
            ],
            slots: [
                'review_sent' => true,
                'booking_confirmed' => false,
                'booking_intent_status' => 'awaiting_final_confirmation',
                'waiting_admin_takeover' => false,
                'destination' => 'Pekanbaru',
            ],
        );

        $this->assertSame('handoff', $result['meta']['action']);
        $this->assertSame(IntentType::HumanHandoff->value, $result['intent_result']['intent']);
        $this->assertSame('guard.action_eligibility', $result['reply']['meta']['source']);
    }
}
