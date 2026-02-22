<?php

namespace ProofAge\Laravel;

use ProofAge\Laravel\Support\ConfigResolver;

class ProofAgeClientFactory
{
    public function make(string $configPrefix = 'proofage'): ProofAgeClient
    {
        return new ProofAgeClient(ConfigResolver::resolve($configPrefix));
    }
}
