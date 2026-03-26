<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignEscalationRequest;
use App\Http\Requests\Admin\ResolveEscalationRequest;
use App\Models\Escalation;
use App\Services\Support\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class EscalationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    // -------------------------------------------------------------------------
    // Read actions
    // -------------------------------------------------------------------------

    public function index(Request $request): View
    {
        $query = Escalation::with('conversation.customer')
            ->latest();

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->get('priority')) {
            $query->where('priority', $priority);
        }

        $escalations = $query->paginate(25)->withQueryString();

        $statusOptions   = ['open', 'assigned', 'resolved', 'closed'];
        $priorityOptions = ['normal', 'high', 'urgent'];

        return view('admin.chatbot.escalations.index', compact('escalations', 'statusOptions', 'priorityOptions'));
    }

    // -------------------------------------------------------------------------
    // Escalation actions
    // -------------------------------------------------------------------------

    /**
     * Assign the escalation to the current admin.
     * Also syncs the related conversation to admin-takeover mode so the bot
     * stays suppressed while the admin handles it.
     */
    public function assign(
        AssignEscalationRequest $request,
        Escalation $escalation,
    ): RedirectResponse {
        $adminId = auth()->id();

        $escalation->markAssigned($adminId);

        // Sync conversation handoff if the escalation is linked to one
        if ($escalation->conversation !== null) {
            $escalation->conversation->takeoverBy($adminId);
        }

        $this->audit->record(AuditActionType::EscalationAssigned, [
            'auditable_type'  => Escalation::class,
            'auditable_id'    => $escalation->id,
            'conversation_id' => $escalation->conversation_id,
            'message'         => "Eskalasi #{$escalation->id} di-assign ke Admin (ID {$adminId}).",
            'context'         => [
                'escalation_id'   => $escalation->id,
                'admin_id'        => $adminId,
                'conversation_id' => $escalation->conversation_id,
                'priority'        => $escalation->priority,
            ],
        ]);

        Log::info('[Admin] Escalation assigned', [
            'escalation_id'   => $escalation->id,
            'admin_id'        => $adminId,
            'conversation_id' => $escalation->conversation_id,
        ]);

        return back()->with('success', 'Eskalasi berhasil di-assign ke Anda. Percakapan berada dalam mode admin takeover.');
    }

    /**
     * Mark the escalation as resolved.
     * Deliberately does NOT auto-release the bot — that is a separate action
     * on the conversation detail page. This gives the admin full control over timing.
     */
    public function resolve(
        ResolveEscalationRequest $request,
        Escalation $escalation,
    ): RedirectResponse {
        $adminId = auth()->id();

        $escalation->markResolved();

        $this->audit->record(AuditActionType::EscalationResolved, [
            'auditable_type'  => Escalation::class,
            'auditable_id'    => $escalation->id,
            'conversation_id' => $escalation->conversation_id,
            'message'         => "Eskalasi #{$escalation->id} diselesaikan oleh Admin (ID {$adminId}).",
            'context'         => [
                'escalation_id'   => $escalation->id,
                'admin_id'        => $adminId,
                'conversation_id' => $escalation->conversation_id,
            ],
        ]);

        Log::info('[Admin] Escalation resolved', [
            'escalation_id'   => $escalation->id,
            'admin_id'        => $adminId,
            'conversation_id' => $escalation->conversation_id,
        ]);

        return back()->with('success', 'Eskalasi diselesaikan. Gunakan tombol "Release ke Bot" pada percakapan jika ingin mengaktifkan bot kembali.');
    }
}
