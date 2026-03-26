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

    public int   $tries   = 3;
    public array $backoff = [30, 120, 300];
    public int   $timeout = 60;

    public function __construct(
        public readonly int $customerId,
        public readonly int $conversationId,
    ) {}

    public function handle(CrmSyncService $crmSyncService): void
    {
        $customer     = Customer::find($this->customerId);
        $conversation = Conversation::find($this->conversationId);

        if ($customer === null || $conversation === null) {
            Log::warning('[SyncConversationSummaryToCrmJob] Model not found', [
                'customer_id'     => $this->customerId,
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $result = $crmSyncService->syncConversationSummary($customer, $conversation);

        Log::info('[SyncConversationSummaryToCrmJob] Done', [
            'customer_id'     => $this->customerId,
            'conversation_id' => $this->conversationId,
            'status'          => $result['status'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncConversationSummaryToCrmJob] Permanently failed', [
            'customer_id'     => $this->customerId,
            'conversation_id' => $this->conversationId,
            'error'           => $exception->getMessage(),
        ]);
    }
}
