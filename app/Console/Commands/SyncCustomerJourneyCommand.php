<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CRM\CustomerJourneyService;
use App\Support\WaLog;
use Illuminate\Console\Command;
use Throwable;

class SyncCustomerJourneyCommand extends Command
{
    protected $signature = 'chatbot:sync-customer-journey
                            {--type=all : all|booking|anniversary|at_risk}
                            {--dry-run}';

    protected $description = 'Sync customer journey: booking milestones reconcile, anniversary detection, at-risk detection';

    public function handle(CustomerJourneyService $service): int
    {
        $type = (string) $this->option('type');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Starting customer journey sync (type={$type}, dry-run=".($dryRun ? 'YES' : 'NO').')');

        $stats = [
            'booking_milestones_recorded' => 0,
            'anniversaries_recorded' => 0,
            'at_risk_recorded' => [],
        ];

        try {
            if ($type === 'all' || $type === 'booking') {
                $count = 0;
                Customer::query()
                    ->where(function ($query) {
                        $query->whereIn('total_bookings', CustomerJourneyService::BOOKING_MILESTONES)
                            ->orWhere('total_bookings', '>=', 100);
                    })
                    ->chunk(100, function ($customers) use ($service, &$count, $dryRun) {
                        foreach ($customers as $customer) {
                            if (! $dryRun) {
                                $newlyRecorded = $service->syncBookingMilestones($customer);
                                $count += count($newlyRecorded);
                            }
                        }
                    });
                $stats['booking_milestones_recorded'] = $count;
                $this->info("Booking milestones recorded: {$count}");
            }

            if ($type === 'all' || $type === 'anniversary') {
                if (! $dryRun) {
                    $stats['anniversaries_recorded'] = $service->detectAnniversaries();
                }
                $this->info("Anniversaries recorded: {$stats['anniversaries_recorded']}");
            }

            if ($type === 'all' || $type === 'at_risk') {
                if (! $dryRun) {
                    $stats['at_risk_recorded'] = $service->detectAtRiskCustomers();
                }
                $this->info('At-risk recorded: '.json_encode($stats['at_risk_recorded']));
            }

            WaLog::info('[SyncCustomerJourney] Completed', ['stats' => $stats, 'dry_run' => $dryRun]);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Exception: '.$e->getMessage());
            WaLog::error('[SyncCustomerJourney] Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}
