<?php

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
