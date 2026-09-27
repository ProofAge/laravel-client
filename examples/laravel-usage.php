<?php

// Example Laravel Controller using the ProofAge client
//
// Most integrations only need startVerification(): send the person to the hosted flow at
// $verification['url'] and learn the outcome from the webhook (see webhook-controller.php).
// The consent/upload/submit actions below are for an integration that captures the images
// itself instead of using the hosted flow.

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// Inside a Laravel application the client throws this package's exception classes: catch the
// Laravel name for a specific status, and the SDK base class for everything — it is the parent of
// all of them, and of TransportException. The SDK's own AuthenticationException and
// ValidationException are not parents of the Laravel classes and would never match here (see UPGRADE.md).
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Sdk\Exceptions\ProofAgeException;

class VerificationController extends Controller
{
    /**
     * Start a new verification process.
     */
    public function startVerification(Request $request): JsonResponse
    {
        try {
            $verification = ProofAge::verifications()->create([
                // Where the person's browser is sent when they finish the flow — a page of
                // your app, not a webhook. The outcome arrives separately, at the webhook URL
                // configured in the workspace settings of the ProofAge console.
                'callback_url' => route('verification.done'),
                // Your own identifiers, echoed back in every verification response and in
                // every webhook, so the webhook can find the user. (`metadata` is stored by
                // ProofAge but never returned: do not use it for correlation.)
                'external_id' => (string) $request->user()->id,
                'external_metadata' => ['plan' => 'pro'],
            ]);

            return response()->json([
                'success' => true,
                'verification_id' => $verification['id'],
                // Redirect the person here to run the hosted verification flow.
                'url' => $verification['url'],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->getErrors(),
            ], 422);

        } catch (AuthenticationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
                'error_code' => $e->getErrorCode(),
            ], 401);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record that the person accepted the consent text you showed them.
     *
     * The consent version and its hash are not yours to choose: they must be exactly the
     * `id` and `text_sha256` of the active version returned by GET /v1/consent, whose text
     * (at its `url`) is what the person has to be shown. Any other value is rejected.
     */
    public function acceptConsent(Request $request, string $verificationId): JsonResponse
    {
        $request->validate([
            'consent' => 'accepted',
        ]);

        try {
            $consent = ProofAge::workspace()->getConsent();

            $result = ProofAge::verifications($verificationId)->acceptConsent([
                'consent_version_id' => $consent['id'],
                'text_sha256' => $consent['text_sha256'],
            ]);

            return response()->json([
                'success' => true,
                'consent_accepted_at' => $result['consent_accepted_at'],
            ]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Upload a selfie or one side of an identity document.
     */
    public function uploadMedia(Request $request, string $verificationId): JsonResponse
    {
        // The same rules the API applies: images only, up to 10 MB; a document needs the
        // side and the document kind.
        $request->validate([
            'type' => 'required|in:selfie,document',
            'side' => 'required_if:type,document|in:front,back',
            'document' => 'required_if:type,document|in:id,driver_license,passport,residence_permit',
            'file' => 'required|image|max:10240',
        ]);

        try {
            // The API answers 200 with an empty body, so this returns null; failures throw.
            ProofAge::verifications($verificationId)->uploadMedia(array_filter([
                'type' => $request->input('type'),
                'side' => $request->input('side'),
                'document' => $request->input('document'),
                'file' => $request->file('file'),
            ], fn ($value) => $value !== null));

            return response()->json(['success' => true]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get verification status.
     */
    public function getVerification(string $verificationId): JsonResponse
    {
        try {
            $verification = ProofAge::verifications($verificationId)->get();

            return response()->json([
                'success' => true,
                // One of the ProofAge\Sdk\Enums\VerificationStatus values, or
                // `documents_required`, which is not one of its cases: map it with tryFrom().
                'status' => $verification['status'],
                'reason' => $verification['reason'],
            ]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Submit verification for processing.
     */
    public function submitVerification(string $verificationId): JsonResponse
    {
        try {
            // Empty 200 from the API, so null here; the decision arrives by webhook.
            ProofAge::verifications($verificationId)->submit();

            return response()->json(['success' => true]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Get workspace information.
     */
    public function getWorkspace(): JsonResponse
    {
        try {
            $workspace = ProofAge::workspace()->get();

            return response()->json([
                'success' => true,
                'workspace' => $workspace,
            ]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }
}
