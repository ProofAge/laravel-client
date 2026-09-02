<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * The pre-0.7 base exception, kept so existing `catch` blocks keep matching. In a Laravel
 * app the client throws this class for every non-2xx that is not a 401 or a 422, and for an
 * incomplete configuration.
 *
 * AuthenticationException and ValidationException extend their SDK counterparts rather than
 * this class, so a `catch` on this name does not see a 401 or a 422. The catch-all is the
 * SDK base class, ProofAge\Sdk\Exceptions\ProofAgeException. See UPGRADE.md.
 *
 * @deprecated since 0.7.0, removed in 1.0. Catch ProofAge\Sdk\Exceptions\ProofAgeException instead.
 */
class ProofAgeException extends \ProofAge\Sdk\Exceptions\ProofAgeException {}
