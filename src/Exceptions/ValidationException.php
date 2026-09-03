<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\HasValidationErrors;

/**
 * Descends from the Laravel base rather than from ProofAge\Sdk\Exceptions\ValidationException,
 * so that a pre-0.7 `catch (ProofAge\Laravel\Exceptions\ProofAgeException)` still sees a 422.
 * getErrors() comes from the shared SDK trait, so the two implementations cannot drift.
 *
 * @deprecated since 0.7.0, removed in 1.0. Still the class thrown for a 422 inside a Laravel
 *             application: catch this name for a 422, or ProofAge\Sdk\Exceptions\ProofAgeException
 *             for every error. ProofAge\Sdk\Exceptions\ValidationException does not match it.
 */
class ValidationException extends ProofAgeException
{
    use HasValidationErrors;
}
