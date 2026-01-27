# ProofAge Laravel Client - Installation Guide

## Requirements

- PHP 8.1 or higher
- Laravel 10.0, 11.0, or 12.0
- Composer

## Installation Steps

### 1. Install via Composer

```bash
composer require proofage/laravel-client
```

### 2. Publish Configuration (Optional)

The package will automatically register its service provider. If you want to customize the configuration:

```bash
php artisan vendor:publish --provider="ProofAge\Laravel\ProofAgeServiceProvider" --tag="config"
```

### 3. Environment Configuration

Add the following environment variables to your `.env` file:

```env
PROOFAGE_API_KEY=your-api-key-here
PROOFAGE_SECRET_KEY=your-secret-key-here
```

### 4. Verify Installation

Create a simple test to verify the installation:

```php
<?php

use ProofAge\Laravel\Facades\ProofAge;

try {
    $workspace = ProofAge::workspace()->get();
    echo "Connected successfully! Workspace: " . $workspace['name'];
} catch (\Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
```

## Configuration Options

The package supports the following configuration options in `config/proofage.php`:

```php
return [
    'api_key' => env('PROOFAGE_API_KEY'),
    'secret_key' => env('PROOFAGE_SECRET_KEY'),
    'base_url' => env('PROOFAGE_BASE_URL', 'https://api.proofage.xyz'),
    'version' => env('PROOFAGE_VERSION', 'v1'),
    'timeout' => env('PROOFAGE_TIMEOUT', 30),
    'retry_attempts' => env('PROOFAGE_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('PROOFAGE_RETRY_DELAY', 1000),
];
```

## Usage Examples

### Basic Usage with Facade

```php
use ProofAge\Laravel\Facades\ProofAge;

// Get workspace information
$workspace = ProofAge::workspace()->get();

// Create verification
$verification = ProofAge::verifications()->create([
    'callback_url' => 'https://your-app.com/webhook',
    'metadata' => ['user_id' => 123]
]);
```

### Direct Client Usage

```php
use ProofAge\Laravel\ProofAgeClient;

$client = app(ProofAgeClient::class);
$workspace = $client->workspace()->get();
```

### Dependency Injection

```php
use ProofAge\Laravel\ProofAgeClient;

class VerificationService
{
    public function __construct(
        private ProofAgeClient $proofAge
    ) {}

    public function createVerification(array $data)
    {
        return $this->proofAge->verifications()->create($data);
    }
}
```

## Error Handling

The client throws specific exceptions for different error types:

```php
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ValidationException;

try {
    $verification = ProofAge::verifications()->create($data);
} catch (AuthenticationException $e) {
    // Handle authentication errors (401)
    logger()->error('ProofAge authentication failed', [
        'error_code' => $e->getErrorCode(),
        'message' => $e->getMessage()
    ]);
} catch (ValidationException $e) {
    // Handle validation errors (422)
    return response()->json([
        'errors' => $e->getErrors()
    ], 422);
} catch (ProofAgeException $e) {
    // Handle other API errors
    logger()->error('ProofAge API error', [
        'status' => $e->getCode(),
        'message' => $e->getMessage()
    ]);
}
```

## Testing

To run the package tests:

```bash
composer test
```

Or if you've cloned the repository:

```bash
./vendor/bin/phpunit
```

## Troubleshooting

### Common Issues

1. **Authentication Errors**: Verify your API key and secret key are correct
2. **HMAC Signature Issues**: Ensure your secret key matches the one configured in your ProofAge workspace
3. **Network Timeouts**: Increase the timeout value in configuration
4. **SSL Issues**: Ensure your server can make HTTPS requests to the ProofAge API

### Debug Mode

Enable debug logging by setting `LOG_LEVEL=debug` in your `.env` file to see detailed HTTP request/response information.

## Support

For support and questions:
- Email: support@proofage.xyz
- Documentation: https://docs.proofage.xyz
- GitHub Issues: https://github.com/proofage/laravel-client/issues
