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

    public int $tries = 4;
    public array $backoff = [30, 120, 300, 900];
    public int $timeout = 90;

    public function __construct(
        public readonly int $customerId,
    ) {
        $this->onQueue('crm');
    }

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            Log::warning('[SyncContactToCrmJob] Customer not found', [
                'customer_id' => $this->customerId,
                'queue' => $this->queue,
            ]);

            return;
        }

        $result = $crmSyncService->syncCustomer($customer);

        Log::info('[SyncContactToCrmJob] Result', [
            'customer_id' => $this->customerId,
            'queue' => $this->queue,
            'status' => $result['status'] ?? null,
            'reason' => $result['reason'] ?? null,
            'reason_code' => $result['reason_code'] ?? null,
            'retryable' => $result['retryable'] ?? null,
            'external_contact_id' => $result['external_contact_id'] ?? null,
        ]);

        if (($result['retryable'] ?? false) === true && ! in_array($result['status'] ?? null, ['success', 'skipped'], true)) {
            throw new \RuntimeException((string) ($result['error'] ?? $result['reason'] ?? 'CRM contact sync retry requested'));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncContactToCrmJob] Permanently failed', [
            'customer_id' => $this->customerId,
            'queue' => $this->queue,
            'error' => $exception->getMessage(),
        ]);
    }
}
