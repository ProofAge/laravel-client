<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * The pre-0.7 base exception, kept so existing `catch` blocks keep matching. Inside a Laravel
 * application the client throws this class for every non-2xx that is not a 401 or a 422, and
 * for an incomplete configuration; AuthenticationException, ValidationException and
 * WebhookVerificationException extend it, so a `catch` on this name sees those too, exactly
 * as it did before 0.7.
 *
 * What it does not see is ProofAge\Sdk\Exceptions\TransportException (a failure below HTTP),
 * which is why the catch-all is the SDK base class this one extends. See UPGRADE.md.
 *
 * @deprecated since 0.7.0, removed in 1.0. Catch ProofAge\Sdk\Exceptions\ProofAgeException instead;
 *             it matches everything this class does, and TransportException as well.
 */
class ProofAgeException extends \ProofAge\Sdk\Exceptions\ProofAgeException {}
