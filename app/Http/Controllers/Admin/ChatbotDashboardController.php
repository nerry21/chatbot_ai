<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\AiLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Services\AI\AiQualityService;
use App\Services\Support\ChatbotHealthService;
use Illuminate\View\View;

class ChatbotDashboardController extends Controller
{
    public function __construct(
        private readonly ChatbotHealthService $healthService,
        private readonly AiQualityService     $aiQualityService,
    ) {}

    public function index(): View
    {
        // ── Core stats (Tahap 1–8) ────────────────────────────────────────────
        $stats = [
            'total_conversations'     => Conversation::count(),
            'active_conversations'    => Conversation::where('status', 'active')->count(),
            'needs_human'             => Conversation::where('needs_human', true)->count(),
            'total_customers'         => Customer::count(),
            'total_bookings'          => BookingRequest::count(),
            'awaiting_confirmation'   => BookingRequest::where('booking_status', 'awaiting_confirmation')->count(),
            'confirmed_bookings'      => BookingRequest::where('booking_status', 'confirmed')->count(),
            'open_escalations'        => Escalation::where('status', 'open')->count(),
            'unread_notifications'    => AdminNotification::where('is_read', false)->count(),
            'ai_logs_today'           => AiLog::whereDate('created_at', today())->count(),
        ];

        // ── Reliability stats (Tahap 9) ───────────────────────────────────────
        $reliability = $this->buildReliabilityStats();

        // ── AI quality overview (Tahap 10) ────────────────────────────────────
        $aiQuality = $this->buildAiQualityStats();

        $latestConversations = Conversation::with('customer')
            ->latest('last_message_at')
            ->limit(5)
            ->get();

        $latestEscalations = Escalation::with('conversation.customer')
            ->where('status', 'open')
            ->latest()
            ->limit(5)
            ->get();

        return view('admin.chatbot.dashboard', compact(
            'stats',
            'reliability',
            'aiQuality',
            'latestConversations',
            'latestEscalations',
        ));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build reliability summary from direct queries.
     * We do NOT call ChatbotHealthService::run() here to avoid duplication
     * of heavy queries on every dashboard load; instead we pull the specific
     * metrics the UI needs.
     *
     * @return array<string, mixed>
     */
    private function buildReliabilityStats(): array
    {
        try {
            $failedMessages24h = ConversationMessage::where('direction', 'outbound')
                ->where('delivery_status', 'failed')
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            $pendingStale = ConversationMessage::where('direction', 'outbound')
                ->where('delivery_status', 'pending')
                ->where('created_at', '<', now()->subMinutes(10))
                ->count();

            $sentMessages24h = ConversationMessage::where('direction', 'outbound')
                ->whereIn('delivery_status', ['sent', 'delivered'])
                ->where('created_at', '>=', now()->subHours(24))
                ->count();

            $activeTakeovers = Conversation::where('handoff_mode', 'admin')->count();

        } catch (\Throwable) {
            $failedMessages24h = 0;
            $pendingStale      = 0;
            $sentMessages24h   = 0;
            $activeTakeovers   = 0;
        }

        // Derive a simple traffic-light status for the reliability card
        $reliabilityStatus = 'ok';
        if ($failedMessages24h >= config('chatbot.reliability.health.failed_message_threshold', 10)
            || $pendingStale >= 5) {
            $reliabilityStatus = 'critical';
        } elseif ($failedMessages24h > 0 || $pendingStale > 0) {
            $reliabilityStatus = 'warning';
        }

        return [
            'status'               => $reliabilityStatus,
            'failed_messages_24h'  => $failedMessages24h,
            'pending_stale'        => $pendingStale,
            'sent_messages_24h'    => $sentMessages24h,
            'active_takeovers'     => $activeTakeovers,
            // These are already in $stats but exposed here for the reliability card context
            'open_escalations'     => Escalation::where('status', 'open')->count(),
            'unread_notifications' => AdminNotification::where('is_read', false)->count(),
        ];
    }

    /**
     * Build a lightweight AI quality summary for the dashboard.
     * Gracefully returns null if quality tracking is disabled or queries fail.
     *
     * @return array<string, mixed>|null
     */
    private function buildAiQualityStats(): ?array
    {
        if (! config('chatbot.ai_quality.enabled', true)) {
            return null;
        }

        try {
            $overview = $this->aiQualityService->buildOverview();
            $status   = $this->aiQualityService->qualityStatus($overview['quality_rate']);

            return array_merge($overview, ['status' => $status]);
        } catch (\Throwable) {
            return null;
        }
    }
}
