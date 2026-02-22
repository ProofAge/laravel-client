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
}
