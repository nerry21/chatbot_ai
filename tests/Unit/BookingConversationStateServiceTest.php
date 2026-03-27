<?php

namespace Tests\Unit;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingConversationStateService;
use App\Services\Chatbot\ConversationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConversationStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hydrates_missing_slots_from_existing_draft_booking(): void
    {
        $service = app(BookingConversationStateService::class);
        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'booking_status' => BookingStatus::Draft,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Pekanbaru', $slots['destination']);
        $this->assertSame('Nerry', $slots['passenger_name']);
        $this->assertSame(2, $slots['passenger_count']);
        $this->assertSame('2026-03-28', $slots['travel_date']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertFalse($slots['review_sent']);
    }

    public function test_it_marks_ready_to_confirm_when_hydrating_pending_review_booking(): void
    {
        $service = app(BookingConversationStateService::class);
        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'payment_method' => 'transfer',
            'booking_status' => BookingStatus::AwaitingConfirmation,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame(BookingFlowState::ReadyToConfirm->value, $slots['booking_intent_status']);
        $this->assertTrue($slots['review_sent']);
        $this->assertFalse($slots['booking_confirmed']);
    }

    public function test_it_transitions_flow_state_with_expected_input_snapshot(): void
    {
        $service = app(BookingConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $service->transitionFlowState(
            $conversation,
            BookingFlowState::CollectingSchedule,
            'travel_date',
            'test_transition',
        );

        $snapshot = $service->snapshot($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingSchedule->value, $snapshot['booking_intent_status']);
        $this->assertSame('travel_date', $snapshot['expected_input']);
    }

    public function test_it_normalizes_legacy_collecting_state_into_explicit_flow_state(): void
    {
        $service = app(BookingConversationStateService::class);
        $conversationState = app(ConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $conversationState->put($conversation, 'booking_intent_status', 'collecting');
        $conversationState->put($conversation, 'destination', 'Pekanbaru');

        $slots = $service->load($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingRoute->value, $slots['booking_intent_status']);
    }

    public function test_it_classifies_tracked_slot_changes_into_created_and_overwritten(): void
    {
        $service = app(BookingConversationStateService::class);

        $changes = $service->trackedSlotChanges([
            'pickup_location' => ['old' => 'Panam', 'new' => 'Pasir Pengaraian'],
            'passenger_name' => ['old' => 'Nerry', 'new' => 'Andi'],
            'travel_date' => ['old' => null, 'new' => '2026-03-28'],
            'route_status' => ['old' => 'unsupported', 'new' => 'supported'],
        ]);

        $this->assertArrayHasKey('pickup_location', $changes['overwritten']);
        $this->assertArrayHasKey('passenger_name', $changes['overwritten']);
        $this->assertArrayHasKey('travel_date', $changes['created']);
        $this->assertArrayNotHasKey('route_status', $changes['overwritten']);
        $this->assertSame('Panam', $changes['overwritten']['pickup_location']['old']);
        $this->assertSame('Pasir Pengaraian', $changes['overwritten']['pickup_location']['new']);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }
}
