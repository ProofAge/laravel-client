# ProofAge Laravel Client

A Laravel package for integrating with the ProofAge API, featuring automatic HMAC authentication and a fluent interface.

## Installation

Install the package via Composer:

```bash
composer require proofage/laravel-client
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="ProofAge\Laravel\ProofAgeServiceProvider" --tag="config"
```

Configure your environment variables:

```env
PROOFAGE_API_KEY=your-api-key
PROOFAGE_SECRET_KEY=your-secret-key
PROOFAGE_BASE_URL=https://api.proofage.xyz
PROOFAGE_VERSION=v1
```

## Setup Verification

After configuration, verify your setup using the built-in command:

```bash
php artisan proofage:verify-setup
```

### Successful Setup Output

When everything is configured correctly, you should see:

```
✅ Configuration is valid
✅ Workspace connection successful
✅ Webhook URL is configured https://yoursite.com/webhooks/proof-age
✅ Webhook route found: POST webhooks/proof-age -> App\Http\Controllers\WebhookController@handleProofAgeWebhook
✅ Webhook route is protected with VerifyWebhookSignature middleware
✅ ProofAge setup verified successfully!
```

### What the Command Checks

The verification command ensures:

1. **Configuration** - API keys and base URL are properly set
2. **Workspace Connection** - Can successfully connect to ProofAge API
3. **Webhook URL** - Webhook endpoint is configured in your workspace
4. **Route Existence** - Laravel route exists for the webhook path
5. **HTTP Method** - Route accepts POST requests
6. **Security Middleware** - Route is protected with HMAC signature verification

### Troubleshooting

If you see errors about missing middleware, add it to your webhook route:

```php
Route::post('/webhooks/proof-age', [WebhookController::class, 'handleProofAgeWebhook'])
    ->middleware('proofage.verify_webhook');
```

## Usage

### Basic Usage

```php
use ProofAge\Laravel\Facades\ProofAge;

// Get workspace information
$workspace = ProofAge::workspace()->get();

// Create a verification
$verification = ProofAge::verifications()->create([
    'callback_url' => 'https://your-app.com/webhook',
    'metadata' => ['user_id' => 123]
]);

// Get verification details
$verification = ProofAge::verifications()->find('verification-id');

// Accept consent for verification
ProofAge::verifications('verification-id')->acceptConsent([
    'consent_version_id' => 1,
    'text_sha256' => 'hash-value'
]);

// Upload media
ProofAge::verifications('verification-id')->uploadMedia([
    'type' => 'selfie',
    'file' => $uploadedFile
]);

// Submit verification
ProofAge::verifications('verification-id')->submit();
```

### Using the Client Directly

```php
use ProofAge\Laravel\ProofAgeClient;

$client = new ProofAgeClient([
    'api_key' => 'your-api-key',
    'secret_key' => 'your-secret-key',
    'base_url' => 'https://api.proofage.xyz',
    'version' => 'v1'
]);

$workspace = $client->workspace()->get();
```

## Webhook Security

The package includes middleware to verify HMAC signatures on incoming webhook requests from ProofAge.

### Using the Middleware

Apply the middleware to your webhook routes:

```php
// In your routes/web.php or routes/api.php
Route::post('/proofage/webhook', [WebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook');
```

Or apply it to a route group:

```php
Route::middleware(['proofage.verify_webhook'])->group(function () {
    Route::post('/proofage/decision-webhook', [WebhookController::class, 'handleDecision']);
    Route::post('/proofage/track-webhook', [WebhookController::class, 'handleStatusChanged']);
});
```

### How It Works

The middleware:

1. Checks that `PROOFAGE_SECRET_KEY` is configured
2. Verifies the `X-HMAC-Signature` header is present
3. Generates the expected signature using the same algorithm as ProofAge
4. Compares signatures using `hash_equals()` for timing-safe comparison
5. Returns appropriate error responses for invalid requests

## API Methods

### Workspace

- `workspace()->get()` - Get workspace information
- `workspace()->getConsent()` - Get consent information

### Verifications

- `verifications()->create(array $data)` - Create a new verification
- `verifications()->find(string $id)` - Get verification by ID
- `verifications(string $id)->acceptConsent(array $data)` - Accept consent
- `verifications(string $id)->uploadMedia(array $data)` - Upload media files
- `verifications(string $id)->submit()` - Submit verification for processing

## Error Handling

The client throws specific exceptions for different error types:

```php
use ProofAge\Laravel\Exceptions\ProofAgeException;
use ProofAge\Laravel\Exceptions\AuthenticationException;
use ProofAge\Laravel\Exceptions\ValidationException;

try {
    $verification = ProofAge::verifications()->create($data);
} catch (AuthenticationException $e) {
    // Handle authentication errors
} catch (ValidationException $e) {
    // Handle validation errors
} catch (ProofAgeException $e) {
    // Handle other API errors
}
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
