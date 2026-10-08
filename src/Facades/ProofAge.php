<?php

namespace ProofAge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Sdk\Http\Body\FilePart;
use ProofAge\Sdk\Http\HttpClient;
use ProofAge\Sdk\Http\Response;
use ProofAge\Sdk\Resources\WebhookSubscriptionResource;

/**
 * Proxies to the ProofAgeClient singleton. The API calls live on the resources:
 * workspace() has get() and getConsent(); verifications() has create(), list(), find(), get(),
 * acceptConsent(), uploadMedia(), submit(), document(), downloadMedia(), downloadMediaTo(),
 * estimation(), blockFace() and setTestOutcome(). webhookSubscriptions() has create(), list()
 * and delete().
 *
 * @method static WorkspaceResource workspace()
 * @method static VerificationResource verifications(?string $id = null)
 * @method static WebhookSubscriptionResource webhookSubscriptions()
 * @method static Response makeRequest(string $method, string $endpoint, array<string, mixed> $data = [], array<string, string|\SplFileInfo|FilePart> $files = [])
 * @method static Response makeStreamedRequest(string $method, string $endpoint, ?string $sink = null)
 * @method static ProofAgeClient pushMiddleware(callable $middleware, ?string $name = null)
 * @method static ProofAgeClient removeMiddleware(string $name)
 * @method static ProofAgeClient onRequest(callable $listener)
 * @method static ProofAgeClient onResponse(callable $listener)
 * @method static ProofAgeClient onError(callable $listener)
 * @method static HttpClient transport()
 *
 * @see ProofAgeClient
 */
class ProofAge extends Facade
{
    /**
     * This package's version, reported as `laravel/{VERSION}` in X-ProofAge-Sdk and
     * `ProofAge-Laravel/{VERSION}` in User-Agent. Equal to the newest released heading in
     * CHANGELOG.md (tests/VersionTest.php); bumped in the release commit. The SDK's own is
     * ProofAge\Sdk\Client::VERSION.
     */
    public const VERSION = '0.10.0';

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'proofage';
    }
}
