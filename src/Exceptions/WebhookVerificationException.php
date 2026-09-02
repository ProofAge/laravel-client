<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * What the proofage.verify_webhook middleware throws. Extends the SDK exception, which
 * carries errorCode, statusCode and toArray(); this subclass adds the render() Laravel's
 * exception handler calls, so an unhandled failure answers with the documented JSON body.
 *
 * @deprecated since 0.7.0 as a name to catch, removed in 1.0. Catch ProofAge\Sdk\Exceptions\WebhookVerificationException instead.
 */
class WebhookVerificationException extends \ProofAge\Sdk\Exceptions\WebhookVerificationException
{
    public function render($request)
    {
        return response()->json($this->toArray(), $this->statusCode);
    }
}
