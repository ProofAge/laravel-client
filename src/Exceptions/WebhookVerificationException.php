<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\DescribesWebhookFailure;

/**
 * Descends from the Laravel base for the same reason as ValidationException, and adds the
 * render() Laravel's exception handler calls to turn a rejected webhook into a JSON response.
 *
 * @deprecated since 0.7.0, removed in 1.0. Still the class the middleware throws for a rejected
 *             webhook: catch this name for that, or ProofAge\Sdk\Exceptions\ProofAgeException for
 *             every error. ProofAge\Sdk\Exceptions\WebhookVerificationException does not match it.
 */
class WebhookVerificationException extends ProofAgeException
{
    use DescribesWebhookFailure;

    public function render($request)
    {
        return response()->json($this->toArray(), $this->statusCode);
    }
}
