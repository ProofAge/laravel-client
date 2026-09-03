<?php

namespace ProofAge\Laravel\Services;

/**
 * The SDK's webhook signature verifier under its pre-0.7 name: verify() with the canonical
 * JSON fallback, isTimestampValid(), generateSignature(). The middleware no longer constructs
 * it directly (it delegates the whole check sequence to ProofAge\Sdk\Webhooks\WebhookVerifier);
 * the class stays for consumers who verify by hand.
 */
class WebhookSignatureVerifier extends \ProofAge\Sdk\Webhooks\WebhookSignatureVerifier {}
