<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditActionType;
use App\Http\Controllers\Controller;
use App\Enums\MessageDeliveryStatus;
use App\Http\Requests\Admin\ReleaseConversationRequest;
use App\Http\Requests\Admin\ResendConversationMessageRequest;
use App\Http\Requests\Admin\SendConversationReplyRequest;
use App\Http\Requests\Admin\TakeoverConversationRequest;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\AdminNotification;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\Learning\AdminCorrectionLoggerService;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Support\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    // -------------------------------------------------------------------------
    // Read actions
    // -------------------------------------------------------------------------

    public function index(Request $request): View
    {
        $query = Conversation::with('customer')->latest('last_message_at');

        if ($search = $request->get('search')) {
            $query->whereHas('customer', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('needs_human')) {
            $query->where('needs_human', true);
        }

        $conversations = $query->paginate(20)->withQueryString();

        $statusOptions = ['active', 'closed', 'escalated', 'archived'];

        return view('admin.chatbot.conversations.index', compact('conversations', 'statusOptions'));
    }

    public function show(Conversation $conversation): View
    {
        $conversation->load([
            'customer.tags',
            'customer.crmContact',
            'messages'        => fn ($q) => $q->orderBy('sent_at'),
            'states'          => fn ($q) => $q->active(),
            'bookingRequests' => fn ($q) => $q->active()->latest(),
            'escalations'     => fn ($q) => $q->latest(),
            'leadPipelines'   => fn ($q) => $q->latest(),
        ]);

        return view('admin.chatbot.conversations.show', compact('conversation'));
    }

    // -------------------------------------------------------------------------
    // Admin reply
    // -------------------------------------------------------------------------

    /**
     * Admin sends a manual message into the conversation.
     * Works regardless of handoff_mode — admin can always reply.
     */
    public function reply(
        SendConversationReplyRequest $request,
        Conversation $conversation,
        ConversationManagerService $manager,
        AdminCorrectionLoggerService $adminCorrectionLogger,
    ): RedirectResponse {
        $conversation->loadMissing('customer');

        if ($conversation->customer === null) {
            return back()->with('error', 'Customer tidak valid, tidak dapat mengirim pesan.');
        }

        $message = $manager->appendAdminOutboundMessage(
            conversation: $conversation,
            text:         $request->input('message'),
            adminId:      auth()->id(),
        );

        $adminCorrectionLogger->captureForAdminReply(
            conversation: $conversation,
            adminMessage: $message,
            adminId: auth()->id(),
        );

        SendWhatsAppMessageJob::dispatch($message->id);

        $this->audit->record(AuditActionType::AdminReplySent, [
            'conversation_id' => $conversation->id,
            'auditable_type'  => Conversation::class,
            'auditable_id'    => $conversation->id,
            'message'         => 'Admin mengirim balasan manual ke customer.',
            'context'         => [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'admin_id'        => auth()->id(),
                'text_preview'    => mb_substr($request->input('message'), 0, 80),
            ],
        ]);

        Log::info('[Admin] Reply sent', [
            'conversation_id' => $conversation->id,
            'admin_id'        => auth()->id(),
            'message_id'      => $message->id,
        ]);

        return back()->with('success', 'Pesan berhasil dikirim ke customer.');
    }

    // -------------------------------------------------------------------------
    // Resend failed outbound message (Tahap 9)
    // -------------------------------------------------------------------------

    /**
     * Admin manually resends an outbound message that failed or was skipped
     * for a recoverable reason.
     *
     * Validation rules:
     *  - Message must belong to the given conversation (route model binding enforces this
     *    via the scoped binding below — controller also guards explicitly).
     *  - Message must be outbound (bot or agent).
     *  - Message must not already be successfully sent.
     *  - Message must be resendable (failed, or skipped for recoverable reasons).
     *  - send_attempts must be below max_send_attempts.
     *  - Cooldown between manual resend attempts must be respected.
     */
    public function resendMessage(
        ResendConversationMessageRequest $request,
        Conversation $conversation,
        ConversationMessage $message,
    ): RedirectResponse {
        // Guard: message must belong to this conversation
        if ($message->conversation_id !== $conversation->id) {
            return back()->with('error', 'Pesan tidak ditemukan dalam percakapan ini.');
        }

        // Guard: must be resendable
        if (! $message->isResendable()) {
            $statusVal = $message->delivery_status?->value ?? 'unknown';
            return back()->with('error', "Pesan tidak dapat dikirim ulang (status: {$statusVal}).");
        }

        // Guard: max send attempts
        $maxAttempts = config('chatbot.reliability.max_send_attempts', 3);
        if ($message->send_attempts >= $maxAttempts) {
            return back()->with('error',
                "Batas maksimal percobaan pengiriman ({$maxAttempts}x) sudah tercapai. " .
                'Hubungi tim teknis jika perlu mengirim ulang lebih dari batas ini.'
            );
        }

        // Guard: cooldown between resend attempts
        $cooldownMinutes  = config('chatbot.reliability.resend_cooldown_minutes', 5);
        $lastAttempt      = $message->last_send_attempt_at;

        if ($lastAttempt !== null && $lastAttempt->gt(now()->subMinutes($cooldownMinutes))) {
            $nextAllowed = $lastAttempt->addMinutes($cooldownMinutes)->format('H:i:s');
            return back()->with('error',
                "Harap tunggu hingga {$nextAllowed} sebelum mencoba kirim ulang lagi " .
                "(cooldown: {$cooldownMinutes} menit)."
            );
        }

        // Reset delivery status to pending so the job can process it
        $message->markPending();

        // Dispatch the send job
        SendWhatsAppMessageJob::dispatch($message->id);

        $adminId = auth()->id();

        $this->audit->record(AuditActionType::MessageResendManual, [
            'auditable_type'  => ConversationMessage::class,
            'auditable_id'    => $message->id,
            'conversation_id' => $conversation->id,
            'message'         => "Admin (ID {$adminId}) mengirim ulang pesan outbound #{$message->id} secara manual.",
            'context'         => [
                'message_id'       => $message->id,
                'conversation_id'  => $conversation->id,
                'admin_id'         => $adminId,
                'previous_status'  => $message->delivery_status?->value,
                'send_attempts'    => $message->send_attempts,
            ],
        ]);

        Log::info('[Admin] Manual resend dispatched', [
            'message_id'      => $message->id,
            'conversation_id' => $conversation->id,
            'admin_id'        => $adminId,
            'send_attempts'   => $message->send_attempts,
        ]);

        return back()->with('success', "Pesan #$message->id diantrekan untuk dikirim ulang.");
    }

    // -------------------------------------------------------------------------
    // Handoff management
    // -------------------------------------------------------------------------

    /**
     * Admin explicitly takes over the conversation.
     * After takeover, the bot pipeline will not generate auto-replies.
     */
    public function takeover(
        TakeoverConversationRequest $request,
        Conversation $conversation,
    ): RedirectResponse {
        $adminId = auth()->id();

        $conversation->takeoverBy($adminId);

        $this->audit->record(AuditActionType::ConversationTakeover, [
            'conversation_id' => $conversation->id,
            'auditable_type'  => Conversation::class,
            'auditable_id'    => $conversation->id,
            'message'         => "Admin (ID {$adminId}) mengambil alih percakapan. Bot dinonaktifkan.",
            'context'         => [
                'conversation_id' => $conversation->id,
                'admin_id'        => $adminId,
            ],
        ]);

        // AdminNotification (audit trail + alert) — gated by config
        if (config('chatbot.notifications.enabled', true) && config('chatbot.notifications.create_on_takeover', true)) {
            AdminNotification::create([
                'type'    => 'takeover',
                'title'   => 'Admin Takeover: Percakapan #' . $conversation->id,
                'body'    => "Admin (ID {$adminId}) mengambil alih percakapan. Bot dinonaktifkan.",
                'payload' => [
                    'conversation_id' => $conversation->id,
                    'admin_id'        => $adminId,
                ],
                'is_read' => false,
            ]);
        }

        Log::info('[Admin] Takeover — bot suppressed', [
            'conversation_id' => $conversation->id,
            'admin_id'        => $adminId,
        ]);

        return back()->with('success', 'Anda mengambil alih percakapan. Bot sekarang dinonaktifkan.');
    }

    /**
     * Admin releases the conversation back to the bot pipeline.
     * Does NOT close the conversation.
     */
    public function release(
        ReleaseConversationRequest $request,
        Conversation $conversation,
    ): RedirectResponse {
        $adminId = auth()->id();

        $conversation->releaseToBot();

        $this->audit->record(AuditActionType::ConversationRelease, [
            'conversation_id' => $conversation->id,
            'auditable_type'  => Conversation::class,
            'auditable_id'    => $conversation->id,
            'message'         => "Admin (ID {$adminId}) melepas percakapan kembali ke bot.",
            'context'         => [
                'conversation_id' => $conversation->id,
                'admin_id'        => $adminId,
            ],
        ]);

        // Notification on release — off by default, configurable
        if (config('chatbot.notifications.enabled', true) && config('chatbot.notifications.create_on_release', false)) {
            AdminNotification::create([
                'type'    => 'release',
                'title'   => 'Bot Diaktifkan Kembali: Percakapan #' . $conversation->id,
                'body'    => "Admin (ID {$adminId}) melepas percakapan. Bot aktif kembali.",
                'payload' => [
                    'conversation_id' => $conversation->id,
                    'admin_id'        => $adminId,
                ],
                'is_read' => false,
            ]);
        }

        Log::info('[Admin] Released to bot', [
            'conversation_id' => $conversation->id,
            'admin_id'        => $adminId,
        ]);

        return back()->with('success', 'Percakapan dikembalikan ke bot. Bot aktif kembali.');
    }
}
