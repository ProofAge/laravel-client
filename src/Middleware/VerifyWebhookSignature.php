<?php

namespace ProofAge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming webhook request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedSignature = $request->header('X-HMAC-Signature');
        $timestamp = $request->header('X-Timestamp');
        $authClient = $request->header('X-Auth-Client');

        if (! $providedSignature) {
            return response()->json([
                'error' => [
                    'code' => 'MISSING_SIGNATURE',
                    'message' => 'X-HMAC-Signature header is required',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $timestamp) {
            return response()->json([
                'error' => [
                    'code' => 'MISSING_TIMESTAMP',
                    'message' => 'X-Timestamp header is required',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $authClient) {
            return response()->json([
                'error' => [
                    'code' => 'MISSING_AUTH_CLIENT',
                    'message' => 'X-Auth-Client header is required',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Verify timestamp is not too old (prevent replay attacks)
        $timestampInt = (int) $timestamp;
        $timestampAge = now()->timestamp - $timestampInt;
        if ($timestampAge > 300) { // 5 minutes tolerance
            return response()->json([
                'error' => [
                    'code' => 'TIMESTAMP_TOO_OLD',
                    'message' => 'Timestamp is too old',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $secretKey = config('proofage.secret_key');
        $expectedApiKey = config('proofage.api_key');

        if (! $secretKey || ! $expectedApiKey) {
            Log::error('ProofAge middleware: Secret key or API key is not configured');

            return response()->json([
                'error' => [
                    'code' => 'CONFIGURATION_ERROR',
                    'message' => 'Middleware configuration is incomplete',
                ],
            ], Response::HTTP_I_AM_A_TEAPOT);
        }

        if ($expectedApiKey !== $authClient) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_AUTH_CLIENT',
                    'message' => 'X-Auth-Client header is invalid',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = $this->generateSignature($request, $timestampInt, $secretKey);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_SIGNATURE',
                    'message' => 'HMAC signature is invalid',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Generate HMAC-SHA256 signature for webhook payload.
     */
    protected function generateSignature(Request $request, int $timestamp, string $secret): string
    {
        // Get the raw JSON body
        $rawBody = $request->getContent();

        // Create signature payload: "$timestamp.$rawBody"
        $signaturePayload = $timestamp.'.'.$rawBody;

        // Generate HMAC-SHA256 signature
        return hash_hmac('sha256', $signaturePayload, $secret);
    }
}
