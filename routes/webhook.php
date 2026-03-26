<?php

use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhook events from external providers.
| CSRF verification is disabled for this route group via bootstrap/app.php.
|
*/

Route::prefix('webhook')->name('webhook.')->group(function (): void {

    // Meta WhatsApp Cloud API
    // GET  — hub.mode=subscribe verification challenge
    // POST — incoming messages and status updates
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('whatsapp.verify');

    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive'])
        ->name('whatsapp.receive');
});
