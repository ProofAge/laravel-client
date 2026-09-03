<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Exceptions\ProofAgeException as SdkProofAgeException;
use ProofAge\Sdk\Http\Response;

/**
 * Makes the SDK client throw this package's exception classes.
 *
 * Every class here descends from the Laravel base, which descends from the SDK base, so a
 * pre-0.7 `catch` on any Laravel name keeps matching and a `catch` on
 * ProofAge\Sdk\Exceptions\ProofAgeException catches everything. The SDK's own 401 and 422
 * classes are not in that chain (PHP allows one parent), so nothing built here matches a
 * `catch` on ProofAge\Sdk\Exceptions\AuthenticationException or ValidationException. The
 * mapping is the SDK's own (401, 422, everything else) plus the response-less configuration
 * failures; the client never names an exception class itself, so nothing here has to be kept
 * in sync per resource method.
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
