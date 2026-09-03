<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\DescribesWebhookFailure;

/**
 * Descends from the Laravel base for the same reason as ValidationException, and adds the
 * render() Laravel's exception handler calls to turn a rejected webhook into a JSON response.
 *
 * @deprecated 0.7.0 Catch ProofAge\Sdk\Exceptions\WebhookVerificationException instead. Removed in 1.0.
 */
class WebhookVerificationException extends ProofAgeException
{
    use DescribesWebhookFailure;

    public function render($request)
    {
        return response()->json($this->toArray(), $this->statusCode);
    }
}
