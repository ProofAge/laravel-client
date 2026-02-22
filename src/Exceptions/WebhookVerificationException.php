<?php

namespace ProofAge\Laravel\Exceptions;

class WebhookVerificationException extends ProofAgeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 401,
    ) {
        parent::__construct($message, $statusCode);
    }
}
