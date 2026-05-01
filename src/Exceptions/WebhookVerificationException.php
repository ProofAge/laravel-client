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

    public function render($request)
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->statusCode);
    }
}
