<?php

namespace ProofAge\Laravel\Exceptions;

use ProofAge\Sdk\Exceptions\Concerns\HasValidationErrors;

/**
 * Descends from the Laravel base rather than from ProofAge\Sdk\Exceptions\ValidationException,
 * so that a pre-0.7 `catch (ProofAge\Laravel\Exceptions\ProofAgeException)` still sees a 422.
 * getErrors() comes from the shared SDK trait, so the two implementations cannot drift.
 *
 * @deprecated 0.7.0 Catch ProofAge\Sdk\Exceptions\ValidationException instead. Removed in 1.0.
 */
class ValidationException extends ProofAgeException
{
    use HasValidationErrors;
}
