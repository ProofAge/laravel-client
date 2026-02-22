<?php

namespace ProofAge\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ProofAge\Laravel\Console\Commands\VerifySetupCommand;
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

        $this->app->singleton(ProofAgeClientFactory::class);

        $this->app->singleton(ProofAgeClient::class, function ($app) {
            return $app->make(ProofAgeClientFactory::class)->make();
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

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('proofage.verify_webhook', VerifyWebhookSignature::class);


        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifySetupCommand::class,
            ]);
        }
    }
}
