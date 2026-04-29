<?php

namespace Tests\Unit\CRM;

use App\Enums\BookingStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerPreference;
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

    // -------------------------------------------------------------------------
    // PR-CRM-2: Detailed key-value preferences
    // -------------------------------------------------------------------------

    public function test_upsert_preference_creates_new_with_default_confidence(): void
    {
        $customer = $this->makeCustomer();

        $pref = app(CustomerPreferenceUpdaterService::class)->upsertPreference(
            customer:  $customer,
            key:       'preferred_seat_position',
            value:     'front',
            valueType: 'string',
            source:    'inferred',
        );

        $this->assertNotNull($pref);
        $this->assertSame('front', $pref->value);
        $this->assertEqualsWithDelta(0.5, (float) $pref->confidence, 0.001);
        $this->assertSame(1, $pref->metadata['reinforcement_count']);
        $this->assertNotNull($pref->metadata['first_seen_at'] ?? null);
    }

    public function test_upsert_preference_reinforces_when_value_unchanged(): void
    {
        $customer = $this->makeCustomer();
        $service = app(CustomerPreferenceUpdaterService::class);

        $service->upsertPreference($customer, 'preferred_payment_method', 'cash', 'string', 'inferred');
        $second = $service->upsertPreference($customer, 'preferred_payment_method', 'cash', 'string', 'inferred');

        $this->assertNotNull($second);
        $this->assertSame(2, $second->metadata['reinforcement_count']);
        $this->assertEqualsWithDelta(0.6, (float) $second->confidence, 0.001);
    }

    public function test_upsert_preference_replaces_when_value_changes(): void
    {
        $customer = $this->makeCustomer();
        $service = app(CustomerPreferenceUpdaterService::class);

        $service->upsertPreference($customer, 'preferred_seat_position', 'front', 'string', 'inferred');
        $second = $service->upsertPreference($customer, 'preferred_seat_position', 'back', 'string', 'inferred');

        $this->assertNotNull($second);
        $this->assertSame('back', $second->value);
        $this->assertSame(1, $second->metadata['reinforcement_count']);
        $this->assertEqualsWithDelta(0.5, (float) $second->confidence, 0.001);
    }

    public function test_apply_confidence_decay_drops_stale_and_removes_zero(): void
    {
        $customer = $this->makeCustomer();

        $stale = CustomerPreference::create([
            'customer_id'  => $customer->id,
            'key'          => 'old_key',
            'value'        => 'x',
            'value_type'   => 'string',
            'confidence'   => 0.4,
            'source'       => 'inferred',
            'last_seen_at' => now()->subDays(120),
        ]);

        $fresh = CustomerPreference::create([
            'customer_id'  => $customer->id,
            'key'          => 'fresh_key',
            'value'        => 'y',
            'value_type'   => 'string',
            'confidence'   => 0.4,
            'source'       => 'inferred',
            'last_seen_at' => now()->subDays(5),
        ]);

        $tooStale = CustomerPreference::create([
            'customer_id'  => $customer->id,
            'key'          => 'should_drop',
            'value'        => 'z',
            'value_type'   => 'string',
            'confidence'   => 0.05,
            'source'       => 'inferred',
            'last_seen_at' => now()->subDays(120),
        ]);

        $touched = app(CustomerPreferenceUpdaterService::class)->applyConfidenceDecay($customer);

        $this->assertSame(2, $touched);
        $this->assertNull($tooStale->fresh());

        $stale->refresh();
        $this->assertEqualsWithDelta(0.3, (float) $stale->confidence, 0.001);
        $this->assertNotNull($stale->metadata['last_decay_at'] ?? null);

        $this->assertEqualsWithDelta(0.4, (float) $fresh->fresh()->confidence, 0.001);
    }

    public function test_recompute_all_preferences_sets_customer_tier(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        for ($i = 0; $i < 5; $i++) {
            $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);
        }

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $tier = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('customer_tier')
            ->first();

        $this->assertNotNull($tier);
        $this->assertSame('silver', $tier->value);
        $this->assertEqualsWithDelta(1.0, (float) $tier->confidence, 0.001);
    }

    public function test_recompute_all_preferences_sets_milestone_at_threshold(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        for ($i = 0; $i < 5; $i++) {
            $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);
        }

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $milestone = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('total_lifetime_bookings_milestone')
            ->first();

        $this->assertNotNull($milestone);
        $this->assertSame('5_bookings', $milestone->value);
    }

    public function test_recompute_all_preferences_skips_milestone_off_threshold(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        for ($i = 0; $i < 4; $i++) {
            $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);
        }

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $milestone = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('total_lifetime_bookings_milestone')
            ->first();

        $this->assertNull($milestone);
    }

    public function test_recompute_all_preferences_derives_seat_position_from_selected_seats(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        $this->makeBookingWithSeats($customer, $conversation, ['CC1', 'CC2']);
        $this->makeBookingWithSeats($customer, $conversation, ['CC1']);

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $position = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('preferred_seat_position')
            ->first();

        $this->assertNotNull($position);
        $this->assertSame('front', $position->value);
    }

    public function test_recompute_all_preferences_flags_frequent_canceller(): void
    {
        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($customer);

        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Cancelled);
        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Cancelled);
        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Cancelled);
        $this->makeBooking($customer, $conversation, 'Pasir Pengaraian', 'Pekanbaru', '07:00', BookingStatus::Confirmed);

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $pattern = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('cancellation_pattern')
            ->first();

        $this->assertNotNull($pattern);
        $this->assertSame('frequent_canceller', $pattern->value);
    }

    public function test_recompute_all_preferences_skips_customer_with_no_history(): void
    {
        $customer = $this->makeCustomer();

        app(CustomerPreferenceUpdaterService::class)->recomputePreferences($customer);

        $tier = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('customer_tier')
            ->first();

        // tier is rule-based on total_bookings (0 → 'regular') so it WILL be set.
        $this->assertNotNull($tier);
        $this->assertSame('regular', $tier->value);

        // But seat-derivation prefs should be absent.
        $seat = CustomerPreference::query()
            ->forCustomer($customer->id)
            ->forKey('preferred_seat_specific')
            ->first();
        $this->assertNull($seat);
    }

    private function makeBookingWithSeats(
        Customer $customer,
        Conversation $conversation,
        array $seats,
    ): BookingRequest {
        return BookingRequest::create([
            'customer_id'     => $customer->id,
            'conversation_id' => $conversation->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination'     => 'Pekanbaru',
            'departure_date'  => now()->addDay()->toDateString(),
            'departure_time'  => '07:00',
            'passenger_count' => count($seats),
            'selected_seats'  => $seats,
            'price_estimate'  => 150000,
            'booking_status'  => BookingStatus::Confirmed,
        ]);
    }
}
