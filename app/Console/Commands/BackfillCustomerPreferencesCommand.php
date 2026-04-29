<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CRM\CustomerPreferenceUpdaterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillCustomerPreferencesCommand extends Command
{
    protected $signature = 'chatbot:backfill-customer-preferences
                            {--limit= : Maximum number of customers to process}';

    protected $description = 'Recompute preferences and counters for all customers from booking history';

    public function handle(CustomerPreferenceUpdaterService $updater): int
    {
        $limit = $this->option('limit');
        $limit = $limit !== null ? max(1, (int) $limit) : null;

        $query = Customer::query()
            ->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('booking_requests')
                    ->whereColumn('booking_requests.customer_id', 'customers.id');
            })
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $customers = $query->get();
        $total = $customers->count();

        if ($total === 0) {
            $this->info('No customers with booking history. Nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Backfilling preferences for {$total} customer(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            try {
                $updater->recomputePreferences($customer);
                $success++;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Customer #{$customer->id} failed: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Success: {$success}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
