<?php

namespace App\Jobs;

use App\Services\CRM\CrmSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactToCrmJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 30;

    public function __construct(
        public readonly int $customerId,
        public readonly array $context = [],
    ) {
        $this->onQueue('crm');
    }

    public function handle(CrmSyncService $crmSyncService): void
    {
        Log::info('[SyncContactToCrmJob] Started', [
            'customer_id' => $this->customerId,
            'context' => $this->context,
        ]);

        $crmSyncService->syncCustomerToCrm(
            customerId: $this->customerId,
            context: $this->context,
        );

        Log::info('[SyncContactToCrmJob] Finished', [
            'customer_id' => $this->customerId,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('[SyncContactToCrmJob] Failed', [
            'customer_id' => $this->customerId,
            'context' => $this->context,
            'error' => $e->getMessage(),
        ]);
    }
}