# ProofAge Laravel Client — API contract for agents

This package wraps the ProofAge v1 HTTP API. Methods on `ProofAge::workspace()` and
`ProofAge::verifications($id)` return decoded JSON as `array|null`. The exact request and
response shape of every method is below and in the `@param`/`@return` PHPDoc on
`src/Resources/`. A machine-readable spec ships at `resources/openapi.json` (authoritative
for endpoints + request bodies; response schemas there are incomplete by generator
limitation — the shapes below are authoritative for responses).

All requests send `X-API-Key` and `X-HMAC-Signature`. Base URL is
`{base_url}/{version}` (defaults `https://api.proofage.xyz/v1`).

## Auth / HMAC

- `X-API-Key`: workspace API key (plaintext; the server SHA256-hashes it).
- `X-HMAC-Signature`: hex HMAC-SHA256 with the workspace secret key over a canonical string:
  - JSON / no-file requests: `METHOD + /{version}/{path} + rawJsonBody`.
  - Multipart (file) requests: `METHOD/{version}/{path}\n{sorted fields as RFC3986 query}\n{comma-joined sorted sha256(file) hashes}`.

## Endpoints

### GET /workspace — `ProofAge::workspace()->get()`
Request: none.
Response: `{ id: string, name: string, flow_type: string, mode: string, age_mode: string|null, age_threshold: int|null, verification_type: string, redirect_url: string|null, webhook_url: string|null, allow_expired_documents: bool, allow_duplicate_accounts: bool }`

### GET /consent — `ProofAge::workspace()->getConsent()`
Request: none.
Response: `{ id: int, version: string, text_sha256: string, url: string }`

### POST /verifications — `ProofAge::verifications()->create($data)`
Request: `{ fingerprint?: string(64), callback_url?: url(<=2048), external_id?: string(<=255), external_metadata?: object, metadata?: object }`
Response: `{ id: string, external_id: string|null, external_metadata: object|null, redirect_url: string|null, status: string, reason: string|null, consent_accepted_at: string|null, created_at: string, updated_at: string, url: string }`
Errors: `402` `{ code: "PAYMENT_METHOD_REQUIRED", message, free_verifications_remaining, trial_ends_at, trial_active }`.

### GET /verifications/{verification} — `ProofAge::verifications($id)->find($id)` / `->get()`
Request: none.
Response: same as create **without** `url`.

### POST /verifications/{verification}/consent — `ProofAge::verifications($id)->acceptConsent($data)`
Request: `{ consent_version_id: int, text_sha256: string(64 hex) }`
Response: `{ consent_version_id: int, consent_accepted_at: string }`

### POST /verifications/{verification}/media — `ProofAge::verifications($id)->uploadMedia($data)` (multipart)
Request: `{ file: UploadedFile|path, type: "selfie"|"liveness_selfie"|"document", side?: "front"|"back" (req. if type=document), document?: "id"|"driver_license"|"passport"|"residence_permit" (req. if type=document), fingerprint?: string(64), head_turn_step?: int(0..10), capture_resolution?: json-string, device_info?: json-string }`
Response: `{ message: string }`. Requires consent accepted first.

### POST /verifications/{verification}/submit — `ProofAge::verifications($id)->submit()`
Request: none.
Response: `{ message: string }`. Error: `422 { error: { code, message } }`.

### GET /verifications/{verification}/document — `ProofAge::verifications($id)->document()`
Request: none.
Response: `{ document: { fields: { first_name: string|null, last_name: string|null, date_of_birth: string|null (YYYY-MM-DD), document_number: string|null } }, media: [ { id: string, type: "selfie"|"document_front"|"document_back", signed_url: string|null, expires_at: string } ], meta: { attempt_id: string|null, signed_url_ttl_seconds: int, signed_url_expires_at: string } }`

### GET /verifications/{verification}/estimation — `ProofAge::verifications($id)->estimation()`
Request: none.
Response: `{ verification_id: string, attempt_id: string|null, age_threshold: { minimum: int|null, passed: bool|null, confidence: float|null }, gender: { value: 0|1|null, confidence: float|null }|null }` (gender value: 0=female, 1=male).

### POST /verifications/{verification}/blocked-face — `ProofAge::verifications($id)->blockFace($data)`
Request: `{ reason?: string(<=1000) }`.
Response: `204 No Content` (method returns `null`).

## Enums

- `status`: one of `created`, `started`, `submitted`, `resubmission_requested`, `approved`, `declined`, `abandoned`, `expired`, `review` (the `ProofAge\Laravel\Enums\VerificationStatus` cases), or `documents_required` — surfaced from the latest attempt's state (an `AttemptStatus`), not a `VerificationStatus` case. Map the `status` field with `VerificationStatus::tryFrom()` and handle `documents_required` explicitly.
- `reason` (on `declined` / `resubmission_requested`): dotted codes from the server's reason catalog — illustrative examples: `aml.blocklist.face_match`, `document.face.mismatch`, `verification.age_threshold.failed`. `ProofAge\Laravel\Enums\WebhookReason` models only the AML blocklist codes; treat `reason` as an open string.

## Outbound webhook (ProofAge → your `callback_url` / workspace webhook URL)

Headers: `X-Auth-Client` (api key), `X-Timestamp` (unix seconds), `X-HMAC-Signature`
(= hex HMAC-SHA256 of `{timestamp}.{rawJsonBody}` with the active secret key),
`X-ProofAge-Webhook-Delivery-Id`. Verify with the `proofage.verify_webhook` middleware.

Body:
```
{
  "verification_id": string,
  "status": string,
  "external_id": string|null,
  "external_metadata": object|null,
  "reason": string|null,                       // only on resubmission_requested / declined
  "timestamp": string (ISO8601),
  "duplicate_detected"?: true,                 // present only when a duplicate was found
  "duplicate_of"?: { "verification_id": string, "external_id": string|null },
  "fingerprint_signals"?: { "ip_address"?, "ip_country_code"?, "ip_timezone"?, "device_timezone"?, ... },
  "manual_moderation"?: { "action": "approve"|"decline", "reason": string, "source": string,
                          "performed_by": string, "source_status"?: string|null, "source_reason"?: string|null }
}
```

## Keeping this in sync

`resources/openapi.json` is generated from the app (`composer run sync-spec`). The drift
guard is `tests/ApiContractTest.php`. When the API changes, refresh the spec, run the tests,
and update `tests/Support/ApiContractMap.php`, the `@param`/`@return` shapes, and this file
together.
