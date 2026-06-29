# ProofAge Laravel Client — Self-Describing, Drift-Checked API Contract

**Date:** 2026-06-29
**Status:** Approved design, pending spec review
**Scope:** `proofage/laravel-client` (Laravel-first). The Node client follows the same pattern as a later pass.

## Problem

When integrating the `proofage/laravel-client` package, a developer (or an AI agent
acting on their behalf) cannot tell from the package alone **what each method accepts
or returns**. Every resource method returns a bare `?array` (only `estimation()`
documents its shape via `@return array{...}`), so the request/response contract is
invisible without reading the external docs site *and* the main application source —
which integrators do not have.

Two secondary problems compound it:

1. **Drift between the package and the real API.** The app's own OpenAPI spec is stale
   (the `GET /verifications/{id}/estimation` endpoint is annotated in the controller but
   missing from the committed `openapi.json`), and the package has no mechanism to detect
   when its surface diverges from the API.
2. **No AI-facing contract ships with the package.** There is no `AGENTS.md` describing
   the endpoints, and the README's "API Methods" list omits methods that already exist
   in code (`document()`, `estimation()`, `blockFace()`).

## Goal & success criteria

A developer or AI agent with **only the installed package** — no app source, no external
docs — can determine, for every method:

- what it accepts: field names, types, and validation rules;
- what it returns: field names, types, nullability, and enum values;
- what errors it raises;
- the outbound webhook payload shape.

And the contract **cannot silently fall out of sync** with the real API: a divergence
must fail CI.

### Non-goals

- No breaking changes to the package's public API. Methods keep returning `array`
  (the chosen type form is PHPDoc array shapes, not DTO objects).
- Not rebuilding the app's docs site or Scramble pipeline. We consume its output.
- Node client changes are out of scope for this pass (documented as a follow-up).

## Architecture

### Single source of truth → three contract layers in the package

The app's Scramble-generated `openapi.json` (`php artisan scramble:export`, already wired
as `npm run generate:openapi` in `developer-docs/`) remains the upstream source of truth.
From it, the package carries the contract in three co-located layers:

1. **Bundled machine-readable spec.** A copy of `openapi.json` ships inside the package at
   `resources/openapi.json`. This is what makes the package *autonomous* — the full schema
   is present after `composer require`, offline-readable by any tool or agent.

2. **Code-level types.** Every method in `VerificationResource` and `WorkspaceResource`
   gets `@param array{...}` (for request bodies) and `@return array{...}` (for responses)
   PHPDoc, following the convention `estimation()` already uses. Enum values reference the
   existing `VerificationStatus` and `WebhookReason` enums where applicable. This puts the
   contract at the point of use, visible to IDEs and to agents reading the code.

3. **AI/human contract doc.** An `AGENTS.md` at the package root: concise,
   endpoint-by-endpoint (method → URL → request fields with rules → response fields with
   types/enums → errors), plus the auth/HMAC canonical-string rules and the webhook
   payload. It points to the bundled `resources/openapi.json` and the README. The existing
   `.cursor/` rules reference it so Cursor agents pick it up.

### Responses: authored shapes are the source of truth (Scramble limitation)

Decision (2026-06-29): **the package's authored `@return` array-shapes + `AGENTS.md` are the
authoritative response contract**, pinned by the package's existing HTTP-fake tests. The
bundled `openapi.json` is the source of truth for **endpoints and request bodies**, not
responses.

Reason: the app's Scramble version (`dedoc/scramble ^0.13`) does **not** honor the controllers'
`@response` PHPDoc and cannot introspect the JsonResource `toArray()` for most endpoints. The
regenerated spec types the `2xx` bodies of `create`, `acceptConsent`, `submit`, `uploadMedia`,
and `GET /consent` as `{"type":"integer","const":200}` (it captured the status code), and
`GET /verifications/{id}` as an empty `VerificationResource` (`{"type":"array"}`). Only
`GET /workspace`, `GET …/estimation`, and `GET …/document` get correct response schemas.
Fixing Scramble is explicitly out of scope (it would be uncertain app-side effort); the
authored shapes carry the response contract instead.

### The "synchronized" guarantee — a drift test

A PHPUnit contract test, `tests/ApiContractTest.php` (this package uses PHPUnit + Orchestra
Testbench, **not** Pest), loads the bundled `resources/openapi.json` and asserts:

1. **Endpoint existence** — every `(verb, path)` that an SDK resource method targets exists
   as a path/operation in the spec. Catches renamed or removed endpoints.
2. **Endpoint coverage** — every operation in the spec is reachable by some SDK method.
   Catches "the app added an endpoint the SDK is missing" (the exact `estimation` gap that
   already exists in the Node client).
3. **Request field parity** — for every operation, the request field set declared in the
   contract map equals the spec's request-body schema property set. Scramble captures these
   reliably (from FormRequests), so this covers all write endpoints.
4. **Response field parity (reliable endpoints only)** — for the operations whose spec
   response schema exposes properties (`getWorkspace`, `getVerificationEstimation`,
   `getVerificationDocument`), assert the contract map's top-level response field set equals
   the spec's. For every other operation the response shape is pinned by the package's
   existing HTTP-fake tests + the authored `@return` PHPDoc, **not** the spec. The test
   skips response parity for operations whose spec schema has no enumerable properties (and
   records which ones were skipped) so it never false-fails on Scramble's gaps.

To keep the test robust and avoid fragile free-form PHPDoc parsing, the SDK's method→
operation mapping and expected field sets are declared once in a small structured map
(`tests/Support/ApiContractMap.php`, namespace `ProofAge\Laravel\Tests\Support`). The
PHPDoc array-shapes are authored to match that map. When the bundled spec is refreshed from
the app, the test fails and names exactly which endpoint/field diverged, so the maintainer
updates the map + PHPDoc + `AGENTS.md` in lockstep.

> **Authoritative shapes.** The field shapes in the appendix below were read from the app's
> API Resources on 2026-06-29 and are the implementation target. During implementation they
> must be reconciled against the freshly regenerated `resources/openapi.json` and the actual
> Resource/FormRequest files; where they disagree, the regenerated spec + source win and this
> spec's appendix is corrected.

### Update workflow (documented in the package README/AGENTS.md and the app's developer-docs)

When the API changes:

1. App: `cd developer-docs && npm run generate:openapi` regenerates
   `developer-docs/public/openapi.json`.
2. Package: `composer run sync-spec` copies that file into `resources/openapi.json`
   (the script holds the source path; the copy is the only app dependency, and it is a
   build-time/manual step, never runtime).
3. Package: `composer test` runs the drift test, which goes red on exactly what changed.
4. Maintainer updates the affected `@param`/`@return` shapes and `AGENTS.md` until green.

## Files to add / change (in `proofage-laravel-client`)

**Add**
- `resources/openapi.json` — bundled copy of the app spec (regenerated, includes `estimation`).
- `AGENTS.md` — AI-facing endpoint contract (see layer 3).
- `tests/ApiContractTest.php` — the drift test (flat in `tests/`, matching package convention).
- `tests/Support/ApiContractMap.php` — method→operation→fields map (`ProofAge\Laravel\Tests\Support`).
- `composer.json` `scripts` block with `test` and `sync-spec` (there is none today; the
  README's `composer test` currently has no script behind it). `sync-spec` copies the spec
  from the app path. Ensure
  `resources/openapi.json` is **not** `export-ignore`d in `.gitattributes` (so it installs
  into consumers' `vendor/proofage/laravel-client/resources/`), while `tests/`, `docs/`,
  and `examples/` stay `export-ignore`d as usual. The drift test reads `resources/openapi.json`
  via a package-relative path.

**Change**
- `src/Resources/VerificationResource.php` — add `@param`/`@return` array shapes to
  `create`, `find`/`get`, `acceptConsent`, `uploadMedia`, `submit`, `document`,
  `blockFace`. (`estimation` already has its `@return` shape.)
- `src/Resources/WorkspaceResource.php` — add `@return` array shapes to `get`, `getConsent`.
- `README.md` — extend "API Methods" to include `document()`, `estimation()`,
  `blockFace()`; add an "AI agents / contract" pointer to `AGENTS.md` and the bundled spec;
  document the update workflow.
- `.cursor/` rules — reference `AGENTS.md`.

> All listed methods already exist in `src/` with passing HTTP-fake tests (the package has
> recent `Add verification estimation endpoint` / `Allow blockFace request body data`
> commits). This pass adds the **contract layers** around them; it does not add methods.

**App-side (one minimal touch, produces the source artifact)**
- Regenerate `developer-docs/public/openapi.json` (`cd developer-docs && npm run generate:openapi`).
  Verified 2026-06-29: the regenerated spec already includes
  `GET /verifications/{verification}/estimation` — the committed copy was just stale. No
  annotation or behavior changes needed.

## Testing

- New `tests/ApiContractTest.php` (PHPUnit, `extends ProofAge\Laravel\Tests\TestCase`)
  implementing the four drift assertions above.
- Existing test suite stays green (`vendor/bin/phpunit` / `composer test`).
- Response shapes for endpoints Scramble can't describe are pinned by the existing
  `VerificationResourceTest` / `WorkspaceResourceTest` HTTP-fake tests; the contract map +
  drift test cover endpoints, request fields, and the three reliable response schemas.

## Node client follow-up (out of scope here, same pattern)

Mirror the approach: response `interface`s in `src/types.ts` typing each method's return;
add the missing `estimation()` method and a `reason` argument on `blockFace()`; bundle
`openapi.json`; add a vitest contract test; replace the release-only `AGENTS.md` content
with (or add alongside it) the API-contract doc.

---

## Appendix A — Authoritative endpoint contract (verified 2026-06-29)

Base URL: `{base_url}/{version}` (e.g. `https://api.proofage.com/v1`). All requests send
`X-API-Key` and `X-HMAC-Signature`; HMAC is **optional** on `POST /verifications` and
**required** elsewhere.

### `workspace()->get()` — `GET /workspace`
Response (`WorkspaceResource`):
```
id: string, name: string,
flow_type: string (enum), mode: string (enum), age_mode: string|null (enum),
age_threshold: int|null, verification_type: string (enum),
redirect_url: string|null, webhook_url: string|null,
allow_expired_documents: bool, allow_duplicate_accounts: bool
```

### `workspace()->getConsent()` — `GET /consent`
Response:
```
id: int, version: string, text_sha256: string(64 hex), url: string
```

### `verifications()->create(array $data)` — `POST /verifications`
Request:
```
fingerprint?: string(64)   callback_url?: url(max 2048)
external_id?: string(max 255)   external_metadata?: array   metadata?: array
```
Response (`VerificationResource` + `url`):
```
id: string, external_id: string|null, external_metadata: array|null,
redirect_url: string|null, status: VerificationStatus, reason: string|null,
consent_accepted_at: iso8601|null, created_at: iso8601, updated_at: iso8601,
url: string
```
Errors: `402 PAYMENT_METHOD_REQUIRED` `{ code, message, free_verifications_remaining,
trial_ends_at, trial_active }`.

### `verifications()->find($id)` / `verifications($id)->get()` — `GET /verifications/{id}`
Response = `VerificationResource` **without** `url` (the `redirect_url`, `status`,
`reason`, timestamps as above). `status` may also be `documents_required` (from
`AttemptStatus`) in addition to the `VerificationStatus` set.

### `verifications($id)->acceptConsent(array $data)` — `POST /verifications/{id}/consent`
Request: `consent_version_id: int (exists)`, `text_sha256: string(64 hex)`.
Response: `{ consent_version_id: int, consent_accepted_at: iso8601 }`.

### `verifications($id)->uploadMedia(array $data)` — `POST /verifications/{id}/media` (multipart)
Request: `file: image(max 10240KB)`, `type: selfie|liveness_selfie|document`,
`side?: front|back` (required if type=document),
`document?: id|driver_license|passport|residence_permit` (required if type=document),
`fingerprint?: string(64)`, `head_turn_step?: int(0..10)`,
`capture_resolution?: json`, `device_info?: json`.
Response: `{ message: string }`. Requires consent accepted first.

### `verifications($id)->submit()` — `POST /verifications/{id}/submit`
Response: `{ message: string }`. Error: `422 { error: { code, message } }`.

### `verifications($id)->document()` — `GET /verifications/{id}/document`
Response (`VerificationDocumentResource`):
```
document: { fields: { first_name: string|null, last_name: string|null,
                      date_of_birth: string|null (YYYY-MM-DD), document_number: string|null } }
media: [ { id: string, type: string (selfie|document_front|document_back),
           signed_url: string|null, expires_at: iso8601 } ]   // ordered selfie, front, back
meta: { attempt_id: string|null, signed_url_ttl_seconds: int, signed_url_expires_at: iso8601 }
```

### `verifications($id)->estimation()` — `GET /verifications/{id}/estimation`
Response (`VerificationEstimationResource`):
```
verification_id: string, attempt_id: string|null,
age_threshold: { minimum: int|null, passed: bool|null, confidence: float|null },
gender: { value: 0|1|null (0=female,1=male), confidence: float|null } | null
```
> Currently **missing from the committed openapi.json** — fix during the app-side regenerate step.

### `verifications($id)->blockFace(?array $data)` — `POST /verifications/{id}/blocked-face`
Request: `reason?: string(max 1000)`. Response: `204 No Content`.

## Appendix B — Outbound webhook payload (from `WebhookService`)

Sent on terminal status. Headers: `X-Auth-Client` (api key), `X-HMAC-Signature`,
`X-Timestamp`, `X-ProofAge-Webhook-Delivery-Id`. Signature = `hash_hmac('sha256',
"{timestamp}.{rawJsonBody}", active_secret_key)`.

```
verification_id: string
status: VerificationStatus
external_id: string|null
external_metadata: object|null
reason: string|null            // only on resubmission_requested / declined
timestamp: iso8601
duplicate_detected?: true      // present only when a duplicate was found
duplicate_of?: { verification_id: string, external_id: string|null }
fingerprint_signals?: { ip_address?, ip_country_code?, ip_timezone?, device_timezone?, ... }
manual_moderation?: { action: approve|decline, reason, source, performed_by,
                      source_status?, source_reason? }   // source_* only when action=approve
```

## Appendix C — Auth / HMAC canonical strings (for `AGENTS.md`)

- **JSON / no-file requests:** `METHOD + /{version}/{path} + rawBody`, HMAC-SHA256 over the
  raw body bytes with `secret_key`.
- **Multipart (file) requests:** `METHOD/{version}/{path}\n{sorted fields as RFC3986 query}\n{comma-joined sorted sha256(file) hashes}`.
- Header: `X-HMAC-Signature` = hex HMAC-SHA256. `X-API-Key` = plaintext key (server SHA256-hashes it).
