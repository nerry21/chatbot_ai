<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarkAllNotificationsReadRequest;
use App\Http\Requests\Admin\MarkNotificationReadRequest;
use App\Models\AdminNotification;
use App\Services\Support\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminNotificationController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): View
    {
        $query = AdminNotification::latest();

        if ($request->get('filter') === 'unread') {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate(30)->withQueryString();

        $unreadCount = AdminNotification::where('is_read', false)->count();

        return view('admin.chatbot.notifications.index', compact('notifications', 'unreadCount'));
    }

    public function markRead(
        MarkNotificationReadRequest $request,
        AdminNotification $notification,
    ): RedirectResponse {
        $wasUnread = $notification->isUnread();

        $notification->markRead();

        if ($wasUnread) {
            $this->audit->record(AuditActionType::NotificationMarkRead, [
                'auditable_type' => AdminNotification::class,
                'auditable_id'   => $notification->id,
                'message'        => "Notifikasi #{$notification->id} ditandai dibaca.",
                'context'        => [
                    'notification_id'   => $notification->id,
                    'notification_type' => $notification->type,
                ],
            ]);
        }

        return back()->with('success', 'Notifikasi ditandai sudah dibaca.');
    }

    public function markAllRead(MarkAllNotificationsReadRequest $request): RedirectResponse
    {
        $count = AdminNotification::where('is_read', false)->count();

        AdminNotification::where('is_read', false)->update(['is_read' => true]);

        if ($count > 0) {
            $this->audit->record(AuditActionType::NotificationMarkAllRead, [
                'message' => "Semua notifikasi ({$count}) ditandai dibaca.",
                'context' => ['count' => $count],
            ]);
        }

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
