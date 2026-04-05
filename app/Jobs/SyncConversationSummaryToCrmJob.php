<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CRM\CrmSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncConversationSummaryToCrmJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public string $queue = 'crm';
    public int $tries = 4;
    public array $backoff = [30, 120, 300, 900];
    public int $timeout = 90;

    public function __construct(
        public readonly int $customerId,
        public readonly int $conversationId,
    ) {
        $this->onQueue('crm');
    }

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer = Customer::find($this->customerId);
        $conversation = Conversation::find($this->conversationId);

        if ($customer === null || $conversation === null) {
            Log::warning('[SyncConversationSummaryToCrmJob] Model not found', [
                'customer_id' => $this->customerId,
                'conversation_id' => $this->conversationId,
                'queue' => $this->queue,
            ]);

            return;
        }

        $result = $crmSyncService->syncConversationSummary($customer, $conversation);

        Log::info('[SyncConversationSummaryToCrmJob] Result', [
            'customer_id' => $this->customerId,
            'conversation_id' => $this->conversationId,
            'queue' => $this->queue,
            'status' => $result['status'] ?? null,
            'reason' => $result['reason'] ?? null,
            'reason_code' => $result['reason_code'] ?? null,
            'retryable' => $result['retryable'] ?? null,
            'http_status' => $result['http_status'] ?? null,
        ]);

        if (($result['retryable'] ?? false) === true && ! in_array($result['status'] ?? null, ['success', 'skipped'], true)) {
            throw new \RuntimeException((string) ($result['error'] ?? $result['reason'] ?? 'CRM summary sync retry requested'));
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncConversationSummaryToCrmJob] Permanently failed', [
            'customer_id' => $this->customerId,
            'conversation_id' => $this->conversationId,
            'queue' => $this->queue,
            'error' => $exception->getMessage(),
        ]);
    }
}
