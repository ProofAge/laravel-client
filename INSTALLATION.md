# ProofAge Laravel Client - Installation Guide

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- Composer

The package depends on `proofage/php-sdk`, the framework-neutral ProofAge client; Composer installs
it alongside.

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
    'base_url' => env('PROOFAGE_BASE_URL', 'https://api.proofage.net'),
    'version' => env('PROOFAGE_VERSION', 'v1'),
    'timeout' => env('PROOFAGE_TIMEOUT', 30),
    'retry_attempts' => env('PROOFAGE_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('PROOFAGE_RETRY_DELAY', 1000), // milliseconds
    'download_retry_attempts' => env('PROOFAGE_DOWNLOAD_RETRY_ATTEMPTS', 1),
    'webhook_tolerance' => env('PROOFAGE_WEBHOOK_TOLERANCE', 300), // seconds
];
```

`download_retry_attempts` is the number of attempts for `downloadMedia()` / `downloadMediaTo()`;
1 means no in-process retry, and raising it retries connection failures only, never an HTTP
status. `webhook_tolerance` is how far `X-Timestamp` on an incoming webhook may be from the
server clock before the `proofage.verify_webhook` middleware rejects it.

## Usage Examples

### Basic Usage with Facade

```php
use ProofAge\Laravel\Facades\ProofAge;

// Get workspace information
$workspace = ProofAge::workspace()->get();

// Create verification
$verification = ProofAge::verifications()->create([
    // Where the person's browser returns after the flow: a page of your app, not a webhook
    'callback_url' => 'https://your-app.com/verification/done',
    // Echoed back in responses and webhooks, so the webhook can find the user
    'external_id' => (string) $user->id,
    'external_metadata' => ['plan' => 'pro'],
]);

// Send the person to the hosted flow
return redirect()->away($verification['url']);
```

Decisions are POSTed to the workspace's webhook URL, which is set in the workspace settings of
the ProofAge console, not per verification. See the README's Webhook Security section for the
route and its middleware. (`metadata` is also accepted, but it is stored internally and never
returned, so it cannot be used to match a webhook to a user.)

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

Inside a Laravel application the client throws `ProofAge\Laravel\Exceptions\AuthenticationException`
for a 401, `ValidationException` for a 422 and `ProofAgeException` for every other non-2xx. Catch
the Laravel name for a specific status, and `ProofAge\Sdk\Exceptions\ProofAgeException` — the base
class of all of them — for everything; it also catches `ProofAge\Sdk\Exceptions\TransportException`,
a network failure. (The SDK's own `AuthenticationException` and `ValidationException` are not
parents of the Laravel classes, so a `catch` on those two names never matches here; see the README's
Error Handling section.)

```php
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ValidationException;
use ProofAge\Sdk\Exceptions\ProofAgeException;

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

### Seeing requests and responses

The package logs nothing by itself. To log each call, register an event listener on the client
(`onResponse()`, `onError()`; see the README's Middleware and events section) — the API key and
the signature are masked in what they receive.

### Webhooks rejected

- A 419 means the route is in `routes/web.php` and CSRF verification rejected it: move it to
  `routes/api.php` or exclude its path (README, Webhook Security).
- `INVALID_SIGNATURE` usually means `PROOFAGE_SECRET_KEY` is not the workspace's **active** secret
  key: webhooks are signed with the active one only, while API calls accept any of its keys.
- `TIMESTAMP_TOO_OLD` means the server clock is more than `webhook_tolerance` seconds off.

## Support

For support and questions:
- Email: support@proofage.xyz
- Documentation: https://docs.proofage.net
- GitHub Issues: https://github.com/proofage/laravel-client/issues
