<?php

use App\Http\Controllers\Api\AdminMobile\AuthController as AdminMobileAuthController;
use App\Http\Controllers\Api\AdminMobile\ConversationActionController as AdminMobileConversationActionController;
use App\Http\Controllers\Api\AdminMobile\ConversationController as AdminMobileConversationController;
use App\Http\Controllers\Api\AdminMobile\DashboardController as AdminMobileDashboardController;
use App\Http\Controllers\Api\AdminMobile\WorkspaceController as AdminMobileWorkspaceController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\LiveChatController;
use App\Http\Controllers\Api\Mobile\LiveChatMessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin-mobile')
    ->name('api.admin-mobile.')
    ->group(function (): void {
        Route::prefix('auth')
            ->name('auth.')
            ->group(function (): void {
                Route::post('login', [AdminMobileAuthController::class, 'login'])->name('login');

                Route::middleware('admin-mobile.auth')->group(function (): void {
                    Route::post('logout', [AdminMobileAuthController::class, 'logout'])->name('logout');
                    Route::get('me', [AdminMobileAuthController::class, 'me'])->name('me');
                });
            });

        Route::middleware('admin-mobile.auth')->group(function (): void {
            Route::get('workspace', [AdminMobileWorkspaceController::class, 'workspace'])->name('workspace');
            Route::get('conversations', [AdminMobileConversationController::class, 'index'])->name('conversations.index');
            Route::get('conversations/{conversation}', [AdminMobileConversationController::class, 'show'])->name('conversations.show');
            Route::get('conversations/{conversation}/messages', [AdminMobileConversationController::class, 'messages'])->name('conversations.messages.index');
            Route::get('conversations/{conversation}/poll', [AdminMobileConversationController::class, 'poll'])->name('conversations.poll');
            Route::post('conversations/{conversation}/messages', [AdminMobileConversationActionController::class, 'storeMessage'])->name('conversations.messages.store');
            Route::post('conversations/{conversation}/mark-read', [AdminMobileConversationActionController::class, 'markRead'])->name('conversations.mark-read');
            Route::post('conversations/{conversation}/takeover', [AdminMobileConversationActionController::class, 'takeover'])->name('conversations.takeover');
            Route::post('conversations/{conversation}/release', [AdminMobileConversationActionController::class, 'release'])->name('conversations.release');
            Route::post('conversations/{conversation}/tags', [AdminMobileConversationActionController::class, 'storeTag'])->name('conversations.tags.store');
            Route::post('conversations/{conversation}/notes', [AdminMobileConversationActionController::class, 'storeNote'])->name('conversations.notes.store');
            Route::post('conversations/{conversation}/status/escalate', [AdminMobileConversationActionController::class, 'escalate'])->name('conversations.status.escalate');
            Route::post('conversations/{conversation}/status/close', [AdminMobileConversationActionController::class, 'close'])->name('conversations.status.close');
            Route::post('conversations/{conversation}/status/reopen', [AdminMobileConversationActionController::class, 'reopen'])->name('conversations.status.reopen');
            Route::get('poll/list', [AdminMobileConversationController::class, 'pollList'])->name('poll.list');
            Route::get('dashboard/summary', [AdminMobileDashboardController::class, 'summary'])->name('dashboard.summary');
            Route::get('meta/filters', [AdminMobileWorkspaceController::class, 'filters'])->name('meta.filters');
        });
    });

Route::prefix('mobile')
    ->name('api.mobile.')
    ->group(function (): void {
        Route::prefix('auth')
            ->name('auth.')
            ->group(function (): void {
                Route::post('register', [AuthController::class, 'register'])->name('register');
                Route::post('login', [AuthController::class, 'login'])->name('login');

                Route::middleware('mobile.auth')->group(function (): void {
                    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
                    Route::get('me', [AuthController::class, 'me'])->name('me');
                });
            });

        Route::prefix('live-chat')
            ->middleware('mobile.auth')
            ->name('live-chat.')
            ->group(function (): void {
                Route::post('start', [LiveChatController::class, 'start'])->name('start');
                Route::get('conversations', [LiveChatController::class, 'index'])->name('conversations.index');
                Route::get('conversations/{conversation}', [LiveChatController::class, 'show'])->name('conversations.show');
                Route::get('conversations/{conversation}/messages', [LiveChatMessageController::class, 'index'])->name('conversations.messages.index');
                Route::post('conversations/{conversation}/messages', [LiveChatMessageController::class, 'store'])->name('conversations.messages.store');
                Route::get('conversations/{conversation}/poll', [LiveChatController::class, 'poll'])->name('conversations.poll');
                Route::post('conversations/{conversation}/mark-read', [LiveChatController::class, 'markRead'])->name('conversations.mark-read');
            });
    });
