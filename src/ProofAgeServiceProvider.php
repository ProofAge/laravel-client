<?php

namespace ProofAge\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ProofAge\Laravel\Console\Commands\VerifySetupCommand;
use ProofAge\Laravel\Exceptions\WebhookVerificationException;
use ProofAge\Laravel\Middleware\VerifyWebhookSignature;

class ProofAgeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/proofage.php',
            'proofage'
        );

        $this->app->singleton(ProofAgeClient::class, function ($app) {
            return new ProofAgeClient([
                'api_key' => config('proofage.api_key'),
                'secret_key' => config('proofage.secret_key'),
                'base_url' => config('proofage.base_url'),
                'version' => config('proofage.version'),
                'timeout' => config('proofage.timeout'),
                'retry_attempts' => config('proofage.retry_attempts'),
                'retry_delay' => config('proofage.retry_delay'),
            ]);
        });

        $this->app->alias(ProofAgeClient::class, 'proofage');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/proofage.php' => config_path('proofage.php'),
            ], 'config');
        }

        // Register middleware
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('proofage.verify_webhook', VerifyWebhookSignature::class);

        // Register default exception renderer for webhook verification errors
        $this->app->make(ExceptionHandler::class)->renderable(
            function (WebhookVerificationException $e) {
                return response()->json([
                    'error' => [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                    ],
                ], $e->statusCode);
            }
        );

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifySetupCommand::class,
            ]);
        }
    }
}
