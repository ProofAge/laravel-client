<?php

namespace ProofAge\Laravel\Services;

class WebhookSignatureVerifier
{
    public function __construct(
        protected string $secretKey,
        protected int $tolerance = 300,
    ) {}

    public function verify(string $payload, int $timestamp, string $signature): bool
    {
        $expected = $this->generateSignature($payload, $timestamp);

        return hash_equals($expected, $signature);
    }

    public function isTimestampValid(int $timestamp): bool
    {
        return abs(time() - $timestamp) <= $this->tolerance;
    }

    public function generateSignature(string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secretKey);
    }
}
