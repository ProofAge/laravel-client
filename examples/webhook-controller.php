<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProofAgeWebhookController extends Controller
{
    /**
     * Handle incoming ProofAge webhook
     *
     * This route should be protected with the 'proofage.verify_webhook' middleware:
     * Route::post('/proofage/webhook', [ProofAgeWebhookController::class, 'handle'])
     *     ->middleware('proofage.verify_webhook');
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('ProofAge webhook received', [
            'event_type' => $payload['event_type'] ?? 'unknown',
            'verification_id' => $payload['verification_id'] ?? null,
            'payload' => $payload,
        ]);

        // Handle different webhook events
        match ($payload['event_type'] ?? null) {
            'verification.completed' => $this->handleVerificationCompleted($payload),
            'verification.failed' => $this->handleVerificationFailed($payload),
            'verification.expired' => $this->handleVerificationExpired($payload),
            default => Log::warning('Unknown webhook event type', ['payload' => $payload]),
        };

        return response()->json(['status' => 'received']);
    }

    /**
     * Handle verification completed event
     */
    protected function handleVerificationCompleted(array $payload): void
    {
        $verificationId = $payload['verification_id'];
        $result = $payload['result'] ?? [];

        Log::info('Verification completed', [
            'verification_id' => $verificationId,
            'status' => $result['status'] ?? 'unknown',
            'checks' => $result['checks'] ?? [],
        ]);

        // Update your application's verification record
        // Example: User::where('verification_id', $verificationId)->update(['verified' => true]);
    }

    /**
     * Handle verification failed event
     */
    protected function handleVerificationFailed(array $payload): void
    {
        $verificationId = $payload['verification_id'];
        $error = $payload['error'] ?? [];

        Log::warning('Verification failed', [
            'verification_id' => $verificationId,
            'error_code' => $error['code'] ?? 'unknown',
            'error_message' => $error['message'] ?? 'No message provided',
        ]);

        // Handle verification failure in your application
        // Example: User::where('verification_id', $verificationId)->update(['verification_failed' => true]);
    }

    /**
     * Handle verification expired event
     */
    protected function handleVerificationExpired(array $payload): void
    {
        $verificationId = $payload['verification_id'];

        Log::info('Verification expired', [
            'verification_id' => $verificationId,
        ]);

        // Handle verification expiration in your application
        // Example: User::where('verification_id', $verificationId)->update(['verification_expired' => true]);
    }
}
