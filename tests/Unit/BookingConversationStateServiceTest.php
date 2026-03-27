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
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'selected_seats' => ['CC', 'BS'],
            'booking_status' => BookingStatus::Draft,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame(2, $slots['passenger_count']);
        $this->assertSame('2026-03-28', $slots['travel_date']);
        $this->assertSame(['CC', 'BS'], $slots['selected_seats']);
        $this->assertSame(BookingFlowState::AskingPickupPoint->value, $slots['booking_intent_status']);
        $this->assertSame('pickup_location', $service->nextRequiredInput($slots));
    }

    public function test_it_marks_ready_to_confirm_when_hydrating_pending_review_booking(): void
    {
        $service = app(BookingConversationStateService::class);
        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Andi',
            'passenger_names' => ['Andi'],
            'passenger_count' => 1,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'selected_seats' => ['CC'],
            'contact_number' => '+6281234567890',
            'booking_status' => BookingStatus::AwaitingConfirmation,
        ]);

        $slots = $service->hydrateFromBooking($conversation, $booking);

        $this->assertSame(BookingFlowState::AwaitingFinalConfirmation->value, $slots['booking_intent_status']);
        $this->assertTrue($slots['review_sent']);
        $this->assertFalse($slots['booking_confirmed']);
    }

    public function test_it_transitions_flow_state_with_expected_input_snapshot(): void
    {
        $service = app(BookingConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $service->transitionFlowState(
            $conversation,
            BookingFlowState::AskingDepartureDate,
            'travel_date',
            'test_transition',
        );

        $snapshot = $service->snapshot($conversation->fresh());

        $this->assertSame(BookingFlowState::AskingDepartureDate->value, $snapshot['booking_intent_status']);
        $this->assertSame('travel_date', $snapshot['expected_input']);
    }

    public function test_it_normalizes_legacy_collecting_state_into_the_next_required_step(): void
    {
        $service = app(BookingConversationStateService::class);
        $conversationState = app(ConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $conversationState->put($conversation, 'booking_intent_status', 'collecting');
        $conversationState->put($conversation, 'destination', 'Pekanbaru');

        $slots = $service->load($conversation->fresh());

        $this->assertSame(BookingFlowState::AskingPassengerCount->value, $slots['booking_intent_status']);
        $this->assertSame('passenger_count', $service->nextRequiredInput($slots));
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
    }

    public function test_it_repairs_inconsistent_state_to_the_next_safe_step(): void
    {
        $service = app(BookingConversationStateService::class);
        $conversationState = app(ConversationStateService::class);
        [, $conversation] = $this->makeConversation();

        $conversationState->put($conversation, 'booking_intent_status', BookingFlowState::Completed->value);
        $conversationState->put($conversation, 'review_sent', true);
        $conversationState->put($conversation, 'booking_confirmed', true);
        $conversationState->put($conversation, 'destination', 'Pekanbaru');
        $conversationState->put($conversation, BookingConversationStateService::EXPECTED_INPUT_KEY, 'invalid_step');

        $slots = $service->repairCorruptedState($conversation->fresh());

        $this->assertSame(BookingFlowState::AskingPassengerCount->value, $slots['booking_intent_status']);
        $this->assertFalse($slots['review_sent']);
        $this->assertFalse($slots['booking_confirmed']);
        $this->assertSame('passenger_count', $service->expectedInput($conversation->fresh()));
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
