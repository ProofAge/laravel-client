<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ProofAge\Sdk\Enums\VerificationStatus;
use ProofAge\Sdk\Enums\WebhookReason;

/**
 * Receives ProofAge's decision webhook.
 *
 * A workspace has one webhook URL, set in the workspace settings of the ProofAge console, and
 * ProofAge POSTs a JSON body to it whenever a verification reaches a decision status. Route it
 * through the signature middleware (see routes-example.php):
 *
 *     Route::post('/webhooks/proofage', [ProofAgeWebhookController::class, 'handle'])
 *         ->middleware('proofage.verify_webhook');
 *
 * The body, as sent by ProofAge:
 *
 *     {
 *         "verification_id": "019d...",
 *         "status": "approved",              // approved | declined | resubmission_requested | review | abandoned | expired
 *         "external_id": "123",              // what you passed to create(), or null
 *         "external_metadata": {"plan": "pro"}, // what you passed to create(), or null
 *         "reason": null,                    // a dotted reason code on declined / resubmission_requested
 *         "timestamp": "2026-09-27T12:00:00+00:00",
 *         // only when a matching face was found on another account:
 *         "duplicate_detected": true, "duplicate_count": 1,
 *         "duplicate_of": {"verification_id": "019c...", "external_id": "77"},
 *         // only when present: "fingerprint_signals": {...}, "manual_moderation": {...}
 *     }
 *
 * Answer 2xx quickly; anything else (or a timeout) makes ProofAge retry the same delivery.
 */
class ProofAgeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // A retried delivery carries the same id, so it is processed once. (Resending from the
        // console creates a new delivery with a new id; the handlers below are idempotent for
        // that case.)
        $deliveryId = (string) $request->header('X-ProofAge-Webhook-Delivery-Id');
        $seenKey = "proofage-webhook-delivery:{$deliveryId}";

        if ($deliveryId !== '' && Cache::has($seenKey)) {
            return response()->json(['status' => 'duplicate']);
        }

        $payload = $request->json()->all();

        $user = isset($payload['external_id'])
            ? User::find($payload['external_id'])
            : null;

        if ($user === null) {
            Log::warning('ProofAge webhook for an unknown user', [
                'verification_id' => $payload['verification_id'] ?? null,
                'external_id' => $payload['external_id'] ?? null,
            ]);
        } else {
            $this->apply($user, $payload);
        }

        if ($deliveryId !== '') {
            Cache::put($seenKey, true, now()->addDays(7));
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(User $user, array $payload): void
    {
        // Deliveries can arrive out of order (a retry of an older one after a newer one):
        // never let an older decision overwrite a newer one.
        $decidedAt = Carbon::parse($payload['timestamp']);

        if ($user->proofage_decided_at !== null && $user->proofage_decided_at->gt($decidedAt)) {
            return;
        }

        $reason = $payload['reason'] ?? null;

        match (VerificationStatus::tryFrom($payload['status'] ?? '')) {
            VerificationStatus::APPROVED => $user->markAgeVerified(),
            VerificationStatus::DECLINED => WebhookReason::isAmlBlocklist($reason)
                ? $user->blockForBlocklistMatch($reason)
                : $user->markAgeVerificationFailed($reason),
            // The person can try again on the same verification link.
            VerificationStatus::RESUBMISSION_REQUESTED => $user->askToRetryVerification($reason),
            // A person at ProofAge will decide; another webhook follows.
            VerificationStatus::REVIEW => $user->markAgeVerificationPending(),
            VerificationStatus::ABANDONED, VerificationStatus::EXPIRED => $user->clearAgeVerification(),
            default => Log::warning('Unhandled ProofAge status', ['status' => $payload['status'] ?? null]),
        };

        if (($payload['duplicate_detected'] ?? false) === true) {
            Log::notice('ProofAge found the same face on another account', [
                'verification_id' => $payload['verification_id'],
                'duplicate_count' => $payload['duplicate_count'],
                'duplicate_of_external_id' => $payload['duplicate_of']['external_id'] ?? null,
            ]);
        }

        $user->forceFill([
            'proofage_verification_id' => $payload['verification_id'],
            'proofage_status' => $payload['status'],
            'proofage_decided_at' => $decidedAt,
        ])->save();
    }
}
