<?php

namespace Tests\Unit\Services\CRM;

use App\Models\AdminNotification;
use App\Models\Customer;
use App\Models\CustomerJourneyEvent;
use App\Models\CustomerMilestone;
use App\Services\CRM\CustomerJourneyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerJourneyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CustomerJourneyService
    {
        return app(CustomerJourneyService::class);
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Journey Test '.uniqid(),
            'phone_e164' => '+62812'.random_int(10000000, 99999999),
            'status' => 'active',
            'total_bookings' => 0,
        ], $overrides));
    }

    public function test_sync_booking_milestones_records_5_bookings_threshold(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 5]);

        $newlyRecorded = $this->service()->syncBookingMilestones($customer);

        $this->assertSame(['5_bookings'], $newlyRecorded);
        $this->assertDatabaseHas('customer_milestones', [
            'customer_id' => $customer->id,
            'milestone_key' => '5_bookings',
            'milestone_category' => 'booking_count',
        ]);
    }

    public function test_sync_booking_milestones_records_multiple_thresholds_at_once(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 25]);

        $newlyRecorded = $this->service()->syncBookingMilestones($customer);

        $this->assertSame(['5_bookings', '10_bookings', '25_bookings'], $newlyRecorded);
        $this->assertSame(3, CustomerMilestone::where('customer_id', $customer->id)->count());
    }

    public function test_sync_booking_milestones_idempotent(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 10]);

        $this->service()->syncBookingMilestones($customer);
        $secondCall = $this->service()->syncBookingMilestones($customer);

        $this->assertSame([], $secondCall);
        $this->assertSame(2, CustomerMilestone::where('customer_id', $customer->id)->count());
    }

    public function test_sync_booking_milestones_skips_below_threshold(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 3]);

        $newlyRecorded = $this->service()->syncBookingMilestones($customer);

        $this->assertSame([], $newlyRecorded);
        $this->assertSame(0, CustomerMilestone::where('customer_id', $customer->id)->count());
    }

    public function test_detect_anniversaries_records_1_year_milestone(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $customer = $this->makeCustomer([
            'first_booking_at' => $now->copy()->subYear(),
        ]);

        $count = $this->service()->detectAnniversaries($now);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('customer_milestones', [
            'customer_id' => $customer->id,
            'milestone_key' => '1_year_anniversary',
            'milestone_category' => 'anniversary',
        ]);

        Carbon::setTestNow();
    }

    public function test_detect_anniversaries_skips_outside_tolerance(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer([
            'first_booking_at' => $now->copy()->subYear()->subDays(5),
        ]);

        $count = $this->service()->detectAnniversaries($now);

        $this->assertSame(0, $count);

        Carbon::setTestNow();
    }

    public function test_detect_anniversaries_idempotent(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer([
            'first_booking_at' => $now->copy()->subYear(),
        ]);

        $this->service()->detectAnniversaries($now);
        $second = $this->service()->detectAnniversaries($now);

        $this->assertSame(0, $second);

        Carbon::setTestNow();
    }

    public function test_detect_at_risk_records_30d_tier(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $customer = $this->makeCustomer([
            'total_bookings' => 1,
            'last_interaction_at' => $now->copy()->subDays(35),
        ]);

        $results = $this->service()->detectAtRiskCustomers($now);

        $this->assertSame(1, $results['at_risk_30d']);
        $this->assertSame(0, $results['at_risk_60d']);
        $this->assertDatabaseHas('customer_milestones', [
            'customer_id' => $customer->id,
            'milestone_key' => 'at_risk_30d',
            'milestone_category' => 'at_risk',
        ]);

        Carbon::setTestNow();
    }

    public function test_detect_at_risk_records_all_three_tiers_for_120d_silent_customer(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $customer = $this->makeCustomer([
            'total_bookings' => 2,
            'last_interaction_at' => $now->copy()->subDays(120),
        ]);

        $results = $this->service()->detectAtRiskCustomers($now);

        $this->assertSame(1, $results['at_risk_30d']);
        $this->assertSame(1, $results['at_risk_60d']);
        $this->assertSame(1, $results['at_risk_90d']);
        $this->assertSame(3, CustomerMilestone::where('customer_id', $customer->id)->count());

        Carbon::setTestNow();
    }

    public function test_detect_at_risk_skips_zero_bookings_customer(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer([
            'total_bookings' => 0,
            'last_interaction_at' => $now->copy()->subDays(35),
        ]);

        $results = $this->service()->detectAtRiskCustomers($now);

        $this->assertSame(0, $results['at_risk_30d']);

        Carbon::setTestNow();
    }

    public function test_get_unacknowledged_milestones_returns_latest_3(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 100]);

        $this->service()->syncBookingMilestones($customer);

        $unacked = $this->service()->getUnacknowledgedMilestones($customer, 3);

        $this->assertCount(3, $unacked);
        $keys = array_column($unacked, 'key');
        foreach ($keys as $key) {
            $this->assertContains($key, ['5_bookings', '10_bookings', '25_bookings', '50_bookings', '100_bookings']);
        }
    }

    public function test_acknowledge_milestone_marks_acknowledged_at(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 5]);
        $this->service()->syncBookingMilestones($customer);

        $this->service()->acknowledgeMilestone($customer, '5_bookings');

        $milestone = CustomerMilestone::where('customer_id', $customer->id)
            ->where('milestone_key', '5_bookings')
            ->first();

        $this->assertNotNull($milestone->acknowledged_at);
    }

    public function test_milestone_creation_dispatches_admin_notification(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 5]);

        $this->service()->syncBookingMilestones($customer);

        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'milestone_booking',
        ]);
    }

    public function test_at_risk_detection_dispatches_admin_notification(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer([
            'total_bookings' => 1,
            'last_interaction_at' => $now->copy()->subDays(35),
        ]);

        $this->service()->detectAtRiskCustomers($now);

        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'milestone_at_risk',
        ]);

        Carbon::setTestNow();
    }

    public function test_milestone_records_journey_event(): void
    {
        $customer = $this->makeCustomer(['total_bookings' => 5]);

        $this->service()->syncBookingMilestones($customer);

        $this->assertDatabaseHas('customer_journey_events', [
            'customer_id' => $customer->id,
            'event_type' => 'milestone_reached',
            'event_key' => '5_bookings',
        ]);
        $this->assertSame(1, CustomerJourneyEvent::where('customer_id', $customer->id)->count());
    }
}
