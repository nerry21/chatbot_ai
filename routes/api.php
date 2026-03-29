<?php

use App\Http\Controllers\Api\AdminMobile\AuthController as AdminMobileAuthController;
use App\Http\Controllers\Api\AdminMobile\ContactController as AdminMobileContactController;
use App\Http\Controllers\Api\AdminMobile\ReplyController as AdminMobileReplyController;
use App\Http\Controllers\Api\AdminMobile\WorkspaceController as AdminMobileWorkspaceController;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\LiveChatController;
use App\Http\Controllers\Api\Mobile\LiveChatMessageController;
use Illuminate\Support\Facades\Route;

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

Route::prefix('admin-mobile')
    ->name('api.admin-mobile.')
    ->group(function (): void {
        Route::prefix('auth')
            ->name('auth.')
            ->group(function (): void {
                Route::post('login', [AdminMobileAuthController::class, 'login'])->name('login');

                Route::middleware('admin.mobile.auth')->group(function (): void {
                    Route::post('logout', [AdminMobileAuthController::class, 'logout'])->name('logout');
                    Route::get('me', [AdminMobileAuthController::class, 'me'])->name('me');
                });
            });

        Route::middleware('admin.mobile.auth')->group(function (): void {
            Route::get('workspace', [AdminMobileWorkspaceController::class, 'workspace'])->name('workspace');
            Route::get('conversations', [AdminMobileWorkspaceController::class, 'conversations'])->name('conversations.index');
            Route::get('conversations/{conversation}', [AdminMobileWorkspaceController::class, 'detail'])->name('conversations.show');
            Route::get('conversations/{conversation}/messages', [AdminMobileWorkspaceController::class, 'messages'])->name('conversations.messages.index');
            Route::get('conversations/{conversation}/poll', [AdminMobileWorkspaceController::class, 'pollConversation'])->name('conversations.poll');
            Route::post('conversations/{conversation}/reply', [AdminMobileReplyController::class, 'store'])->name('conversations.reply');
            Route::post('conversations/{conversation}/send-contact', [AdminMobileContactController::class, 'store'])->name('conversations.send-contact');
            Route::get('poll/list', [AdminMobileWorkspaceController::class, 'pollList'])->name('poll.list');
            Route::get('dashboard/summary', [AdminMobileWorkspaceController::class, 'dashboardSummary'])->name('dashboard.summary');
            Route::get('meta/filters', [AdminMobileWorkspaceController::class, 'metaFilters'])->name('meta.filters');
        });
    });
