<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CRM\CrmSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryDecisionNoteToCrmJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 4;
    public array $backoff = [60, 300, 900, 1800];
    public int $timeout = 60;

    public function __construct(
        public readonly int $customerId,
        public readonly string $note,
    ) {}

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            Log::warning('[RetryDecisionNoteToCrmJob] Customer not found', [
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        $result = $crmSyncService->appendConversationDecisionNote($customer, $this->note);

        Log::info('[RetryDecisionNoteToCrmJob] Done', [
            'customer_id' => $this->customerId,
            'status' => $result['status'] ?? null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[RetryDecisionNoteToCrmJob] Permanently failed', [
            'customer_id' => $this->customerId,
            'error' => $exception->getMessage(),
        ]);
    }
}
