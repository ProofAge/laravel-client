<?php

namespace ProofAge\Laravel\Tests;

use Illuminate\Support\Facades\Http;

class VerifySetupCommandTest extends TestCase
{
    public function test_fails_when_config_missing(): void
    {
        config(['proofage.api_key' => null]);

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('Missing configuration settings')
            ->assertExitCode(1);
    }

    public function test_succeeds_with_valid_workspace_and_webhook(): void
    {
        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Test Workspace',
                'webhook_url' => 'https://myapp.com/webhooks/proofage',
            ]),
        ]);

        $this->app['router']->post('webhooks/proofage', function () {
            return 'ok';
        })->middleware('proofage.verify_webhook');

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('Configuration is valid')
            ->expectsOutputToContain('Workspace connection successful')
            ->expectsOutputToContain('Webhook route is protected')
            ->expectsOutputToContain('ProofAge setup verified successfully')
            ->assertExitCode(0);
    }

    public function test_warns_when_no_webhook_url(): void
    {
        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Test Workspace',
            ]),
        ]);

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('Configuration is valid')
            ->expectsOutputToContain('Workspace connection successful')
            ->expectsOutputToContain('Webhook URL is not configured')
            ->assertExitCode(0);
    }

    public function test_fails_when_api_call_throws(): void
    {
        Http::fake([
            'api.test.com/*' => Http::response(['error' => ['message' => 'Server Error']], 500),
        ]);

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('Configuration is valid')
            ->expectsOutputToContain('Failed to retrieve workspace data')
            ->assertExitCode(1);
    }

    public function test_warns_when_route_missing_middleware(): void
    {
        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Test Workspace',
                'webhook_url' => 'https://myapp.com/webhooks/proofage',
            ]),
        ]);

        $this->app['router']->post('webhooks/proofage', function () {
            return 'ok';
        });

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('VerifyWebhookSignature middleware')
            ->expectsOutputToContain('Webhooks are not configured')
            ->assertExitCode(0);
    }

    public function test_warns_when_webhook_route_not_found(): void
    {
        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Test Workspace',
                'webhook_url' => 'https://myapp.com/webhooks/proofage',
            ]),
        ]);

        $this->artisan('proofage:verify-setup')
            ->expectsOutputToContain('Webhook route not found')
            ->expectsOutputToContain('Webhooks are not configured')
            ->assertExitCode(0);
    }

    public function test_custom_config_prefix_succeeds(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Seller Workspace',
            ]),
        ]);

        $this->artisan('proofage:verify-setup', ['--config' => 'services.proofage_seller'])
            ->expectsOutputToContain('Configuration is valid')
            ->expectsOutputToContain('Workspace connection successful')
            ->assertExitCode(0);
    }

    public function test_custom_config_prefix_fails_when_keys_missing(): void
    {
        $this->artisan('proofage:verify-setup', ['--config' => 'services.proofage_seller'])
            ->expectsOutputToContain('Missing configuration settings')
            ->expectsOutputToContain('services.proofage_seller')
            ->assertExitCode(1);
    }

    public function test_custom_config_prefix_with_matching_middleware(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Seller Workspace',
                'webhook_url' => 'https://myapp.com/webhooks/seller',
            ]),
        ]);

        $this->app['router']->post('webhooks/seller', function () {
            return 'ok';
        })->middleware('proofage.verify_webhook:services.proofage_seller');

        $this->artisan('proofage:verify-setup', ['--config' => 'services.proofage_seller'])
            ->expectsOutputToContain('Webhook route is protected')
            ->expectsOutputToContain('ProofAge setup verified successfully')
            ->assertExitCode(0);
    }

    public function test_custom_config_prefix_warns_on_middleware_prefix_mismatch(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        Http::fake([
            'api.test.com/v1/workspace' => Http::response([
                'name' => 'Seller Workspace',
                'webhook_url' => 'https://myapp.com/webhooks/seller',
            ]),
        ]);

        $this->app['router']->post('webhooks/seller', function () {
            return 'ok';
        })->middleware('proofage.verify_webhook');

        $this->artisan('proofage:verify-setup', ['--config' => 'services.proofage_seller'])
            ->expectsOutputToContain('different config prefix')
            ->expectsOutputToContain('Webhooks are not configured')
            ->assertExitCode(0);
    }
}
