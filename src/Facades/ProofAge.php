<?php

namespace ProofAge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProofAge\Laravel\Resources\WorkspaceResource workspace()
 * @method static \ProofAge\Laravel\Resources\VerificationResource verifications(string $id = null)
 *
 * @see \ProofAge\Laravel\ProofAgeClient
 */
class ProofAge extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'proofage';
    }
}
