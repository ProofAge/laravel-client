<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ProofAgeException as SdkProofAgeException;
use ProofAge\Sdk\Http\Response;

/**
 * Makes the SDK client throw this package's exception classes.
 *
 * Each class here extends its SDK counterpart, so a `catch` on either the pre-0.7 name or
 * the SDK name matches what the client throws. The mapping is the SDK's own (401, 422,
 * everything else) plus the response-less configuration failures; the client never names
 * an exception class itself, so nothing here has to be kept in sync per resource method.
 */
final class LaravelExceptionFactory implements ExceptionFactory
{
    public function fromResponse(Response $response): SdkProofAgeException
    {
        return match ($response->status()) {
            401 => AuthenticationException::fromResponse($response),
            422 => ValidationException::fromResponse($response),
            default => ProofAgeException::fromResponse($response),
        };
    }

    public function configuration(string $message, ?\Throwable $previous = null): ProofAgeException
    {
        return new ProofAgeException($message, 0, $previous);
    }
}
