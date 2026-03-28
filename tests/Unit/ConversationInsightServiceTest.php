<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Services\Chatbot\ConversationInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_booking_slot_summary_from_booking_and_state(): void
    {
        $service = app(ConversationInsightService::class);
        $conversation = $this->makeConversation();

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Raya No 1',
            'destination' => 'Pekanbaru',
            'destination_full_address' => 'Jl Tuanku Tambusai No 7',
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '10:00',
            'passenger_count' => 2,
            'selected_seats' => ['A1', 'A2'],
            'passenger_name' => 'Nerry',
            'payment_method' => 'transfer',
            'price_estimate' => 180000,
        ]);

        ConversationState::create([
            'conversation_id' => $conversation->id,
            'state_key' => 'review_sent',
            'state_value' => true,
        ]);

        ConversationState::create([
            'conversation_id' => $conversation->id,
            'state_key' => 'booking_confirmed',
            'state_value' => true,
        ]);

        $conversation->load(['states', 'bookingRequests', 'customer.tags']);
        $insight = $service->forConversation($conversation);
        $labels = collect($insight['slot_summary'])->pluck('label');

        $this->assertTrue($labels->contains('Payment method'));
        $this->assertTrue($labels->contains('Review status'));
        $this->assertTrue($labels->contains('Confirmation status'));
        $this->assertTrue($labels->contains('Alamat tujuan'));
    }

    private function makeConversation(): Conversation
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        return Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);
    }
}
