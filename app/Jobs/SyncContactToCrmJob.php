<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CRM\CrmSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncContactToCrmJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [30, 120, 300];
    public int   $timeout = 60;

    public function __construct(
        public readonly int $customerId,
    ) {}

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            Log::warning('[SyncContactToCrmJob] Customer not found', [
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        $result = $crmSyncService->syncCustomer($customer);

        Log::info('[SyncContactToCrmJob] Done', [
            'customer_id' => $this->customerId,
            'status'      => $result['status'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncContactToCrmJob] Permanently failed', [
            'customer_id' => $this->customerId,
            'error'       => $exception->getMessage(),
        ]);
    }
}
