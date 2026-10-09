# ProofAge Laravel Client — Age Verification for Laravel

**Platform:** https://proofage.xyz | **Packagist:** https://packagist.org/packages/proofage/laravel-client

A Laravel package for integrating with the ProofAge API, featuring automatic HMAC authentication and a fluent interface.

Full API reference: https://docs.proofage.net/api-reference

## About ProofAge

ProofAge is an online age verification platform enabling websites to confirm users meet minimum age requirements through a hosted, privacy-focused KYC process — without server-side document handling. It supports alcohol/tobacco/cannabis commerce, adult content platforms, gambling sites, and age-restricted subscriptions.

This package provides a first-class Laravel integration: a service provider with auto-discovery, a facade, HMAC-signed webhook middleware, and a setup verification command.

It is built on [`proofage/php-sdk`](https://github.com/ProofAge/php-sdk), the framework-neutral ProofAge client, which does the request signing, the retries and the resource calls. This package adds the Laravel wiring and sends every request through the `Http` facade, so `Http::fake()` intercepts the client in your tests. Upgrading from 0.6? See [`UPGRADE.md`](UPGRADE.md).

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
PROOFAGE_BASE_URL=https://api.proofage.net
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
✅ Webhook URL is configured https://yoursite.com/api/webhooks/proofage
✅ Webhook route found: POST api/webhooks/proofage -> App\Http\Controllers\ProofAgeWebhookController@handle
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
Route::post('/webhooks/proofage', [ProofAgeWebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook');
```

## Usage

### Basic Usage

```php
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\Resources\VerificationResource;

// Get workspace information
$workspace = ProofAge::workspace()->get();

// Create a verification
$verification = ProofAge::verifications()->create([
    // Where the person's browser is sent after the flow: a page of your app, not a webhook.
    // Decisions are POSTed to the workspace's webhook URL, set in the ProofAge console
    // (see Webhook Security below).
    'callback_url' => 'https://your-app.com/verification/done',
    // Your identifiers, echoed back in every response and webhook. `metadata` is also accepted,
    // but it is stored internally and never returned, so it cannot be used for correlation.
    'external_id' => (string) $user->id,
    'external_metadata' => ['plan' => 'pro'],
]);

// Send the person to the hosted verification flow at $verification['url'],
// e.g. redirect()->away($verification['url']); the decision arrives by webhook.

// Get verification details
$verification = ProofAge::verifications()->find('verification-id');

// Get age estimation details
$estimation = ProofAge::verifications('verification-id')->estimation();
// [
//     'verification_id' => '...',
//     'attempt_id' => '...',
//     'age_threshold' => [
//         'minimum' => 18,
//         'passed' => true,
//         'confidence' => 0.98,
//     ],
//     'gender' => [
//         'value' => VerificationResource::GENDER_FEMALE, // 0 = female, 1 = male
//         'confidence' => 0.93,
//     ],
// ]
```

If you capture the images yourself instead of using the hosted flow:

```php
// Accept consent: the id and text_sha256 must be exactly those of the active consent version,
// whose text (at $consent['url']) is what the person was shown. Anything else is rejected.
$consent = ProofAge::workspace()->getConsent();

ProofAge::verifications('verification-id')->acceptConsent([
    'consent_version_id' => $consent['id'],
    'text_sha256' => $consent['text_sha256'],
]);

// Upload media: images only. `type` is selfie or document; a document also
// needs `side` (front|back) and `document` (id|driver_license|passport|residence_permit).
ProofAge::verifications('verification-id')->uploadMedia([
    'type' => 'selfie',
    'file' => $request->file('selfie'),
]);

ProofAge::verifications('verification-id')->uploadMedia([
    'type' => 'document',
    'side' => 'front',
    'document' => 'passport',
    'file' => $request->file('passport_front'),
]);

// Submit verification; the decision arrives by webhook
ProofAge::verifications('verification-id')->submit();
```

`uploadMedia()` and `submit()` return `null`: the API answers both with an empty `200`. A failure
throws (see Error Handling).

### Using the Client Directly

```php
use ProofAge\Laravel\ProofAgeClient;

$client = app(ProofAgeClient::class);   // app(\ProofAge\Sdk\Client::class) resolves the same singleton
$workspace = $client->workspace()->get();

// Lower level: makeRequest() returns a ProofAge\Sdk\Http\Response
$response = $client->makeRequest('GET', 'workspace');
$response->status();
$response->json();
```

### Middleware and events

`ProofAgeClient` is the SDK client, so its middleware and events are available: a middleware runs once per HTTP attempt, before signing; events observe the signed request and the response, with the API key and signature masked.

```php
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ProofAge\Laravel\ProofAgeClient;
use ProofAge\Sdk\Events\ResponseEvent;
use ProofAge\Sdk\Http\Request;
use ProofAge\Sdk\Http\Response;

$client = app(ProofAgeClient::class);

$client->pushMiddleware(fn (Request $request, callable $next): Response => $next(
    $request->withHeader('X-Request-Id', (string) Str::uuid())
));

$client->onResponse(fn (ResponseEvent $e) => Log::info('proofage.response', [
    'status' => $e->status(),
    'attempt' => $e->attempt(),
    'ms' => $e->durationMs(),
]));
```

See the SDK's README for the full middleware and event API and for what `raw()` on an event exposes.

### SDK identification

Every request carries `X-ProofAge-Sdk: laravel/{ProofAge::VERSION} php/{SDK version}` (for example
`laravel/0.9.0 php/0.3.0`) and, unless a middleware sets its own, `User-Agent: ProofAge-Laravel/0.9.0
ProofAge-PHP/0.3.0 (PHP 8.4.1)`. Neither is signed, and no middleware can remove this package's or
the SDK's token. A package that wraps this one puts its own first by passing the SDK options
`sdk_tokens` (`['acme-shop/2.1.0']`) and `user_agent_prefix` (`'AcmeShop/2.1.0'`) to
`new ProofAgeClient($config)`.

### Secrets in dumps

`dd()`, `dump()`, `print_r()` and `var_dump()` of the client, of a request or of a caught exception
show the SDK's redacted view: the secret key as `[redacted]`, the API key and the HMAC signature
masked, a request body as its size and sha256 rather than its bytes. The SDK covers `print_r()` and
`var_dump()` itself through `__debugInfo()`; this package registers casters with Symfony's
VarDumper — what Laravel's `dd()` and `dump()` use, and which otherwise reads the real properties by
reflection — for the same classes when Composer's autoloader loads. `var_export()` and reflection
are not covered.

## Webhook Security

The package includes middleware to verify HMAC signatures on incoming webhook requests from ProofAge.

A workspace has exactly one webhook URL, set in its settings in the ProofAge console, and ProofAge
POSTs every decision to it: one route per workspace.

### Using the Middleware

Register the route in `routes/api.php`. Its routes carry no CSRF check — ProofAge's POST has no
CSRF token — and are prefixed with `/api`, so the URL to enter in the console is
`https://your-app.com/api/webhooks/proofage`. (No `routes/api.php` yet? `php artisan install:api`
creates it.)

```php
// routes/api.php
Route::post('/webhooks/proofage', [ProofAgeWebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook');
```

In `routes/web.php` instead, exclude the path from CSRF verification in `bootstrap/app.php`, or
every webhook is rejected with a 419 before the middleware runs:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webhooks/proofage']);
})
```

### The webhook body

Sent when a verification's status becomes `approved`, `declined`, `resubmission_requested`,
`review`, `abandoned` or `expired`:

```json
{
    "verification_id": "019d...",
    "event": "status.updated",
    "status": "declined",
    "external_id": "123",
    "external_metadata": {"plan": "pro"},
    "reason": "document.face.mismatch",
    "timestamp": "2026-09-27T12:00:00+00:00"
}
```

`reason` is set on `declined` and `resubmission_requested` only. `duplicate_detected`,
`duplicate_count` and `duplicate_of` (`verification_id`, `external_id`) are added when the face
matched another account, and `fingerprint_signals` and `manual_moderation` when they apply. Each
delivery carries an `X-ProofAge-Webhook-Delivery-Id` header that stays the same across retries of
that delivery: use it to process a delivery once. Every body also has `event`: `status.updated` for a
decision, `data.updated` when a tenant corrected document fields the reader got wrong (`status` is then the
current one, unchanged, `document` holds the corrected values and `changed_fields` names what changed).
Read `event` before `status`; a body without it is `status.updated`. The repository's
`examples/webhook-controller.php` handles `data.updated` first and then each status; the SDK's `AGENTS.md` has the full body.

### How It Works

The middleware (`ProofAge\Laravel\Middleware\VerifyWebhookSignature`), in this order:

1. Requires the `X-HMAC-Signature`, `X-Timestamp` and `X-Auth-Client` headers (401
   `MISSING_SIGNATURE`, `MISSING_TIMESTAMP`, `MISSING_AUTH_CLIENT`).
2. Reads `api_key` and `secret_key` under its config prefix (`proofage` by default, or the one
   named as `proofage.verify_webhook:{prefix}`); either missing is a 418 `CONFIGURATION_ERROR`.
3. Checks that `X-Auth-Client` equals the configured `api_key` (401 `INVALID_AUTH_CLIENT`).
4. Rejects an `X-Timestamp` more than `webhook_tolerance` seconds (default 300) from now (401
   `TIMESTAMP_TOO_OLD`).
5. Computes `hex(hmac_sha256(X-Timestamp . '.' . rawBody, secret_key))` and compares it with
   `X-HMAC-Signature` using `hash_equals()`; if that fails, it retries once over the body
   re-encoded as ProofAge encodes JSON, for a proxy that re-serialised it (401 `INVALID_SIGNATURE`).

ProofAge signs webhooks with the workspace's **active** secret key only (API calls accept any of
its keys), so `secret_key` must be the active one.

## Multiple Workspaces

Some applications need separate verification flows for different user roles. For example, a marketplace where buyers go through a basic age check while sellers require full identity verification -- each with its own ProofAge workspace, credentials, and webhook endpoint.

The package supports this out of the box. All shared settings (`base_url`, `version`, `timeout`, etc.) are inherited from the default `proofage` config, so additional workspaces only need their own `api_key` and `secret_key`.

### Example: marketplace with buyer and seller verification

#### 1. Configure credentials for each workspace

The default workspace (buyers) is configured via `config/proofage.php` as usual. For sellers, add a second set of credentials anywhere in your application config -- `config/services.php` is a common choice:

```php
// config/services.php
'proofage_seller' => [
    'api_key' => env('PROOFAGE_SELLER_API_KEY'),
    'secret_key' => env('PROOFAGE_SELLER_SECRET_KEY'),
],
```

```env
# .env
# Buyer workspace (default)
PROOFAGE_API_KEY=pk_live_...
PROOFAGE_SECRET_KEY=sk_live_...

# Seller workspace
PROOFAGE_SELLER_API_KEY=pk_live_...
PROOFAGE_SELLER_SECRET_KEY=sk_live_...
```

#### 2. Create verifications with the correct client

The `ProofAge` facade and `app(ProofAgeClient::class)` singleton always use the default (buyer) workspace. For the seller workspace, use `ProofAgeClientFactory`:

```php
use ProofAge\Laravel\Facades\ProofAge;
use ProofAge\Laravel\ProofAgeClientFactory;

// Buyer verification -- uses default proofage.* config
$buyerVerification = ProofAge::verifications()->create([
    'callback_url' => 'https://marketplace.com/buyer/verification/done', // browser return page
    'external_id' => (string) $buyer->id,
]);

// Seller verification -- uses services.proofage_seller config
$sellerClient = app(ProofAgeClientFactory::class)->make('services.proofage_seller');
$sellerVerification = $sellerClient->verifications()->create([
    'callback_url' => 'https://marketplace.com/seller/verification/done', // browser return page
    'external_id' => (string) $seller->id,
]);
```

#### 3. Set up separate webhook routes

Each workspace sends webhooks to the webhook URL in its own console settings, signed with its own active secret key. Use the middleware's config prefix parameter to verify signatures with the correct credentials:

```php
// routes/api.php

// Buyer webhooks -- verified with default proofage.* keys
Route::post('/webhooks/proofage', [BuyerWebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook');

// Seller webhooks -- verified with services.proofage_seller keys
Route::post('/webhooks/proofage-seller', [SellerWebhookController::class, 'handle'])
    ->middleware('proofage.verify_webhook:services.proofage_seller');
```

#### 4. Verify setup for each workspace

```bash
# Check the buyer (default) workspace
php artisan proofage:verify-setup

# Check the seller workspace
php artisan proofage:verify-setup --config=services.proofage_seller
```

The command checks configuration, API connectivity, webhook route existence, and that the middleware uses the matching config prefix -- so you'll be warned if the keys would mismatch.

### Config resolution

When a custom config prefix is used, the following resolution rules apply:

| Key | Resolution |
|-----|-----------|
| `api_key` | Read from the specified prefix (required) |
| `secret_key` | Read from the specified prefix (required) |
| `base_url` | Specified prefix, falls back to `proofage.base_url` |
| `version` | Specified prefix, falls back to `proofage.version` |
| `timeout` | Specified prefix, falls back to `proofage.timeout` |
| `retry_attempts` | Specified prefix, falls back to `proofage.retry_attempts` |
| `retry_delay` | Specified prefix, falls back to `proofage.retry_delay` |
| `download_retry_attempts` | Specified prefix, falls back to `proofage.download_retry_attempts` |
| `webhook_tolerance` | Specified prefix, falls back to `proofage.webhook_tolerance` (default: 300s) |

Additional workspaces only need `api_key` and `secret_key`. If a workspace connects to a different ProofAge environment (e.g. staging), add `base_url` under the same prefix and it will take priority over the default.

## API Methods

### Workspace

- `workspace()->get()` - Get workspace information
- `workspace()->getConsent()` - Get consent information

### Verifications

- `verifications()->create(array $data)` - Create a new verification
- `verifications()->find(string $id)` - Get verification by ID
- `verifications(string $id)->get()` - Get the verification the resource was built for
- `verifications(string $id)->acceptConsent(array $data)` - Accept consent
- `verifications(string $id)->uploadMedia(array $data)` - Upload a media file (returns `null`)
- `verifications(string $id)->submit()` - Submit verification for processing (returns `null`)
- `verifications(string $id)->document()` - Get sanitized document fields and source media
- `verifications(string $id)->downloadMedia(string $mediaId)` - Download one media file as a PSR-7 stream
- `verifications(string $id)->downloadMediaTo(string $mediaId, string $path)` - Download one media file straight to disk
- `verifications(string $id)->estimation()` - Get age-threshold and gender estimation
- `verifications(string $id)->blockFace(?array $data)` - Block the verification face for AML

Every method's exact request and response shape is documented in the SDK: its `AGENTS.md`
(`vendor/proofage/php-sdk/AGENTS.md`), the `@param`/`@return` PHPDoc on `ProofAge\Sdk\Resources\*`,
and the bundled `vendor/proofage/php-sdk/resources/openapi.json`.

### Enums

`ProofAge\Sdk\Enums\VerificationStatus`, `ProofAge\Sdk\Enums\WebhookReason` and
`ProofAge\Sdk\Enums\BlockFaceReasonCode` model the `status`, AML `reason` and `reason_code` values.
(`ProofAge\Laravel\Enums\*` was removed in 0.7.0.)

A verification's `status` can also be `documents_required`, taken from its latest attempt
(`VerificationStatus::DOCUMENTS_REQUIRED` since `proofage/php-sdk` 0.2.0). Map `status` with
`VerificationStatus::tryFrom()` rather than `from()` and handle `null`, so a status added later
does not throw. `WebhookReason` covers only the AML blocklist codes; treat `reason` as
an open string.

## Error Handling

Inside a Laravel application, catch the Laravel name for a specific status and the SDK base class
for everything:

```php
use ProofAge\Laravel\Exceptions\AuthenticationException;   // 401
use ProofAge\Laravel\Exceptions\ValidationException;       // 422, getErrors()
use ProofAge\Sdk\Exceptions\TransportException;            // connection refused, DNS, TLS, timeout
use ProofAge\Sdk\Exceptions\ProofAgeException;             // every other non-2xx, and the base class of all of the above

try {
    $verification = ProofAge::verifications()->create($data);
} catch (AuthenticationException $e) {
    // 401: $e->getErrorCode()
} catch (ValidationException $e) {
    // 422: $e->getErrors()
} catch (TransportException $e) {
    // The API could not be reached; $e->getResponse() is null
} catch (ProofAgeException $e) {
    // Everything else: $e->getCode() is the HTTP status, $e->getResponse() the ProofAge\Sdk\Http\Response
}
```

The client throws `ProofAge\Laravel\Exceptions\AuthenticationException` for a 401,
`ValidationException` for a 422 and `ProofAgeException` for every other non-2xx; the webhook
middleware throws `WebhookVerificationException`. All four descend from
`ProofAge\Laravel\Exceptions\ProofAgeException`, which descends from
`ProofAge\Sdk\Exceptions\ProofAgeException` — the catch-all, and the only one of the two bases that
also catches `TransportException`.

They do **not** descend from the SDK's own `ProofAge\Sdk\Exceptions\AuthenticationException`,
`ValidationException` or `WebhookVerificationException`: PHP allows one parent, and keeping the
pre-0.7 `catch (ProofAge\Laravel\Exceptions\ProofAgeException)` working won. A `catch` on one of
those three SDK names therefore never matches inside a Laravel application — a 422 would fall
through to whatever comes next. The Laravel names are deprecated in 0.7 and removed in 1.0, when
the SDK names become what is thrown; see `UPGRADE.md`.

### Webhook Exception Handling

The webhook middleware throws `ProofAge\Laravel\Exceptions\WebhookVerificationException` on invalid requests. It descends from `ProofAge\Laravel\Exceptions\ProofAgeException` (not from the SDK's `WebhookVerificationException`; see Error Handling above) and carries `errorCode`, `statusCode` and `toArray()`. By default, the exception renders a JSON error response:

```json
{
    "error": {
        "code": "INVALID_SIGNATURE",
        "message": "HMAC signature is invalid"
    }
}
```

To customize this response, register a renderable in `bootstrap/app.php`:

```php
use ProofAge\Laravel\Exceptions\WebhookVerificationException;

->withExceptions(function (Exceptions $exceptions) {
    $exceptions->renderable(function (WebhookVerificationException $e) {
        return response()->json([
            'error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ],
        ], $e->statusCode);
    });
})
```

## Testing

```bash
composer test
```

In your own application's tests, `Http::fake()` intercepts every request the client makes, including
multipart uploads (`$request->hasFile('file')`) and the signed headers (`$request->header('X-HMAC-Signature')`):

```php
Http::fake(['api.proofage.net/v1/workspace' => Http::response(['id' => 'ws_1', 'name' => 'Acme'])]);

ProofAge::workspace()->get();

Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key'));
```

If you would rather not go through the facade, `ProofAge\Sdk\Testing\FakeHttpClient` is a transport
double the SDK ships: `new ProofAgeClient($config, $fake)`.

## Additional Resources

- **Platform:** https://proofage.xyz
- **Live Demo:** https://demo.proofage.net
- **Node SDK:** `@proofage/node` on npm

### Integrations for other platforms

| Platform | Repository | Use-case |
|---|---|---|
| **Node.js** | [ProofAge/node-client](https://github.com/ProofAge/node-client) | Node.js age verification client — HMAC-signed API calls, webhook verification for Express, Hono, Next.js and other Node.js frameworks |
| **WordPress** | [ProofAge/wordpress-plugin](https://github.com/ProofAge/wordpress-plugin) | Age gate plugin for WordPress — WooCommerce age verification, age-restricted pages, adult content gating |
| **Laravel** | this repo | Laravel age verification client — HMAC-signed API calls, webhook handling, middleware for age-restricted routes |
| **Next.js** | [ProofAge/demo](https://github.com/ProofAge/demo) | Full-stack age verification demo with JS SDK, server routes, and webhook receiver |

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
