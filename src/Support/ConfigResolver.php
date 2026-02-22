<?php

namespace ProofAge\Laravel\Support;

class ConfigResolver
{
    /**
     * Resolve a full config array for the given prefix.
     *
     * Per-workspace keys (api_key, secret_key) are read directly from the prefix.
     * Shared keys (base_url, version, timeout, etc.) fall back to the default
     * proofage.* config when absent under the custom prefix.
     */
    public static function resolve(string $configPrefix = 'proofage'): array
    {
        return [
            'api_key' => config("{$configPrefix}.api_key"),
            'secret_key' => config("{$configPrefix}.secret_key"),
            'base_url' => config("{$configPrefix}.base_url") ?? config('proofage.base_url'),
            'version' => config("{$configPrefix}.version") ?? config('proofage.version'),
            'timeout' => config("{$configPrefix}.timeout") ?? config('proofage.timeout'),
            'retry_attempts' => config("{$configPrefix}.retry_attempts") ?? config('proofage.retry_attempts'),
            'retry_delay' => config("{$configPrefix}.retry_delay") ?? config('proofage.retry_delay'),
            'webhook_tolerance' => config("{$configPrefix}.webhook_tolerance") ?? config('proofage.webhook_tolerance') ?? 300,
        ];
    }
}
