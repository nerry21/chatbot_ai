<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Services\AI\AiQualityService;
use App\Services\Chatbot\ConversationInsightService;
use App\Services\Support\ChatbotHealthService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\View\View;

class ChatbotDashboardController extends Controller
{
    public function __construct(
        private readonly ChatbotHealthService $healthService,
        private readonly AiQualityService $aiQualityService,
        private readonly ConversationInsightService $conversationInsightService,
    ) {}

    public function index(): View
    {
        $stats = $this->buildCoreStats();
        $health = $this->buildHealthSnapshot();
        $aiQuality = $this->buildAiQualityStats();

        return view('admin.chatbot.dashboard', [
            'stats' => $stats,
            'health' => $health,
            'aiQuality' => $aiQuality,
            'workspaceInsights' => $this->conversationInsightService->dashboard(),
            'recentConversations' => $this->recentConversations(),
            'recentEscalations' => $this->recentEscalations(),
            'recentBookings' => $this->recentBookings(),
            'recentAiIncidents' => $this->recentAiIncidents(),
            'recentFailedMessages' => $this->recentFailedMessages(),
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function buildHealthSnapshot(): array
    {
        try {
            return $this->healthService->run();
        } catch (\Throwable) {
            return [
                'status' => 'warning',
                'checks' => [],
                'summary' => [
                    'failed_messages_24h' => 0,
                    'pending_messages_stale' => 0,
                    'open_escalations' => 0,
                    'unread_notifications' => 0,
                    'active_takeovers' => 0,
                    'queue_backlog' => null,
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAiQualityStats(): ?array
    {
        if (! config('chatbot.ai_quality.enabled', true)) {
            return null;
        }

        try {
            $overview = $this->aiQualityService->buildOverview();

            return array_merge($overview, [
                'status' => $this->aiQualityService->qualityStatus($overview['quality_rate'] ?? 0),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return EloquentCollection<int, Conversation>
     */
    private function recentConversations(): EloquentCollection
    {
        try {
            return Conversation::with(['customer'])
                ->where('status', 'active')
                ->latest('last_message_at')
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection();
        }
    }

    /**
     * @return EloquentCollection<int, Escalation>
     */
    private function recentEscalations(): EloquentCollection
    {
        try {
            return Escalation::with(['conversation.customer'])
                ->latest()
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection();
        }
    }

    /**
     * @return EloquentCollection<int, BookingRequest>
     */
    private function recentBookings(): EloquentCollection
    {
        try {
            return BookingRequest::with(['customer', 'conversation'])
                ->latest('updated_at')
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection();
        }
    }

    /**
     * @return EloquentCollection<int, AiLog>
     */
    private function recentAiIncidents(): EloquentCollection
    {
        try {
            return AiLog::with(['conversation.customer'])
                ->where(function ($query): void {
                    $query->where('status', 'failed')
                        ->orWhereIn('quality_label', ['low_confidence', 'fallback']);
                })
                ->latest()
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection();
        }
    }

    /**
     * @return EloquentCollection<int, ConversationMessage>
     */
    private function recentFailedMessages(): EloquentCollection
    {
        try {
            return ConversationMessage::with(['conversation.customer'])
                ->where('direction', 'outbound')
                ->whereIn('delivery_status', ['failed', 'skipped'])
                ->latest()
                ->limit(6)
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection();
        }
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
