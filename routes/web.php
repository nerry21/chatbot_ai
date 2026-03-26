<?php

use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AiLogController;
use App\Http\Controllers\Admin\BookingLeadController;
use App\Http\Controllers\Admin\ChatbotDashboardController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\EscalationController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin Chatbot Dashboard
// Middleware: auth (current project does not have a dedicated admin role guard).
// To restrict to admins only, replace 'auth' with a role middleware once the
// admin user model is finalised (e.g. 'auth', 'role:admin').
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'chatbot.admin'])
    ->prefix('admin/chatbot')
    ->name('admin.chatbot.')
    ->group(function (): void {

        // Dashboard
        Route::get('/', [ChatbotDashboardController::class, 'index'])
            ->name('dashboard');

        // Conversations
        Route::get('/conversations', [AdminConversationController::class, 'index'])
            ->name('conversations.index');
        Route::get('/conversations/{conversation}', [AdminConversationController::class, 'show'])
            ->name('conversations.show');
        Route::post('/conversations/{conversation}/reply', [AdminConversationController::class, 'reply'])
            ->name('conversations.reply');
        Route::post('/conversations/{conversation}/takeover', [AdminConversationController::class, 'takeover'])
            ->name('conversations.takeover');
        Route::post('/conversations/{conversation}/release', [AdminConversationController::class, 'release'])
            ->name('conversations.release');
        // Tahap 9: manual resend of a failed/skipped outbound message
        Route::post('/conversations/{conversation}/messages/{message}/resend', [AdminConversationController::class, 'resendMessage'])
            ->name('conversations.messages.resend');

        // Customers
        Route::get('/customers', [AdminCustomerController::class, 'index'])
            ->name('customers.index');
        Route::get('/customers/{customer}', [AdminCustomerController::class, 'show'])
            ->name('customers.show');

        // Booking Leads
        Route::get('/bookings', [BookingLeadController::class, 'index'])
            ->name('bookings.index');

        // Escalations
        Route::get('/escalations', [EscalationController::class, 'index'])
            ->name('escalations.index');
        Route::post('/escalations/{escalation}/assign', [EscalationController::class, 'assign'])
            ->name('escalations.assign');
        Route::post('/escalations/{escalation}/resolve', [EscalationController::class, 'resolve'])
            ->name('escalations.resolve');

        // Admin Notifications
        Route::get('/notifications', [AdminNotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('/notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])
            ->name('notifications.mark-read');
        Route::post('/notifications/read-all', [AdminNotificationController::class, 'markAllRead'])
            ->name('notifications.read-all');

        // AI Logs
        Route::get('/ai-logs', [AiLogController::class, 'index'])
            ->name('ai-logs.index');

        // Knowledge Base
        Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])
            ->name('knowledge.index');
        Route::get('/knowledge/create', [KnowledgeBaseController::class, 'create'])
            ->name('knowledge.create');
        Route::post('/knowledge', [KnowledgeBaseController::class, 'store'])
            ->name('knowledge.store');
        Route::get('/knowledge/{knowledgeArticle}/edit', [KnowledgeBaseController::class, 'edit'])
            ->name('knowledge.edit');
        Route::patch('/knowledge/{knowledgeArticle}', [KnowledgeBaseController::class, 'update'])
            ->name('knowledge.update');
    });

require __DIR__.'/auth.php';
