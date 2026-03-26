<?php

namespace App\Jobs;

use App\Models\AdminNotification;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Services\Chatbot\ConversationManagerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EscalateConversationToAdminJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [15, 60, 180];
    public int   $timeout = 60;

    public function __construct(
        public readonly int    $conversationId,
        public readonly string $reason   = '',
        public readonly string $priority = 'normal',
    ) {}

    public function handle(ConversationManagerService $conversationManager): void
    {
        $conversation = Conversation::with('customer')->find($this->conversationId);

        if ($conversation === null) {
            Log::warning('[EscalateJob] Conversation not found', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // ── Guard: do not create a duplicate open escalation ───────────────
        $existing = Escalation::where('conversation_id', $this->conversationId)
            ->where('status', 'open')
            ->first();

        if ($existing !== null) {
            Log::info('[EscalateJob] Skipped — open escalation already exists', [
                'conversation_id' => $this->conversationId,
                'escalation_id'   => $existing->id,
            ]);

            return;
        }

        // ── 1. Create escalation record ────────────────────────────────────
        $escalation = Escalation::create([
            'conversation_id' => $this->conversationId,
            'reason'          => $this->reason ?: 'Permintaan handoff atau eskalasi otomatis',
            'priority'        => $this->priority,
            'status'          => 'open',
            'summary'         => $conversation->summary,
        ]);

        // ── 2. Mark conversation as needing human attention ────────────────
        $conversationManager->escalate($conversation, $this->reason);

        // ── 3. Create admin notification ───────────────────────────────────
        $customer     = $conversation->customer;
        $customerName = $customer?->name ?? $customer?->phone_e164 ?? 'Pelanggan';

        AdminNotification::create([
            'type'    => 'escalation',
            'title'   => "Eskalasi: {$customerName}",
            'body'    => $this->buildNotificationBody($conversation, $escalation),
            'payload' => [
                'escalation_id'   => $escalation->id,
                'conversation_id' => $this->conversationId,
                'customer_id'     => $customer?->id,
                'reason'          => $this->reason,
                'priority'        => $this->priority,
            ],
            'is_read' => false,
        ]);

        Log::info('[EscalateJob] Escalation created', [
            'escalation_id'   => $escalation->id,
            'conversation_id' => $this->conversationId,
            'priority'        => $this->priority,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[EscalateJob] Permanently failed', [
            'conversation_id' => $this->conversationId,
            'error'           => $exception->getMessage(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildNotificationBody(Conversation $conversation, Escalation $escalation): string
    {
        $customer = $conversation->customer;

        $lines = [
            'Percakapan membutuhkan perhatian admin.',
            'Pelanggan : ' . ($customer?->name ?? 'Tidak diketahui'),
            'Nomor     : ' . ($customer?->phone_e164 ?? '-'),
            'Alasan    : ' . $escalation->reason,
            'Prioritas : ' . $escalation->priority,
        ];

        if (! empty($conversation->summary)) {
            $lines[] = '';
            $lines[] = 'Ringkasan percakapan:';
            $lines[] = $conversation->summary;
        }

        return implode("\n", $lines);
    }
}
