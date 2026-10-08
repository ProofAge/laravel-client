# ProofAge Laravel Client — contract for agents

This package is the Laravel integration layer over `proofage/php-sdk`. **The API contract lives in
the SDK**: every endpoint with its request and response shape, the HMAC canonical forms, the enums,
and the outbound webhook body are in `vendor/proofage/php-sdk/AGENTS.md`, with the machine-readable
spec at `vendor/proofage/php-sdk/resources/openapi.json` and the `@param`/`@return` PHPDoc on
`ProofAge\Sdk\Resources\*`. Nothing about that contract is duplicated here.

Methods on `ProofAge::workspace()` and `ProofAge::verifications($id)` return decoded JSON as
`array|null`; they are the SDK resources under this package's names. The API never wraps a body
in `data`. `uploadMedia()` and `submit()` return `null` (the API answers an empty `200`),
`blockFace()` returns `null` (`204`), `downloadMedia()` a PSR-7 stream.

## Integration facts that are easy to get wrong

- `callback_url` on `create()` is where the person's **browser** is sent after the flow (echoed as
  `redirect_url`), not a webhook URL. Correlate with `external_id` / `external_metadata`, which
  come back in every response and webhook; `metadata` is stored but never returned.
- `acceptConsent()` takes exactly the `id` and `text_sha256` of `ProofAge::workspace()->getConsent()`
  (the active version); any other pair is rejected.
- `uploadMedia()`: `type` is `selfie` or `document`; a document also needs
  `side` (`front`|`back`) and `document` (`id`|`driver_license`|`passport`|`residence_permit`).
  Images only. `document_front` / `document_back` are media types in `document()`'s output, not
  inputs.
- `status` may be `documents_required` (`VerificationStatus::DOCUMENTS_REQUIRED`); map it with `tryFrom()` so a future status does not throw.
- Webhooks: one URL per workspace, set in the ProofAge console, not per verification. One body
  shape (`verification_id`, `event`, `status`, `external_id`, `external_metadata`, `reason`, `timestamp`,
  `document`, plus `duplicate_*`, `fingerprint_signals`, `manual_moderation` when present). Read
  `event` first: `status.updated` (or absent, a retry of an older delivery) is a status change;
  `data.updated` is a tenant's correction of document fields, with the current unchanged `status`, the
  corrected `document` and `changed_fields` (names only) — not a decision, so do not run the status
  handling on it. There is no `event_type`. `X-ProofAge-Webhook-Delivery-Id` is stable across retries of a delivery. The route
  needs no CSRF check: put it in `routes/api.php`, or exclude it in `validateCsrfTokens(except:)`.
  Webhooks are signed with the workspace's **active** secret key only.

## What this package adds

- **Service provider** (auto-discovered): binds `ProofAge\Laravel\ProofAgeClient` as a singleton,
  also reachable as `app('proofage')` and `app(\ProofAge\Sdk\Client::class)`; publishes
  `config/proofage.php` (tag `config`); registers the middleware alias and the artisan command.
- **Facade** `ProofAge\Laravel\Facades\ProofAge`: `workspace(): WorkspaceResource`,
  `verifications(?string $id = null): VerificationResource` (the `ProofAge\Laravel\Resources\*`
  subclasses of the SDK resources; `verifications()->list()` lists by status or `external_id`,
  `verifications($id)->setTestOutcome()` works in test workspaces) and
  `webhookSubscriptions(): \ProofAge\Sdk\Resources\WebhookSubscriptionResource` (`create()`,
  `list()`, `delete()`).
- **Client** `ProofAge\Laravel\ProofAgeClient extends ProofAge\Sdk\Client`: transport defaults to
  `ProofAge\Laravel\Http\IlluminateHttpClient` (sends through the `Http` facade, so `Http::fake()`
  intercepts; no retry or throw of its own) and exceptions default to
  `ProofAge\Laravel\Exceptions\LaravelExceptionFactory`. `makeRequest()` / `makeStreamedRequest()`
  return `ProofAge\Sdk\Http\Response`.
- **SDK identification**: every request sends `X-ProofAge-Sdk: laravel/{ProofAge::VERSION} php/{SDK version}`
  and, unless a middleware sets one, `User-Agent: ProofAge-Laravel/{v} ProofAge-PHP/{v} (PHP {PHP_VERSION})`;
  a wrapper passes `sdk_tokens` / `user_agent_prefix` to `new ProofAgeClient($config)` to go first.
- **Multiple workspaces**: `app(ProofAgeClientFactory::class)->make('services.proofage_seller')`
  reads `api_key`/`secret_key` under that prefix; `base_url`, `version`, `timeout`,
  `retry_attempts`, `retry_delay`, `download_retry_attempts`, `webhook_tolerance` fall back to
  `proofage.*` (`ProofAge\Laravel\Support\ConfigResolver`).
- **Config keys / env**: `api_key` (`PROOFAGE_API_KEY`), `secret_key` (`PROOFAGE_SECRET_KEY`),
  `base_url` (`PROOFAGE_BASE_URL`, default `https://api.proofage.xyz`), `version`
  (`PROOFAGE_VERSION`, `v1`), `timeout` (`PROOFAGE_TIMEOUT`, 30), `retry_attempts`
  (`PROOFAGE_RETRY_ATTEMPTS`, 3), `retry_delay` (`PROOFAGE_RETRY_DELAY`, 1000 ms),
  `download_retry_attempts` (`PROOFAGE_DOWNLOAD_RETRY_ATTEMPTS`, 1), `webhook_tolerance`
  (`PROOFAGE_WEBHOOK_TOLERANCE`, 300 s).
- **Webhook middleware** alias `proofage.verify_webhook` (or `proofage.verify_webhook:{prefix}`):
  `ProofAge\Laravel\Middleware\VerifyWebhookSignature`. Reads `X-HMAC-Signature`, `X-Timestamp`,
  `X-Auth-Client`; throws `ProofAge\Laravel\Exceptions\WebhookVerificationException` with, in this
  order, `MISSING_SIGNATURE`, `MISSING_TIMESTAMP`, `MISSING_AUTH_CLIENT` (401),
  `CONFIGURATION_ERROR` (418, keys missing under the prefix), then the SDK
  `WebhookVerifier` sequence `INVALID_AUTH_CLIENT`, `TIMESTAMP_TOO_OLD`, `INVALID_SIGNATURE` (401).
  Unhandled, it renders `{ "error": { "code", "message" } }` with that status.
- **Artisan** `proofage:verify-setup [--config=prefix]`: checks config, calls `GET /workspace`,
  checks that the workspace's `webhook_url` has a POST route protected by the middleware with the
  matching prefix. Exit 0 on success (warns when webhooks are not configured), 1 on failure.
- **Exceptions** thrown by the client: `ProofAge\Laravel\Exceptions\AuthenticationException` (401),
  `ValidationException` (422, `getErrors()`), `ProofAgeException` (other statuses, configuration);
  by the middleware: `WebhookVerificationException`. All four descend from the Laravel
  `ProofAgeException`, which descends from `ProofAge\Sdk\Exceptions\ProofAgeException` — the
  catch-all, and the only one that also catches `ProofAge\Sdk\Exceptions\TransportException`
  (network failures). None of them is an instance of the SDK's own `AuthenticationException`,
  `ValidationException` or `WebhookVerificationException`, so a `catch` on those three SDK names
  never matches inside a Laravel application: catch the Laravel name for a specific status, or the
  SDK base for everything. The four Laravel classes are deprecated names, removed in 1.0.
- **Dump redaction**: `ProofAge\Laravel\Support\DumpCasters` registers VarDumper casters when the
  autoloader loads (composer `files`), so `dd()` / `dump()` of the client, an SDK `Request`, a body
  part, a webhook verifier, or an exception carrying any of them show the SDK's `__debugInfo()`
  view: secret key `[redacted]`, API key and signature masked, bodies as size and sha256.
- **Enums**: `ProofAge\Sdk\Enums\VerificationStatus`, `WebhookReason`, `BlockFaceReasonCode`.
  There is no `ProofAge\Laravel\Enums\*` since 0.7.0.

## Keeping this in sync

Endpoint or shape changes are made in the SDK first (`composer run sync-spec` and
`tests/ApiContractTest.php` there). This package only follows the SDK version constraint in
`composer.json`; see `CLAUDE.md` for the release rules.
