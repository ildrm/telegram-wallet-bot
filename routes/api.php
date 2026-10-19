<?php

use App\Http\Controllers\CustodyWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/webhooks/telegram', TelegramWebhookController::class);
    Route::post('/webhooks/custody', CustodyWebhookController::class);
});
