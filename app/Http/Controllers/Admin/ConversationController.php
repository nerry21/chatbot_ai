<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReleaseConversationRequest;
use App\Http\Requests\Admin\ResendConversationMessageRequest;
use App\Http\Requests\Admin\SendConversationReplyRequest;
use App\Http\Requests\Admin\TakeoverConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Chatbot\AdminConversationMessageService;
use App\Services\Chatbot\ConversationTakeoverService;
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

    public function index(Request $request): View
    {
        $query = Conversation::with(['customer', 'assignedAdmin'])->latest('last_message_at');

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
            'assignedAdmin',
            'handoffAdmin',
            'messages' => fn ($q) => $q->orderBy('sent_at'),
            'states' => fn ($q) => $q->active(),
            'bookingRequests' => fn ($q) => $q->active()->latest(),
            'escalations' => fn ($q) => $q->latest(),
            'leadPipelines' => fn ($q) => $q->latest(),
            'handoffs' => fn ($q) => $q->latest('happened_at')->limit(10),
        ]);

        return view('admin.chatbot.conversations.show', compact('conversation'));
    }

    public function reply(
        SendConversationReplyRequest $request,
        Conversation $conversation,
        AdminConversationMessageService $messageService,
    ): RedirectResponse {
        $conversation->loadMissing('customer');

        if ($conversation->customer === null) {
            return back()->with('error', 'Customer tidak valid, tidak dapat mengirim pesan.');
        }

        $result = $messageService->send(
            conversation: $conversation,
            text: (string) $request->input('message'),
            adminId: (int) auth()->id(),
            source: 'conversation_detail',
        );

        if ($result['status'] === 'failed') {
            return back()->with('error', $result['error'] ?? 'Pesan gagal dikirim.');
        }

        return back()->with(
            'success',
            $result['duplicate']
                ? 'Pesan yang sama sudah diantrekan. Duplikat diabaikan.'
                : 'Pesan berhasil dikirim ke customer.'
        );
    }

    public function resendMessage(
        ResendConversationMessageRequest $request,
        Conversation $conversation,
        ConversationMessage $message,
    ): RedirectResponse {
        if ($message->conversation_id !== $conversation->id) {
            return back()->with('error', 'Pesan tidak ditemukan dalam percakapan ini.');
        }

        if (! $message->isResendable()) {
            $statusVal = $message->delivery_status?->value ?? 'unknown';

            return back()->with('error', "Pesan tidak dapat dikirim ulang (status: {$statusVal}).");
        }

        $maxAttempts = config('chatbot.reliability.max_send_attempts', 3);
        if ($message->send_attempts >= $maxAttempts) {
            return back()->with(
                'error',
                "Batas maksimal percobaan pengiriman ({$maxAttempts}x) sudah tercapai. Hubungi tim teknis jika perlu mengirim ulang lebih dari batas ini."
            );
        }

        $cooldownMinutes = config('chatbot.reliability.resend_cooldown_minutes', 5);
        $lastAttempt = $message->last_send_attempt_at;

        if ($lastAttempt !== null && $lastAttempt->gt(now()->subMinutes($cooldownMinutes))) {
            $nextAllowed = $lastAttempt->addMinutes($cooldownMinutes)->format('H:i:s');

            return back()->with(
                'error',
                "Harap tunggu hingga {$nextAllowed} sebelum mencoba kirim ulang lagi (cooldown: {$cooldownMinutes} menit)."
            );
        }

        $previousStatus = $message->delivery_status;
        $message->markPending();
        SendWhatsAppMessageJob::dispatch($message->id);

        $adminId = auth()->id();

        $this->audit->record(AuditActionType::MessageResendManual, [
            'auditable_type' => ConversationMessage::class,
            'auditable_id' => $message->id,
            'conversation_id' => $conversation->id,
            'message' => "Admin (ID {$adminId}) mengirim ulang pesan outbound #{$message->id} secara manual.",
            'context' => [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'admin_id' => $adminId,
                'previous_status' => $previousStatus?->value,
                'send_attempts' => $message->send_attempts,
            ],
        ]);

        Log::info('[Admin] Manual resend dispatched', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'admin_id' => $adminId,
            'send_attempts' => $message->send_attempts,
        ]);

        return back()->with('success', "Pesan #{$message->id} diantrekan untuk dikirim ulang.");
    }

    public function takeover(
        TakeoverConversationRequest $request,
        Conversation $conversation,
        ConversationTakeoverService $takeoverService,
    ): RedirectResponse {
        $adminId = auth()->id();

        $conversation = $takeoverService->takeOver(
            conversation: $conversation,
            adminId: $adminId,
            reason: 'manual_takeover',
        );

        Log::info('[Admin] Takeover - bot suppressed', [
            'conversation_id' => $conversation->id,
            'admin_id' => $adminId,
        ]);

        return back()->with('success', 'Anda mengambil alih percakapan. Bot sekarang dinonaktifkan.');
    }

    public function release(
        ReleaseConversationRequest $request,
        Conversation $conversation,
        ConversationTakeoverService $takeoverService,
    ): RedirectResponse {
        $adminId = auth()->id();

        $conversation = $takeoverService->releaseToBot(
            conversation: $conversation,
            adminId: $adminId,
            reason: 'manual_release',
        );

        Log::info('[Admin] Released to bot', [
            'conversation_id' => $conversation->id,
            'admin_id' => $adminId,
        ]);

        return back()->with('success', 'Percakapan dikembalikan ke bot. Bot aktif kembali.');
    }
}
