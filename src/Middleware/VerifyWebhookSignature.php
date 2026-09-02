<?php

namespace ProofAge\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Support\ConfigResolver;
use ProofAge\Sdk\Exceptions\WebhookVerificationException as SdkWebhookVerificationException;
use ProofAge\Sdk\Webhooks\WebhookVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects an inbound ProofAge webhook that is not signed with the workspace's keys.
 *
 * The check sequence and its codes (MISSING_SIGNATURE, MISSING_TIMESTAMP, MISSING_AUTH_CLIENT,
 * INVALID_AUTH_CLIENT, TIMESTAMP_TOO_OLD, INVALID_SIGNATURE) are the SDK's WebhookVerifier.
 * What stays here is config resolution and its CONFIGURATION_ERROR, and the choice of
 * exception class: the SDK's is re-thrown as this package's subclass so Laravel's exception
 * handler renders it and pre-0.7 `catch` blocks keep matching.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next, string $configPrefix = 'proofage'): Response
    {
        $signature = $request->header('X-HMAC-Signature');
        $timestamp = $request->header('X-Timestamp');
        $authClient = $request->header('X-Auth-Client');

        // Header presence is reported before configuration, as it always was, so an unsigned
        // request is rejected as unsigned even on a misconfigured app. The SDK verifier repeats
        // these three checks; here they only fix their place in the order.
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

        try {
            (new WebhookVerifier($apiKey, $secretKey, $tolerance))
                ->verify($signature, $timestamp, $authClient, (string) $request->getContent());
        } catch (SdkWebhookVerificationException $e) {
            throw new WebhookVerificationException($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $next($request);
    }
}
