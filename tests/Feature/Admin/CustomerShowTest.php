<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_page_can_render_recent_bookings(): void
    {
        $admin = User::factory()->create([
            'is_chatbot_admin' => true,
        ]);

        $customer = Customer::create([
            'name' => 'NCP',
            'phone_e164' => '+628117598804',
            'status' => 'active',
            'total_bookings' => 1,
            'total_spent' => 150000,
            'last_interaction_at' => now(),
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now()->subHour(),
            'last_message_at' => now(),
        ]);

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'NCP',
            'passenger_count' => 1,
            'price_estimate' => 150000,
            'booking_status' => BookingStatus::Draft,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.chatbot.customers.show', $customer));

        $response->assertOk()
            ->assertSee('Booking Terakhir')
            ->assertSee('Pasir Pengaraian')
            ->assertSee('Pekanbaru');
    }
}
