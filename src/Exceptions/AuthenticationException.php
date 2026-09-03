<?php

namespace ProofAge\Laravel\Exceptions;

/**
 * Descends from the Laravel base rather than from ProofAge\Sdk\Exceptions\AuthenticationException,
 * so that a pre-0.7 `catch (ProofAge\Laravel\Exceptions\ProofAgeException)` still sees a 401.
 *
 * @deprecated since 0.7.0, removed in 1.0. Still the class thrown for a 401 inside a Laravel
 *             application: catch this name for a 401, or ProofAge\Sdk\Exceptions\ProofAgeException
 *             for every error. ProofAge\Sdk\Exceptions\AuthenticationException does not match it.
 */
class AuthenticationException extends ProofAgeException {}
