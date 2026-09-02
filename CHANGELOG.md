# Changelog

## 0.7.0 - Unreleased

The package is now a Laravel integration layer over [`proofage/php-sdk`](https://github.com/ProofAge/php-sdk):
the service provider, the facade, the webhook middleware, the `proofage:verify-setup` command, and a
transport over the `Http` facade. Signing, retries, the resources, the enums, the exceptions and
the webhook verifier are the SDK's. See `UPGRADE.md` for what a consumer has to do (one edit).

### Added

- `proofage/php-sdk ^0.1` as a dependency.
- `ProofAge\Laravel\Http\IlluminateHttpClient`, the SDK transport that sends through the `Http`
  facade, resolved at send time, so `Http::fake()` in your tests keeps intercepting every request —
  including those made by a client singleton built before the fake was registered.
- `ProofAge\Laravel\Exceptions\LaravelExceptionFactory`: the SDK client throws this package's
  exception classes, so a `catch` on either the pre-0.7 name or the SDK name matches.
- `app(\ProofAge\Sdk\Client::class)` resolves the same singleton as `app(ProofAgeClient::class)`.
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
  reaches `ProofAgeException::getResponse()` and `fromResponse()`. The SDK response mirrors
  `status()`, `body()`, `json()`, `header()`, `headers()`, `successful()`, `failed()` and `ok()`,
  so code that reads a response needs no change. Only `collect()`, `throw()`, `onError()`,
  `toPsrResponse()` and explicit type hints on the Illuminate class are affected.
- `ProofAge\Laravel\Resources\VerificationResource` and `WorkspaceResource` are one-line
  subclasses of the SDK resources; `ProofAgeClient` still returns them, so a hint on either name
  is satisfied. `VerificationResource::GENDER_FEMALE` / `GENDER_MALE` are inherited.
- `ProofAge\Laravel\Exceptions\ProofAgeException`, `AuthenticationException`,
  `ValidationException` and `WebhookVerificationException` extend their SDK counterparts.
  Consequence: `AuthenticationException` and `ValidationException` no longer extend the Laravel
  `ProofAgeException`, so a `catch` on that class **alone** does not see a 401 or a 422 any more;
  the catch-all is `ProofAge\Sdk\Exceptions\ProofAgeException`.
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
  hash signed is the hash of the bytes sent.
- `psr/http-message` is no longer a direct requirement; it arrives through the SDK.

### Deprecated

- `ProofAge\Laravel\Exceptions\ProofAgeException`, `AuthenticationException`, `ValidationException`
  and `WebhookVerificationException` as names to catch. They are still what the client and the
  middleware throw, and are removed in 1.0; catch the `ProofAge\Sdk\Exceptions\*` parents.

### Removed

- `ProofAge\Laravel\Enums\VerificationStatus`, `WebhookReason` and `BlockFaceReasonCode`. Import
  `ProofAge\Sdk\Enums\*` instead — the only edit a consumer must make.
- `resources/openapi.json`, `scripts/sync-spec.php` and the API contract tests; they live in the
  SDK, which is the source of truth for endpoint paths and request shapes.
