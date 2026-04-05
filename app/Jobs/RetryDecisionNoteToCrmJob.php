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

    public string $queue = 'crm';
    public int $tries = 5;
    public array $backoff = [60, 300, 900, 1800, 3600];
    public int $timeout = 90;

    public function __construct(
        public readonly int $customerId,
        public readonly string $note,
    ) {
        $this->onQueue('crm');
    }

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            Log::warning('[RetryDecisionNoteToCrmJob] Customer not found', [
                'customer_id' => $this->customerId,
                'queue' => $this->queue,
            ]);

            return;
        }

        $result = $crmSyncService->appendConversationDecisionNote($customer, $this->note);

        Log::info('[RetryDecisionNoteToCrmJob] Result', [
            'customer_id' => $this->customerId,
            'queue' => $this->queue,
            'status' => $result['status'] ?? null,
            'reason' => $result['reason'] ?? null,
            'reason_code' => $result['reason_code'] ?? null,
            'retryable' => $result['retryable'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'note_id' => $result['note_id'] ?? null,
        ]);

        if (($result['retryable'] ?? false) === true && ! in_array($result['status'] ?? null, ['success', 'skipped'], true)) {
            throw new \RuntimeException((string) ($result['error'] ?? $result['reason'] ?? 'CRM decision note retry requested'));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[RetryDecisionNoteToCrmJob] Permanently failed', [
            'customer_id' => $this->customerId,
            'queue' => $this->queue,
            'error' => $exception->getMessage(),
        ]);
    }
}
