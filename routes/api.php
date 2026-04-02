<?php

use App\Http\Controllers\Api\AdminMobile\AuthController as AdminMobileAuthController;
use App\Http\Controllers\Api\AdminMobile\BotControlController as AdminMobileBotControlController;
use App\Http\Controllers\Api\AdminMobile\CallAnalyticsController as AdminMobileCallAnalyticsController;
use App\Http\Controllers\Api\AdminMobile\CallController as AdminMobileCallController;
use App\Http\Controllers\Api\AdminMobile\ContactController as AdminMobileContactController;
use App\Http\Controllers\Api\AdminMobile\MediaController as AdminMobileMediaController;
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
        Route::get('media/messages/{message}', [AdminMobileMediaController::class, 'show'])
            ->middleware('signed')
            ->name('media.show');

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
            Route::get('call/readiness', [AdminMobileCallController::class, 'readiness'])->name('call.readiness');
            Route::get('conversations', [AdminMobileWorkspaceController::class, 'conversations'])->name('conversations.index');
            Route::get('conversations/{conversation}', [AdminMobileWorkspaceController::class, 'detail'])->name('conversations.show');
            Route::get('conversations/{conversation}/messages', [AdminMobileWorkspaceController::class, 'messages'])->name('conversations.messages.index');
            Route::get('conversations/{conversation}/poll', [AdminMobileWorkspaceController::class, 'pollConversation'])->name('conversations.poll');
            Route::post('conversations/{conversation}/reply', [AdminMobileReplyController::class, 'store'])->name('conversations.reply');
            Route::post('conversations/{conversation}/call/start', [AdminMobileCallController::class, 'start'])->name('conversations.call.start');
            Route::post('conversations/{conversation}/call/request-permission', [AdminMobileCallController::class, 'requestPermission'])->name('conversations.call.request-permission');
            Route::post('conversations/{conversation}/call/accept', [AdminMobileCallController::class, 'accept'])->name('conversations.call.accept');
            Route::post('conversations/{conversation}/call/reject', [AdminMobileCallController::class, 'reject'])->name('conversations.call.reject');
            Route::post('conversations/{conversation}/call/end', [AdminMobileCallController::class, 'end'])->name('conversations.call.end');
            Route::get('conversations/{conversation}/call/status', [AdminMobileCallController::class, 'status'])->name('conversations.call.status');
            Route::get('conversations/{conversation}/call-history', [AdminMobileCallAnalyticsController::class, 'conversationHistory'])->name('conversations.call.history');
            Route::post('conversations/{conversation}/send-contact', [AdminMobileContactController::class, 'store'])->name('conversations.send-contact');
            Route::get('conversations/{conversation}/bot-control', [AdminMobileBotControlController::class, 'status'])->name('conversations.bot-control.status');
            Route::post('conversations/{conversation}/bot-control/on', [AdminMobileBotControlController::class, 'turnOn'])->name('conversations.bot-control.on');
            Route::post('conversations/{conversation}/bot-control/off', [AdminMobileBotControlController::class, 'turnOff'])->name('conversations.bot-control.off');
            Route::post('conversations/{conversation}/bot-mode', [AdminMobileBotControlController::class, 'store'])->name('conversations.bot-mode');
            Route::post('contacts', [AdminMobileContactController::class, 'create'])->name('contacts.store');
            Route::get('call-analytics/summary', [AdminMobileCallAnalyticsController::class, 'summary'])->name('call-analytics.summary');
            Route::get('call-analytics/recent', [AdminMobileCallAnalyticsController::class, 'recent'])->name('call-analytics.recent');
            Route::get('poll/list', [AdminMobileWorkspaceController::class, 'pollList'])->name('poll.list');
            Route::get('dashboard/summary', [AdminMobileWorkspaceController::class, 'dashboardSummary'])->name('dashboard.summary');
            Route::get('meta/filters', [AdminMobileWorkspaceController::class, 'metaFilters'])->name('meta.filters');
        });
    });
