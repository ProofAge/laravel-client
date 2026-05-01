<?php

namespace ProofAge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;

/**
 * @method static WorkspaceResource workspace()
 * @method static VerificationResource verifications(string $id = null)
 *
 * @see ProofAgeClient
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
