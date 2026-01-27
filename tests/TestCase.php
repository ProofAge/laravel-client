<?php

namespace ProofAge\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ProofAge\Laravel\ProofAgeServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ProofAgeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('proofage.api_key', 'test-api-key');
        $app['config']->set('proofage.secret_key', 'test-secret-key');
        $app['config']->set('proofage.base_url', 'https://api.test.com');
    }
}
