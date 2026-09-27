<?php

use App\Http\Controllers\ProofAgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ProofAge Webhook Route (routes/api.php)
|--------------------------------------------------------------------------
|
| A workspace sends every webhook to one URL — the webhook URL in its settings in the
| ProofAge console — so one route per workspace is all there is to register. The
| 'proofage.verify_webhook' middleware rejects any request not signed with the
| workspace's keys.
|
| routes/api.php is the simplest home: its routes carry no CSRF check, which ProofAge's
| POST could never pass. They are prefixed with /api, so this route answers at
| https://your-app.com/api/webhooks/proofage — the URL to enter in the console.
| (No routes/api.php yet? `php artisan install:api` creates and registers it.)
|
*/

Route::post('/webhooks/proofage', [ProofAgeWebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook')
    ->name('proofage.webhook');

/*
| A second workspace (for example, sellers verified with their own keys) has its own URL
| and names the config prefix its keys live under:
|
| Route::post('/webhooks/proofage-seller', [SellerWebhookController::class, 'handle'])
|     ->middleware('proofage.verify_webhook:services.proofage_seller');
|
|--------------------------------------------------------------------------
| In routes/web.php instead
|--------------------------------------------------------------------------
|
| Web routes verify a CSRF token, so exclude the webhook path in bootstrap/app.php:
|
| ->withMiddleware(function (Middleware $middleware) {
|     $middleware->validateCsrfTokens(except: ['webhooks/proofage']);
| })
|
*/
