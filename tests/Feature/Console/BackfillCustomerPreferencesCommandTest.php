<?php

namespace Tests\Feature\Console;

use App\Enums\BookingStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillCustomerPreferencesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomerWithBooking(string $name, string $phone, string $pickup, string $destination): Customer
    {
        $customer = Customer::create([
            'name' => $name,
            'phone_e164' => $phone,
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => ConversationChannel::WhatsApp,
            'status' => ConversationStatus::Active,
            'started_at' => now(),
        ]);

        BookingRequest::create([
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'pickup_location' => $pickup,
            'destination' => $destination,
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '07:00',
            'passenger_count' => 1,
            'price_estimate' => 150000,
            'booking_status' => BookingStatus::Confirmed,
        ]);

        return $customer;
    }

    public function test_backfills_all_customers_with_bookings(): void
    {
        $a = $this->makeCustomerWithBooking('A', '+6281000000001', 'Pasir Pengaraian', 'Pekanbaru');
        $b = $this->makeCustomerWithBooking('B', '+6281000000002', 'Bangkinang', 'Pekanbaru');

        $orphan = Customer::create([
            'name' => 'Orphan',
            'phone_e164' => '+6281000000003',
            'status' => 'active',
        ]);

        $this->artisan('chatbot:backfill-customer-preferences')->assertExitCode(0);

        $a->refresh();
        $b->refresh();
        $orphan->refresh();

        $this->assertSame('Pasir Pengaraian', $a->preferred_pickup);
        $this->assertSame(1, $a->total_bookings);
        $this->assertSame('Bangkinang', $b->preferred_pickup);
        $this->assertSame(1, $b->total_bookings);
        $this->assertNull($orphan->preferred_pickup);
        $this->assertSame(0, $orphan->total_bookings);
    }

    public function test_respects_limit_option(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCustomerWithBooking(
                "C{$i}",
                '+62810000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'Pasir Pengaraian',
                'Pekanbaru',
            );
        }

        $this->artisan('chatbot:backfill-customer-preferences', ['--limit' => 2])
            ->assertExitCode(0);

        $updated = Customer::query()
            ->where('total_bookings', '>=', 1)
            ->count();

        $this->assertSame(2, $updated);
    }
}
