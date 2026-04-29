<?php

namespace Tests\Unit\Console;

use App\Models\Customer;
use App\Models\CustomerMilestone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCustomerJourneyCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Sync Test '.uniqid(),
            'phone_e164' => '+62812'.random_int(10000000, 99999999),
            'status' => 'active',
            'total_bookings' => 0,
        ], $overrides));
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->makeCustomer(['total_bookings' => 5]);

        $exitCode = $this->artisan('chatbot:sync-customer-journey', [
            '--type' => 'booking',
            '--dry-run' => true,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, CustomerMilestone::count());
    }

    public function test_type_booking_only_runs_booking_sync(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer(['total_bookings' => 5]);

        $this->makeCustomer([
            'total_bookings' => 1,
            'last_interaction_at' => $now->copy()->subDays(35),
        ]);

        $exitCode = $this->artisan('chatbot:sync-customer-journey', ['--type' => 'booking'])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, CustomerMilestone::where('milestone_category', 'booking_count')->count());
        $this->assertSame(0, CustomerMilestone::where('milestone_category', 'at_risk')->count());

        Carbon::setTestNow();
    }

    public function test_full_sync_runs_all_three(): void
    {
        $now = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($now);

        $this->makeCustomer(['total_bookings' => 5]);

        $this->makeCustomer([
            'total_bookings' => 1,
            'first_booking_at' => $now->copy()->subYear(),
        ]);

        $this->makeCustomer([
            'total_bookings' => 1,
            'last_interaction_at' => $now->copy()->subDays(35),
        ]);

        $exitCode = $this->artisan('chatbot:sync-customer-journey', ['--type' => 'all'])->run();

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThanOrEqual(1, CustomerMilestone::where('milestone_category', 'booking_count')->count());
        $this->assertSame(1, CustomerMilestone::where('milestone_category', 'anniversary')->count());
        $this->assertSame(1, CustomerMilestone::where('milestone_category', 'at_risk')->count());

        Carbon::setTestNow();
    }
}
