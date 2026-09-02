<?php

namespace ProofAge\Laravel;

use ProofAge\Laravel\Exceptions\LaravelExceptionFactory;
use ProofAge\Laravel\Http\IlluminateHttpClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Http\HttpClient;

/**
 * The SDK client wired for Laravel: sends through the Http facade (so Http::fake() keeps
 * intercepting), throws this package's exception classes, and builds this package's resource
 * classes. Signing, retries and status mapping are the SDK's; nothing is reimplemented here.
 *
 * A subclass rather than an alias, so `new ProofAgeClient($config)` keeps working and keeps
 * these defaults. Every instance satisfies a type hint on either name; an SDK-built
 * ProofAge\Sdk\Client does not satisfy a hint on this one.
 */
class ProofAgeClient extends Client
{
    /**
     * @param  array<string, mixed>  $config  api_key, secret_key, base_url, and the optional keys the SDK documents
     */
    public function __construct(array $config = [], ?HttpClient $transport = null, ?ExceptionFactory $exceptions = null)
    {
        parent::__construct($config, $transport ?? new IlluminateHttpClient, $exceptions ?? new LaravelExceptionFactory);
    }

    public function workspace(): WorkspaceResource
    {
        return new WorkspaceResource($this);
    }

    public function verifications(?string $id = null): VerificationResource
    {
        return new VerificationResource($this, $id);
    }
}
