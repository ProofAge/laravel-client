# Changelog

## 0.7.0 - Unreleased

The package is now a Laravel integration layer over [`proofage/php-sdk`](https://github.com/ProofAge/php-sdk):
the service provider, the facade, the webhook middleware, the `proofage:verify-setup` command, and a
transport over the `Http` facade. Signing, retries, the resources, the enums, the exceptions and
the webhook verifier are the SDK's. See `UPGRADE.md` for what a consumer has to do (one edit).

### Added

- `proofage/php-sdk ^0.1.2` as a dependency.
- `ProofAge\Laravel\Http\IlluminateHttpClient`, the SDK transport that sends through the `Http`
  facade, resolved at send time, so `Http::fake()` in your tests keeps intercepting every request —
  including those made by a client singleton built before the fake was registered.
- `ProofAge\Laravel\Exceptions\LaravelExceptionFactory`: the SDK client throws this package's
  exception classes, so every pre-0.7 `catch` keeps matching, and so does a `catch` on the SDK
  base `ProofAge\Sdk\Exceptions\ProofAgeException`.
- `app(\ProofAge\Sdk\Client::class)` resolves the same singleton as `app(ProofAgeClient::class)`.
- The wait between retry attempts goes through `Illuminate\Support\Sleep::usleep()`, so
  `Sleep::fake()` covers it in your tests; `ProofAgeClient::__construct()` takes the sleeper as its
  fourth argument, as the SDK's `Client` does.
- Casters for Symfony's VarDumper, registered when Composer's autoloader loads, so `dd()` and
  `dump()` of the client, a request, a webhook verifier or a caught exception show the SDK's
  redacted view (secret key `[redacted]`, API key and signature masked, bodies as size and sha256)
  instead of the real properties VarDumper reads by reflection. `ProofAgeClient::__construct()`
  marks its `$config` `#[\SensitiveParameter]`, as the SDK's does, so a constructor failure's
  trace does not carry the keys either.
- Everything the SDK client offers is available on `ProofAgeClient`: `pushMiddleware()`,
  `removeMiddleware()`, `onRequest()`, `onResponse()`, `onError()`, `transport()`.
- `ProofAge\Sdk\Exceptions\TransportException` for failures below HTTP (connection refused, DNS,
  TLS, timeout), replacing the `Illuminate\Http\Client\ConnectionException` that used to escape.
- `scripts/check-release.php` refuses to release while `proofage/php-sdk` is not resolvable from
  Packagist for the constraint in `composer.json`.

### Changed

- `ProofAgeClient` is a subclass of `ProofAge\Sdk\Client`. `new ProofAgeClient($config)`, the
  facade, the container binding, `ProofAgeClientFactory` and every resource method are unchanged.
- **Declared break:** `ProofAgeClient::makeRequest()` and `makeStreamedRequest()` return
  `ProofAge\Sdk\Http\Response` instead of `Illuminate\Http\Client\Response`; the same object
  reaches `ProofAgeException::getResponse()` and `fromResponse()`. `status()`, `body()`, `json()`
  (dot path included), `header()`, `headers()`, `successful()`, `failed()` and `ok()` carry over,
  so code that reads a response needs no change beyond two details: `header()` returns `null`
  rather than `''` for a missing header (and the first value rather than a comma-joined line), and
  `json($key)` takes no `*` wildcard. The rest of Illuminate's surface does not exist — the status
  helpers (`clientError()`, `notFound()`, …), `object()`, `collect()`, `ArrayAccess`,
  `__toString`, `throw()` and its variants, `toPsrResponse()`, `dd()` — and a test double of
  `makeRequest()` typed on the Illuminate response fails with a `TypeError`. `UPGRADE.md` lists
  every method.
- `ProofAge\Laravel\Resources\VerificationResource` and `WorkspaceResource` are one-line
  subclasses of the SDK resources; `ProofAgeClient` still returns them, so a hint on either name
  is satisfied. `VerificationResource::GENDER_FEMALE` / `GENDER_MALE` are inherited.
- `ProofAge\Laravel\Exceptions\ProofAgeException` extends the SDK base;
  `AuthenticationException`, `ValidationException` and `WebhookVerificationException` extend the
  Laravel base, as before. A `catch` on the Laravel base therefore still sees a 401 and a 422,
  and a `catch` on the SDK base sees everything. Single inheritance is why they descend from the
  Laravel base and not from the SDK's own 401/422/webhook classes — which also means a `catch` on
  `ProofAge\Sdk\Exceptions\AuthenticationException`, `ValidationException` or
  `WebhookVerificationException` never matches inside a Laravel application; `getErrors()` and
  `toArray()` come from traits shared with the SDK, so the two sides cannot drift.
- `WebhookVerificationException::render()` stays on the Laravel class and now renders the SDK's
  `toArray()`; the JSON body and status are unchanged.
- The `proofage.verify_webhook` middleware delegates the check sequence to
  `ProofAge\Sdk\Webhooks\WebhookVerifier`. Codes, messages, order and `CONFIGURATION_ERROR` (418)
  are unchanged.
- `ProofAge\Laravel\Services\WebhookSignatureVerifier` is a subclass of
  `ProofAge\Sdk\Webhooks\WebhookSignatureVerifier`.
- Behaviour inherited from the SDK (see its changelog): a multipart file path that does not exist
  throws `\InvalidArgumentException("File not found: …")` instead of being dropped silently; a
  request body `json_encode` cannot produce throws `ProofAgeException('Request body is not
  JSON-encodable')` instead of sending `false`; a query string on an endpoint is signed and sent in
  normalized form (no current endpoint takes one); multipart file contents are read once so the
  hash signed is the hash of the bytes sent; `timeout` and the retry settings must be integers
  (`timeout => 0.5` throws at construction where 0.6 passed it to Guzzle); endpoint path segments
  are percent-encoded and a `.`/`..` segment, `#`, whitespace or control character throws
  `\InvalidArgumentException`; `downloadMediaTo()` never leaves an error body or a partial file at
  the destination. `UPGRADE.md` has the details.
- `psr/http-message` is no longer a direct requirement; it arrives through the SDK.

### Deprecated

- `ProofAge\Laravel\Exceptions\ProofAgeException`, `AuthenticationException`, `ValidationException`
  and `WebhookVerificationException` as names to catch. They are still what the client and the
  middleware throw, and are removed in 1.0, when the SDK's own classes become what is thrown.
  Until then, catch the Laravel name for a specific status, or `ProofAge\Sdk\Exceptions\ProofAgeException`
  for everything; the SDK's 401, 422 and webhook classes do not match inside a Laravel application.

### Removed

- `ProofAge\Laravel\Enums\VerificationStatus`, `WebhookReason` and `BlockFaceReasonCode`. Import
  `ProofAge\Sdk\Enums\*` instead — the only edit a consumer must make.
- `resources/openapi.json`, `scripts/sync-spec.php` and the API contract tests; they live in the
  SDK, which is the source of truth for endpoint paths and request shapes.
