<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * HTTP 422 under its pre-0.7 name; what the client throws in a Laravel app. getErrors() is inherited.
 *
 * @deprecated since 0.7.0, removed in 1.0. Catch ProofAge\Sdk\Exceptions\ValidationException instead.
 */
class ValidationException extends \ProofAge\Sdk\Exceptions\ValidationException {}
