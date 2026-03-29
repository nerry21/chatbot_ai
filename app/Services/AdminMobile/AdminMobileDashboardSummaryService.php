<?php

namespace App\Services\AdminMobile;

use App\Models\AiLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Services\Chatbot\ConversationInsightService;

class AdminMobileDashboardSummaryService
{
    public function __construct(
        private readonly ConversationInsightService $conversationInsightService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'core_stats' => $this->buildCoreStats(),
            'workspace_insights' => $this->conversationInsightService->dashboard(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildCoreStats(): array
    {
        return [
            'total_customers' => $this->safeCount(fn (): int => Customer::count()),
            'total_conversations' => $this->safeCount(fn (): int => Conversation::count()),
            'active_conversations' => $this->safeCount(fn (): int => Conversation::where('status', 'active')->count()),
            'human_takeover_active' => $this->safeCount(fn (): int => Conversation::humanTakeoverActive()->count()),
            'open_escalations' => $this->safeCount(fn (): int => Escalation::where('status', 'open')->count()),
            'pending_handoffs' => $this->safeCount(function (): int {
                return Conversation::where(function ($query): void {
                        $query->where('needs_human', true)
                            ->orWhere('bot_paused', true)
                            ->orWhere('status', 'escalated');
                    })
                    ->where(function ($query): void {
                        $query->whereNull('assigned_admin_id')
                            ->orWhere('handoff_mode', '!=', 'admin');
                    })
                    ->count();
            }),
            'total_bookings' => $this->safeCount(fn (): int => BookingRequest::count()),
            'failed_outbound_messages' => $this->safeCount(function (): int {
                return ConversationMessage::where('direction', 'outbound')
                    ->where('delivery_status', 'failed')
                    ->count();
            }),
            'ai_logs_today' => $this->safeCount(fn (): int => AiLog::whereDate('created_at', today())->count()),
        ];
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }
}
