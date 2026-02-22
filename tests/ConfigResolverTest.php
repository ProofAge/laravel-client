<?php

namespace ProofAge\Laravel\Tests;

use ProofAge\Laravel\Support\ConfigResolver;

class ConfigResolverTest extends TestCase
{
    public function test_resolve_default_prefix(): void
    {
        $config = ConfigResolver::resolve();

        $this->assertSame('test-api-key', $config['api_key']);
        $this->assertSame('test-secret-key', $config['secret_key']);
        $this->assertSame('https://api.test.com', $config['base_url']);
    }

    public function test_resolve_custom_prefix_reads_own_keys(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        $config = ConfigResolver::resolve('services.proofage_seller');

        $this->assertSame('seller-api-key', $config['api_key']);
        $this->assertSame('seller-secret-key', $config['secret_key']);
    }

    public function test_shared_settings_fallback_to_default_proofage_config(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        $config = ConfigResolver::resolve('services.proofage_seller');

        $this->assertSame('https://api.test.com', $config['base_url']);
        $this->assertSame(config('proofage.version'), $config['version']);
        $this->assertSame(config('proofage.timeout'), $config['timeout']);
        $this->assertSame(config('proofage.retry_attempts'), $config['retry_attempts']);
        $this->assertSame(config('proofage.retry_delay'), $config['retry_delay']);
    }

    public function test_custom_prefix_can_override_shared_settings(): void
    {
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
            'services.proofage_seller.base_url' => 'https://custom.api.com',
            'services.proofage_seller.timeout' => 60,
        ]);

        $config = ConfigResolver::resolve('services.proofage_seller');

        $this->assertSame('https://custom.api.com', $config['base_url']);
        $this->assertSame(60, $config['timeout']);
    }

    public function test_webhook_tolerance_fallback(): void
    {
        config(['proofage.webhook_tolerance' => 120]);
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        $config = ConfigResolver::resolve('services.proofage_seller');

        $this->assertSame(120, $config['webhook_tolerance']);
    }

    public function test_webhook_tolerance_defaults_to_300(): void
    {
        config(['proofage.webhook_tolerance' => null]);
        config([
            'services.proofage_seller.api_key' => 'seller-api-key',
            'services.proofage_seller.secret_key' => 'seller-secret-key',
        ]);

        $config = ConfigResolver::resolve('services.proofage_seller');

        $this->assertSame(300, $config['webhook_tolerance']);
    }
}
