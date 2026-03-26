<?php

use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Middleware\LogWhatsAppWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhook events from external providers.
| CSRF verification is disabled for this route group via bootstrap/app.php.
|
| LogWhatsAppWebhook middleware runs BEFORE the controller and writes an
| emergency raw-file trace plus a Laravel channel log for every request,
| so even if the controller crashes there is always a record that Meta
| reached this server.
|
*/

Route::prefix('webhook')
    ->name('webhook.')
    ->middleware(LogWhatsAppWebhook::class)
    ->group(function (): void {

        // Meta WhatsApp Cloud API
        // GET  — hub.mode=subscribe verification challenge
        // POST — incoming messages and status updates
        Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])
            ->name('whatsapp.verify');

        Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive'])
            ->name('whatsapp.receive');
    });
