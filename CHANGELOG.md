# Changelog

## 0.10.0 - 2026-10-09

### Added

- Requires `proofage/php-sdk` ^0.8.0. `ProofAge::verifications()->list()` lists verifications
  (filter by `status` or `external_id`, newest first, cursor paging),
  `ProofAge::verifications($id)->setTestOutcome()` sets an outcome in a test workspace, and
  `ProofAge::webhookSubscriptions()` creates, lists and deletes webhook subscriptions. See the PHP
  SDK's 0.8.0 changelog; `manual_moderation.performed_by` is optional in subscription deliveries.

## 0.9.7 - 2026-10-08

### Changed

- Requires `proofage/php-sdk` ^0.7.0, which adds `ProofAge\Sdk\Enums\WebhookEvent`
  (`status.updated`, `data.updated`). Webhook bodies now carry `event`: `data.updated` is sent when a
  tenant corrected document fields the reader got wrong, with the current status unchanged, the
  corrected `document` and `changed_fields`. `examples/webhook-controller.php`, `AGENTS.md` and the
  README read `event` before `status`. See the PHP SDK's 0.7.0 changelog.

## 0.9.6 - 2026-10-02

### Changed

- Requires `proofage/php-sdk` ^0.6.0. `ProofAge::verifications($id)->document()` describes
  `document.issuing_subdivision`: the state or province that issued the document as a bare code
  beside `issuing_country` (e.g. `FL` with `US`), or null. See the PHP SDK's 0.6.0 changelog.

## 0.9.5 - 2026-10-01

### Changed

- Requires `proofage/php-sdk` ^0.5.0. `ProofAge::verifications($id)->document()` describes
  `address` on identity (KYC) workspaces (the printed text as read, not parsed, possibly with
  line breaks), and decision webhooks now carry the same `document` object as `document()`,
  without media. See the PHP SDK's 0.5.0 changelog.

## 0.9.4 - 2026-09-30

### Changed

- Requires `proofage/php-sdk` ^0.4.0. `ProofAge::verifications($id)->document()` now describes
  `document.type` and `document.issuing_country` on every workspace, and six more `fields` on
  identity (KYC) workspaces (`middle_name`, `gender`, `nationality`, `place_of_birth`,
  `issue_date`, `expiry_date`). Age workspaces receive only the four base fields. See the PHP
  SDK's 0.4.0 changelog.

## 0.9.3 - 2026-09-30

### Fixed

- 0.7.0 through 0.9.2 were a fatal error on every request, artisan command and queue job of a
  Laravel 12 application on `symfony/var-dumper` below 7.4 — any whose lock file predates
  2025-10-27 or that updated only this package: `Call to undefined method
  Symfony\Component\VarDumper\Cloner\AbstractCloner::addDefaultCasters()`. The dump casters are
  registered when Composer's autoloader loads, and that method only exists from 7.4, while
  Laravel 12 allows `^7.2`. On older versions they are now appended to
  `AbstractCloner::$defaultCasters`, the public property the method writes, so `dd()` stays
  redacted there too. Laravel 13, which requires 7.4, was never affected.
- A nested multipart field goes out as `name[key]` parts built here instead of a nested
  `contents` array, which `guzzlehttp/psr7` only expands from 2.9.0 and before that rejected with
  `Invalid resource type: array`. The wire format is unchanged: it is what 2.9.0 produces and what
  the SDK's own transports send. No documented `uploadMedia()` field is an array.

### Changed

- CI installs the oldest dependencies the constraints allow in two more cells (Laravel 12 on
  PHP 8.2, Laravel 13 on PHP 8.3); every other cell installs the newest, which is how both
  failures above went unseen.

## 0.9.2 - 2026-09-29

Requires `proofage/php-sdk` ^0.3.2.

### Deprecated

- The fields only the ProofAge widget sends are deprecated in `php-sdk` 0.3.2: `fingerprint` and
  `page_url` on `create()`, the browser fields of `acceptConsent()`, and the capture fields and
  the `liveness_selfie` type of `uploadMedia()`. The API still accepts them, so nothing changes for
  code that sends them. The README and AGENTS.md no longer offer `liveness_selfie`.

## 0.9.1 - 2026-09-28

Requires `proofage/php-sdk` ^0.3.1.

### Fixed

- `getConsent()` is documented as returning `version: int`, the type the API has always sent
  (`php-sdk` 0.3.1 corrects the `@return` shape and AGENTS.md; the API's own documentation said
  string until 2026-09-28). The value your code receives is unchanged.

## 0.9.0 - 2026-09-28

Requires `proofage/php-sdk` ^0.3.0, which identifies itself on every request (`X-ProofAge-Sdk`,
`User-Agent`) and lets a wrapping package prepend its own token.

### Added

- Every request carries `X-ProofAge-Sdk: laravel/{version} php/{sdk version}` and, unless a
  middleware sets one, `User-Agent: ProofAge-Laravel/{version} ProofAge-PHP/{sdk version} (PHP {PHP_VERSION})`,
  whether it goes through the facade, the container singleton or a `ProofAgeClientFactory` client.
  Neither header is signed.
- `ProofAge\Laravel\Facades\ProofAge::VERSION`, the version those headers report, pinned to this
  changelog by a test.
- `new ProofAgeClient($config)` accepts the SDK's `sdk_tokens` and `user_agent_prefix` options; a
  package wrapping this one goes in front of `laravel/...`.

## 0.8.0 - 2026-09-27

Requires `proofage/php-sdk` ^0.2.0, which normalizes multipart fields so null and boolean values
no longer break the upload signature, stops retrying a `POST` the server may already have acted
on, reads every error body shape the API sends, knows the `documents_required` status and
bundles an OpenAPI spec pointing at `api.proofage.xyz`. See its changelog for the full list.

### Fixed

- `IlluminateHttpClient` sends a `FilePart`'s `contentType` as the part's `Content-Type`; it was
  dropped, so Guzzle guessed the type from the filename. Multipart fields are passed to Illuminate
  as pre-built parts, exactly as the SDK signed them, so a nested field value holding `name` and
  `contents` keys can no longer be mistaken for a part.
- The documentation and examples described an API that does not exist. They now match it:
  - `callback_url` is the page the person's browser returns to, not a webhook URL; webhooks go to
    the one URL set in the workspace's console settings.
  - Correlation uses `external_id` / `external_metadata`, which are echoed back; `metadata` is
    never returned.
  - `acceptConsent()` takes the `id` and `text_sha256` from `workspace()->getConsent()`.
  - `uploadMedia()` takes `type` `selfie`|`liveness_selfie`|`document`, with `side` and
    `document` for a document, images only; `document_front` / `document_back` are not inputs.
  - `uploadMedia()` and `submit()` return `null` (the API answers an empty `200`).
  - The webhook example handles the real body (`status`, `external_id`, `reason`,
    `duplicate_detected`, …, no `event_type`) and dedupes on `X-ProofAge-Webhook-Delivery-Id`;
    the routes example registers one webhook route, in `routes/api.php`, instead of invented
    decision/track/status/notification routes, and shows the CSRF exclusion `routes/web.php` needs.
  - The README's "How It Works" lists the middleware's real checks (the three headers, the
    `X-Auth-Client` match, `webhook_tolerance`, HMAC over `timestamp.rawBody`) and notes that
    webhooks are signed with the workspace's active secret key only.
  - `download_retry_attempts` and `webhook_tolerance` are documented; the claim that
    `LOG_LEVEL=debug` shows HTTP traffic (the package logs nothing) and the Laravel 10 exception
    handler section (the package requires Laravel 12 or 13) are gone.
  - `status` can be `documents_required`, which is not a `VerificationStatus` case: map it with
    `tryFrom()`.
- The facade's docblock lists every method it proxies, and the README lists `get()`,
  `downloadMedia()` and `downloadMediaTo()`.

## 0.7.0 - 2026-09-03

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
