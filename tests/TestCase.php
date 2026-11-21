<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Orchestra\Testbench\TestCase as Orchestra;
use ProofAge\Laravel\ProofAgeServiceProvider;

class TestCase extends Orchestra
{
    /**
     * The signature a request must carry: every request is timestamped, so the HMAC covers
     * `{X-Timestamp}.{canonical}` with the timestamp read from the request itself.
     *
     * @param  Request  $request
     */
    protected static function expectedSignature($request, string $canonical, string $secret = 'test-secret-key'): string
    {
        return hash_hmac('sha256', $request->header('X-Timestamp')[0].'.'.$canonical, $secret);
    }

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
