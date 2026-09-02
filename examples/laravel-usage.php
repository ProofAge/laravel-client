<?php

// Example Laravel Controller using the ProofAge client

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ProofAge\Laravel\Facades\ProofAge;
// The client throws this package's ProofAge\Laravel\Exceptions\* classes, which extend these SDK
// classes, so catching the SDK names works — and the SDK base class is the one that catches
// every error, 401 and 422 included (see UPGRADE.md).
use ProofAge\Sdk\Exceptions\AuthenticationException;
use ProofAge\Sdk\Exceptions\ProofAgeException;
use ProofAge\Sdk\Exceptions\ValidationException;

class VerificationController extends Controller
{
    /**
     * Start a new verification process.
     */
    public function startVerification(Request $request): JsonResponse
    {
        $request->validate([
            'callback_url' => 'required|url',
            'metadata' => 'array',
        ]);

        try {
            // Create verification using the facade
            $verification = ProofAge::verifications()->create([
                'callback_url' => $request->input('callback_url'),
                'metadata' => $request->input('metadata', []),
            ]);

            return response()->json([
                'success' => true,
                'verification' => $verification,
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
     * Accept consent for a verification.
     */
    public function acceptConsent(Request $request, string $verificationId): JsonResponse
    {
        $request->validate([
            'consent_version_id' => 'required|integer',
            'text_sha256' => 'required|string',
        ]);

        try {
            $result = ProofAge::verifications($verificationId)->acceptConsent([
                'consent_version_id' => $request->input('consent_version_id'),
                'text_sha256' => $request->input('text_sha256'),
            ]);

            return response()->json([
                'success' => true,
                'consent' => $result,
            ]);

        } catch (ProofAgeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    /**
     * Upload media for verification.
     */
    public function uploadMedia(Request $request, string $verificationId): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:selfie,document_front,document_back',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        try {
            $result = ProofAge::verifications($verificationId)->uploadMedia([
                'type' => $request->input('type'),
                'file' => $request->file('file'),
            ]);

            return response()->json([
                'success' => true,
                'media' => $result,
            ]);

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
                'verification' => $verification,
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
            $result = ProofAge::verifications($verificationId)->submit();

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);

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
