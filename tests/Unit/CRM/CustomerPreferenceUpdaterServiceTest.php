<?php

namespace Tests\Unit\CRM;

use App\Enums\BookingStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CRM\CustomerPreferenceUpdaterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPreferenceUpdaterServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ], $attrs));
    }

    private function makeConversation(Customer $customer): Conversation
    {
        return Conversation::create([
            'customer_id' => $customer->id,
            'channel' => ConversationChannel::WhatsApp,
            'status' => ConversationStatus::Active,
            'started_at' => now(),
        ]);
    }

    private function makeBooking(
        Customer $customer,
        Conversation $conversation,
        string $pickup,
        string $destination,
        string $time,
        BookingStatus $status,
        int $price = 150000,
    ): BookingRequest {
        return BookingRequest::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'pickup_location' => $pickup,
            'destination' => $destination,
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => $time,
            'passenger_count' => 1,
            'price_estimate' => $price,
            'booking_status' => $status,
        ]);
    }

    public function test_increments_total_bookings_on_update_from_booking(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);
        $booking = $this->makeBooking(
            $customer, $conversation,
            'Pasir Pengaraian', 'Pekanbaru', '07:00',
            BookingStatus::Confirmed, 150000,
        );

        app(CustomerPreferenceUpdaterService::class)->updateFromBooking($booking);

        $customer->refresh();
        $this->assertSame(1, $customer->total_bookings);
        $this->assertEqualsWithDelta(150000.0, (float) $customer->total_spent, 0.01);
        $this->assertNotNull($customer->last_interaction_at);
    }

    public function test_sets_preferences_from_single_booking(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);
        $booking = $this->makeBooking(
            $customer, $conversation,
            'Pasir Pengaraian', 'Pekanbaru', '07:00',
            BookingStatus::Confirmed,
        );

        app(CustomerPreferenceUpdaterService::class)->updateFromBooking($booking);

        $customer->refresh();
        $this->assertSame('Pasir Pengaraian', $customer->preferred_pickup);
        $this->assertSame('Pekanbaru', $customer->preferred_destination);
        $this->assertSame('07:00', $customer->preferred_departure_time?->format('H:i'));
    }

    public function test_recomputes_mode_from_multiple_bookings(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);
        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);
        $this->makeBooking($customer, $conversation, 'Bangkinang', 'Pekanbaru', '13:00', BookingStatus::Confirmed);

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $customer->refresh();
        $this->assertSame('Pasir Pengaraian', $customer->preferred_pickup);
        $this->assertSame('Pekanbaru', $customer->preferred_destination);
        $this->assertSame('07:00', $customer->preferred_departure_time?->format('H:i'));
        $this->assertSame(3, $customer->total_bookings);
    }

    public function test_handles_customer_with_no_confirmed_bookings(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Draft);

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $customer->refresh();
        $this->assertNull($customer->preferred_pickup);
        $this->assertNull($customer->preferred_destination);
        $this->assertSame(0, $customer->total_bookings);
    }

    public function test_touch_interaction_updates_timestamp_only(): void
    {
        $customer = $this->makeCustomer([
            'last_interaction_at' => now()->subHour(),
            'preferred_pickup' => 'Pasir Pengaraian',
        ]);

        app(CustomerPreferenceUpdaterService::class)->touchInteraction($customer);

        $customer->refresh();
        $this->assertNotNull($customer->last_interaction_at);
        $this->assertTrue($customer->last_interaction_at->diffInSeconds(now()) < 5);
        $this->assertSame('Pasir Pengaraian', $customer->preferred_pickup);
    }
}
