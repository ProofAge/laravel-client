<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * HTTP 401 under its pre-0.7 name; what the client throws in a Laravel app.
 *
 * @deprecated since 0.7.0, removed in 1.0. Catch ProofAge\Sdk\Exceptions\AuthenticationException instead.
 */
class AuthenticationException extends \ProofAge\Sdk\Exceptions\AuthenticationException {}
