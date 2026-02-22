<?php

namespace ProofAge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Services\WebhookSignatureVerifier;
use ProofAge\Laravel\Support\ConfigResolver;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, string $configPrefix = 'proofage'): Response
    {
        $signature = $request->header('X-HMAC-Signature');
        $timestamp = $request->header('X-Timestamp');
        $authClient = $request->header('X-Auth-Client');

        if (! $signature) {
            throw new WebhookVerificationException('MISSING_SIGNATURE', 'X-HMAC-Signature header is required');
        }

        if (! $timestamp) {
            throw new WebhookVerificationException('MISSING_TIMESTAMP', 'X-Timestamp header is required');
        }

        if (! $authClient) {
            throw new WebhookVerificationException('MISSING_AUTH_CLIENT', 'X-Auth-Client header is required');
        }

        $config = ConfigResolver::resolve($configPrefix);
        $secretKey = $config['secret_key'];
        $apiKey = $config['api_key'];
        $tolerance = (int) ($config['webhook_tolerance'] ?? 300);

        if (! $secretKey || ! $apiKey) {
            throw new WebhookVerificationException('CONFIGURATION_ERROR', 'Middleware configuration is incomplete', 418);
        }

        if ($apiKey !== $authClient) {
            throw new WebhookVerificationException('INVALID_AUTH_CLIENT', 'X-Auth-Client header is invalid');
        }

        $verifier = new WebhookSignatureVerifier($secretKey, $tolerance);
        $timestampInt = (int) $timestamp;

        if (! $verifier->isTimestampValid($timestampInt)) {
            throw new WebhookVerificationException('TIMESTAMP_TOO_OLD', 'Timestamp is too old');
        }

        if (! $verifier->verify($request->getContent(), $timestampInt, $signature)) {
            throw new WebhookVerificationException('INVALID_SIGNATURE', 'HMAC signature is invalid');
        }

        return $next($request);
    }
}
