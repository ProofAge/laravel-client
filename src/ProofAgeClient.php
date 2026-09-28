<?php

namespace ProofAge\Laravel;

use Illuminate\Support\Sleep;
use ProofAge\Laravel\Exceptions\LaravelExceptionFactory;
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\Http\IlluminateHttpClient;
use ProofAge\Laravel\Resources\VerificationResource;
use ProofAge\Laravel\Resources\WorkspaceResource;
use ProofAge\Sdk\Client;
use ProofAge\Sdk\Exceptions\ExceptionFactory;
use ProofAge\Sdk\Http\HttpClient;

/**
 * The SDK client wired for Laravel: sends through the Http facade (so Http::fake() keeps
 * intercepting), waits between retries through Illuminate\Support\Sleep (so Sleep::fake() keeps
 * covering), throws this package's exception classes, and builds this package's resource
 * classes. Signing, retries and status mapping are the SDK's; nothing is reimplemented here.
 *
 * Every request says it came through this package: `laravel/{ProofAge::VERSION}` goes ahead of
 * the SDK's `php/{version}` in X-ProofAge-Sdk, and `ProofAge-Laravel/{version}` ahead of the SDK's
 * product in User-Agent. A package wrapping this one passes its own `sdk_tokens` and
 * `user_agent_prefix`, which stay in front.
 *
 * A subclass rather than an alias, so `new ProofAgeClient($config)` keeps working and keeps
 * these defaults. Every instance satisfies a type hint on either name; an SDK-built
 * ProofAge\Sdk\Client does not satisfy a hint on this one.
 */
class ProofAgeClient extends Client
{
    /**
     * @param  array<string, mixed>  $config  api_key, secret_key, base_url, and the optional keys the SDK documents
     * @param  callable(int): void|null  $sleep  the wait between retry attempts, in microseconds. Defaults to
     *                                           Illuminate\Support\Sleep::usleep(), so Sleep::fake() in a
     *                                           test records the wait instead of the suite sleeping for real.
     */
    public function __construct(#[\SensitiveParameter] array $config = [], ?HttpClient $transport = null, ?ExceptionFactory $exceptions = null, ?callable $sleep = null)
    {
        parent::__construct(
            self::identifyAsLaravel($config),
            $transport ?? new IlluminateHttpClient,
            $exceptions ?? new LaravelExceptionFactory,
            $sleep ?? Sleep::usleep(...),
        );
    }

    /**
     * Appends this package's token and product to whatever a wrapper passed. A value of the
     * wrong type is left alone so the SDK rejects it with its own configuration error.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function identifyAsLaravel(array $config): array
    {
        $tokens = $config['sdk_tokens'] ?? [];
        $prefix = $config['user_agent_prefix'] ?? '';

        if (is_array($tokens) && array_is_list($tokens)) {
            $config['sdk_tokens'] = [...$tokens, 'laravel/'.ProofAge::VERSION];
        }

        if (is_string($prefix)) {
            $product = 'ProofAge-Laravel/'.ProofAge::VERSION;
            $config['user_agent_prefix'] = $prefix === '' ? $product : $prefix.' '.$product;
        }

        return $config;
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
