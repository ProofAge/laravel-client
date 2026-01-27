<?php

use App\Http\Controllers\ProofAgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ProofAge Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from ProofAge. The 'proofage.verify'
| middleware ensures that all requests are properly signed with HMAC.
|
*/

// Single webhook route with middleware
Route::post('/proofage/webhook', [ProofAgeWebhookController::class, 'handle'])
    ->middleware('proofage.verify')
    ->name('proofage.webhook');

// Multiple webhook routes with middleware group
Route::middleware(['proofage.verify'])->prefix('proofage')->name('proofage.')->group(function () {
    Route::post('/webhook', [ProofAgeWebhookController::class, 'handle'])->name('webhook');
    Route::post('/status', [ProofAgeWebhookController::class, 'status'])->name('status');
    Route::post('/notification', [ProofAgeWebhookController::class, 'notification'])->name('notification');
});

// API routes (if using api.php)
Route::middleware(['api', 'proofage.verify'])->prefix('api/proofage')->group(function () {
    Route::post('/webhook', [ProofAgeWebhookController::class, 'handle']);
});
