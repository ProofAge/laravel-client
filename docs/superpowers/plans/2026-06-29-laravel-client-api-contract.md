# ProofAge Laravel Client — Self-Describing API Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `proofage/laravel-client` self-describing — a developer or AI agent with only the installed package can see every method's request/response shape — and add a drift test so the package can't silently diverge from the real API.

**Architecture:** Three contract layers ship inside the package: (1) a bundled `resources/openapi.json` copied from the app's Scramble output, (2) `@param`/`@return` array-shape PHPDoc on every resource method, (3) an `AGENTS.md` endpoint contract. A PHPUnit drift test (`tests/ApiContractTest.php`) loads the bundled spec and asserts the SDK's endpoints and request fields match it (plus response fields for the three endpoints Scramble can describe). Authored `@return` shapes + `AGENTS.md` are the authoritative response contract, since Scramble cannot introspect the app's JsonResources.

**Tech Stack:** PHP 8.1+, PHPUnit 10/11 + Orchestra Testbench (NOT Pest), Laravel HTTP client, Laravel Pint.

## Global Constraints

- PHP floor is `^8.1` — array-shape PHPDoc only; no syntax requiring > 8.1. (verbatim: `"php": "^8.1"`)
- Tests use **PHPUnit + Orchestra Testbench, not Pest**. Test classes extend `ProofAge\Laravel\Tests\TestCase`, methods are `public function test_*(): void`.
- Test namespaces: `ProofAge\Laravel\Tests\` (dir `tests/`) and `ProofAge\Laravel\Tests\Support\` (dir `tests/Support/`).
- No new Composer dependencies (CLAUDE-level rule: don't change dependencies without approval).
- Do NOT add a `version` field to `composer.json` (the `.cursor/rules/release-versioning.mdc` rule; versions come from git tags).
- No breaking changes to the public API: every resource method keeps returning `array|null`.
- Pint must pass: run `vendor/bin/pint` before committing; CI runs `vendor/bin/pint --test`.
- `resources/openapi.json` and `AGENTS.md` MUST ship in the Composer dist (must NOT be `export-ignore`d).
- `phpunit.xml` sets `failOnRisky="true"` and `beStrictAboutOutputDuringTests="true"` — every test method must make ≥1 assertion and produce no stdout.
- End every commit message with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- All commands below assume the working directory is the package root: `/Users/nikolay/Projects/ProofAge/proofage-laravel-client`. The sibling app repo is `/Users/nikolay/Projects/ProofAge/proofageapp`.
- Work on the current branch `feature/self-describing-api-contract`.

---

## File Structure

| File | Responsibility |
|---|---|
| `scripts/sync-spec.php` | Copy the app's generated OpenAPI spec into `resources/openapi.json`. |
| `resources/openapi.json` | Bundled machine-readable spec (endpoints + request bodies). Ships to consumers. |
| `.gitattributes` | Keep `resources/` + `AGENTS.md` in the dist; `export-ignore` dev-only dirs. |
| `composer.json` | Add `scripts.test` and `scripts.sync-spec`. |
| `tests/Support/ApiContractMap.php` | Single structured map: SDK method → operation (method, path, request/response field sets). |
| `tests/ApiContractTest.php` | Drift test: asserts the map matches the bundled spec; asserts `AGENTS.md` completeness. |
| `src/Resources/VerificationResource.php` | Add `@param`/`@return` array shapes (methods already exist). |
| `src/Resources/WorkspaceResource.php` | Add `@return` array shapes. |
| `AGENTS.md` | AI-facing endpoint-by-endpoint request/response/error contract. |
| `README.md` | Extend API Methods list; add contract + update-workflow sections. |
| `.cursor/rules/api-contract.mdc` | Point Cursor agents at the contract. |

---

## Task 1: Bundle the OpenAPI spec + sync tooling + dist rules

**Files:**
- Create: `scripts/sync-spec.php`
- Create: `resources/openapi.json` (generated, not hand-written)
- Create: `.gitattributes`
- Modify: `composer.json` (add `scripts` block)

**Interfaces:**
- Produces: `resources/openapi.json` (read by Task 2's test via `dirname(__DIR__).'/resources/openapi.json'`); `composer run sync-spec`; `composer test` → `phpunit`.

- [ ] **Step 1: Create the sync-spec script**

Create `scripts/sync-spec.php`:

```php
<?php

declare(strict_types=1);

/*
 * Copies the app's generated OpenAPI spec into this package's bundled resources.
 * Source defaults to the sibling app repo; override with PROOFAGE_OPENAPI_SRC.
 * Regenerate the source first in the app: `cd developer-docs && npm run generate:openapi`.
 */

$src = getenv('PROOFAGE_OPENAPI_SRC')
    ?: __DIR__ . '/../../proofageapp/developer-docs/public/openapi.json';
$dest = __DIR__ . '/../resources/openapi.json';

if (! is_file($src)) {
    fwrite(STDERR, "Source spec not found: {$src}\n");
    fwrite(STDERR, "Run `npm run generate:openapi` in the app, or set PROOFAGE_OPENAPI_SRC.\n");
    exit(1);
}

if (! is_dir(dirname($dest)) && ! mkdir($concurrent = dirname($dest), 0755, true) && ! is_dir($concurrent)) {
    fwrite(STDERR, "Could not create directory: {$concurrent}\n");
    exit(1);
}

if (! copy($src, $dest)) {
    fwrite(STDERR, "Copy failed: {$src} -> {$dest}\n");
    exit(1);
}

fwrite(STDOUT, "Synced spec: {$src} -> {$dest}\n");
```

- [ ] **Step 2: Add composer scripts**

Edit `composer.json`. After the `"extra": { ... }` block, add a `scripts` key (insert a comma after the `extra` block's closing brace):

```json
    "scripts": {
        "test": "phpunit",
        "sync-spec": "@php scripts/sync-spec.php"
    },
```

Place it so the file stays valid JSON (e.g. immediately before `"minimum-stability"`). Verify with:

Run: `php -r "json_decode(file_get_contents('composer.json'), false, 512, JSON_THROW_ON_ERROR); echo 'composer.json OK\n';"`
Expected: `composer.json OK`

- [ ] **Step 3: Regenerate the app spec, then sync it into the package**

Run:
```bash
( cd /Users/nikolay/Projects/ProofAge/proofageapp/developer-docs && npm run generate:openapi )
composer run sync-spec
```
Expected: `OpenAPI document exported to developer-docs/public/openapi.json.` then `Synced spec: ... -> .../resources/openapi.json`.

> Note: this updates the app repo's tracked `developer-docs/public/openapi.json` (it was stale — missing `estimation`). That app-side change is the intended one-command source-of-truth refresh; commit it separately in the app repo if desired. It is not part of this package's commits.

- [ ] **Step 4: Verify the bundled spec is complete**

Run:
```bash
php -r '$d=json_decode(file_get_contents("resources/openapi.json"),true); $ops=[]; foreach($d["paths"] as $p=>$m){foreach($m as $verb=>$o){$ops[]=strtoupper($verb)." ".$p;}} sort($ops); echo count($ops)." operations\n"; echo in_array("GET /verifications/{verification}/estimation",$ops)?"estimation: present\n":"estimation: MISSING\n";'
```
Expected:
```
10 operations
estimation: present
```

- [ ] **Step 5: Create `.gitattributes` so the spec ships but dev files don't**

Create `.gitattributes`:

```gitattributes
# Keep src/, config/, resources/, README.md, AGENTS.md, INSTALLATION.md, LICENSE.md in the dist.
/.github            export-ignore
/.cursor            export-ignore
/.idea              export-ignore
/tests              export-ignore
/docs               export-ignore
/examples           export-ignore
/build              export-ignore
/scripts            export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/phpunit.xml        export-ignore
/.phpunit.cache     export-ignore
.DS_Store           export-ignore
```

- [ ] **Step 6: Verify dist rules**

Check directory-level attributes (gitattributes directory patterns drive `git archive`
exclusion; `check-attr` on a *file inside* an ignored dir reports `unspecified`, so check the
dir paths). The paths need not exist yet — `check-attr` matches patterns, not files.

Run:
```bash
git check-attr export-ignore -- resources/openapi.json AGENTS.md tests scripts docs
```
Expected:
```
resources/openapi.json: export-ignore: unspecified
AGENTS.md: export-ignore: unspecified
tests: export-ignore: set
scripts: export-ignore: set
docs: export-ignore: set
```

- [ ] **Step 7: Commit**

```bash
git add composer.json scripts/sync-spec.php resources/openapi.json .gitattributes
git commit -m "feat: bundle OpenAPI spec + sync tooling and dist rules

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Contract map + drift test

**Files:**
- Create: `tests/Support/ApiContractMap.php`
- Create: `tests/ApiContractTest.php`

**Interfaces:**
- Consumes: `resources/openapi.json` (Task 1).
- Produces: `ApiContractMap::operations()` returning `array<string, array{method:string, path:string, operationId:string, request:list<string>, response:list<string>}>`, consumed by Task 4's `AGENTS.md` test and authored against in Task 3.

- [ ] **Step 1: Write the failing drift test**

Create `tests/ApiContractTest.php`:

```php
<?php

namespace ProofAge\Laravel\Tests;

use ProofAge\Laravel\Tests\Support\ApiContractMap;

class ApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__) . '/resources/openapi.json';
        $this->assertFileExists($path, 'Bundled OpenAPI spec is missing. Run `composer run sync-spec`.');
        $this->spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_sdk_operation_exists_in_the_spec(): void
    {
        foreach (ApiContractMap::operations() as $name => $op) {
            $specOp = $this->spec['paths'][$op['path']][strtolower($op['method'])] ?? null;
            $this->assertNotNull(
                $specOp,
                "SDK method [{$name}] targets {$op['method']} {$op['path']} which is missing from the bundled spec."
            );
        }
    }

    public function test_every_spec_operation_is_covered_by_an_sdk_method(): void
    {
        $mapped = [];
        foreach (ApiContractMap::operations() as $op) {
            $mapped[strtoupper($op['method']) . ' ' . $op['path']] = true;
        }

        foreach ($this->spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $key = strtoupper($method) . ' ' . $path;
                $this->assertArrayHasKey(
                    $key,
                    $mapped,
                    "Spec exposes {$key} but no SDK method covers it. Add it to ApiContractMap and the resource class."
                );
            }
        }
    }

    public function test_request_fields_match_the_spec(): void
    {
        foreach (ApiContractMap::operations() as $name => $op) {
            if ($op['request'] === []) {
                continue;
            }

            $specFields = $this->schemaProperties(
                $this->spec['paths'][$op['path']][strtolower($op['method'])]['requestBody']['content']['application/json']['schema'] ?? []
            );
            sort($specFields);
            $mapFields = $op['request'];
            sort($mapFields);

            $this->assertSame($mapFields, $specFields, "Request fields for [{$name}] drifted from the spec.");
        }
    }

    public function test_response_fields_match_the_spec_for_describable_endpoints(): void
    {
        $checked = [];

        foreach (ApiContractMap::operations() as $name => $op) {
            $specFields = $this->responseProperties($op['path'], $op['method']);

            if ($specFields === []) {
                // Scramble cannot describe this response (typed as integer/empty array).
                // Its shape is pinned by the authored @return PHPDoc + HTTP-fake tests instead.
                continue;
            }

            sort($specFields);
            $mapFields = $op['response'];
            sort($mapFields);

            $this->assertSame($mapFields, $specFields, "Response fields for [{$name}] drifted from the spec.");
            $checked[] = $name;
        }

        sort($checked);
        $this->assertSame(
            ['verifications.document', 'verifications.estimation', 'workspace.get'],
            $checked,
            'The set of spec-describable response endpoints changed. If the generator improved, extend response parity coverage.'
        );
    }

    /** @return list<string> */
    private function responseProperties(string $path, string $method): array
    {
        $responses = $this->spec['paths'][$path][strtolower($method)]['responses'] ?? [];

        foreach ($responses as $code => $response) {
            if (! str_starts_with((string) $code, '2')) {
                continue;
            }
            $schema = $response['content']['application/json']['schema'] ?? null;
            if ($schema === null) {
                continue;
            }

            return $this->schemaProperties($schema);
        }

        return [];
    }

    /**
     * Top-level property names of an OpenAPI schema, resolving $ref and merging allOf.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function schemaProperties(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $ref = substr((string) $schema['$ref'], strlen('#/components/schemas/'));

            return $this->schemaProperties($this->spec['components']['schemas'][$ref] ?? []);
        }

        $properties = [];

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                $properties = array_merge($properties, $this->schemaProperties($sub));
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $properties = array_merge($properties, array_keys($schema['properties']));
        }

        return array_values(array_unique($properties));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter ApiContractTest`
Expected: FAIL/ERROR — `Class "ProofAge\Laravel\Tests\Support\ApiContractMap" not found`.

- [ ] **Step 3: Create the contract map**

Create `tests/Support/ApiContractMap.php`:

```php
<?php

namespace ProofAge\Laravel\Tests\Support;

class ApiContractMap
{
    /**
     * SDK method -> API operation contract.
     *
     * `path` matches the bundled openapi.json path template (no version prefix).
     * `request` / `response` are the TOP-LEVEL field-name sets the SDK exchanges; the
     * response sets are the authoritative (authored) contract and double as the source
     * for the `@return` PHPDoc shapes and AGENTS.md.
     *
     * @return array<string, array{method: string, path: string, operationId: string, request: list<string>, response: list<string>}>
     */
    public static function operations(): array
    {
        return [
            'workspace.get' => [
                'method' => 'GET', 'path' => '/workspace', 'operationId' => 'getWorkspace',
                'request' => [],
                'response' => ['id', 'name', 'flow_type', 'mode', 'age_mode', 'age_threshold', 'verification_type', 'redirect_url', 'webhook_url', 'allow_expired_documents', 'allow_duplicate_accounts'],
            ],
            'workspace.getConsent' => [
                'method' => 'GET', 'path' => '/consent', 'operationId' => 'getConsent',
                'request' => [],
                'response' => ['id', 'version', 'text_sha256', 'url'],
            ],
            'verifications.create' => [
                'method' => 'POST', 'path' => '/verifications', 'operationId' => 'createVerification',
                'request' => ['fingerprint', 'callback_url', 'external_id', 'external_metadata', 'metadata'],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'consent_accepted_at', 'created_at', 'updated_at', 'url'],
            ],
            'verifications.find' => [
                'method' => 'GET', 'path' => '/verifications/{verification}', 'operationId' => 'getVerification',
                'request' => [],
                'response' => ['id', 'external_id', 'external_metadata', 'redirect_url', 'status', 'reason', 'consent_accepted_at', 'created_at', 'updated_at'],
            ],
            'verifications.acceptConsent' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/consent', 'operationId' => 'acceptConsent',
                'request' => ['consent_version_id', 'text_sha256'],
                'response' => ['consent_version_id', 'consent_accepted_at'],
            ],
            'verifications.uploadMedia' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/media', 'operationId' => 'uploadMedia',
                'request' => ['file', 'type', 'side', 'document', 'fingerprint', 'head_turn_step', 'capture_resolution', 'device_info'],
                'response' => ['message'],
            ],
            'verifications.submit' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/submit', 'operationId' => 'submitVerification',
                'request' => [],
                'response' => ['message'],
            ],
            'verifications.document' => [
                'method' => 'GET', 'path' => '/verifications/{verification}/document', 'operationId' => 'getVerificationDocument',
                'request' => [],
                'response' => ['document', 'media', 'meta'],
            ],
            'verifications.estimation' => [
                'method' => 'GET', 'path' => '/verifications/{verification}/estimation', 'operationId' => 'getVerificationEstimation',
                'request' => [],
                'response' => ['verification_id', 'attempt_id', 'age_threshold', 'gender'],
            ],
            'verifications.blockFace' => [
                'method' => 'POST', 'path' => '/verifications/{verification}/blocked-face', 'operationId' => 'blockVerificationFace',
                'request' => ['reason'],
                'response' => [],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ApiContractTest`
Expected: PASS (4 tests, e.g. `OK (4 tests, ...)`).

- [ ] **Step 5: Run Pint and the full suite**

Run: `vendor/bin/pint && vendor/bin/phpunit`
Expected: Pint shows no style errors; all tests pass.

- [ ] **Step 6: Commit**

```bash
git add tests/Support/ApiContractMap.php tests/ApiContractTest.php
git commit -m "test: add API contract drift test against bundled spec

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Authored `@param`/`@return` array-shape PHPDoc

**Files:**
- Modify: `src/Resources/VerificationResource.php`
- Modify: `src/Resources/WorkspaceResource.php`

**Interfaces:**
- Consumes: the field sets in `ApiContractMap::operations()` (the PHPDoc shapes are authored to match them).
- Produces: no signature changes (methods still return `array|null`); only docblocks change.

- [ ] **Step 1: Annotate `create()` in `VerificationResource.php`**

Replace:
```php
    /**
     * Create a new verification.
     */
    public function create(array $data): ?array
```
with:
```php
    /**
     * Create a new verification.
     *
     * @param  array{
     *     fingerprint?: string,
     *     callback_url?: string,
     *     external_id?: string,
     *     external_metadata?: array<string, mixed>,
     *     metadata?: array<string, mixed>
     * }  $data
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string,
     *     url: string
     * }|null
     */
    public function create(array $data): ?array
```

- [ ] **Step 2: Annotate `find()`**

Replace:
```php
    /**
     * Get verification by ID.
     */
    public function find(string $id): ?array
```
with:
```php
    /**
     * Get verification by ID.
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function find(string $id): ?array
```

- [ ] **Step 3: Annotate `get()`**

Replace:
```php
    /**
     * Get current verification (if ID was set in constructor).
     */
    public function get(): ?array
```
with:
```php
    /**
     * Get current verification (if ID was set in constructor).
     *
     * @return array{
     *     id: string,
     *     external_id: string|null,
     *     external_metadata: array<string, mixed>|null,
     *     redirect_url: string|null,
     *     status: string,
     *     reason: string|null,
     *     consent_accepted_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }|null
     */
    public function get(): ?array
```

- [ ] **Step 4: Annotate `acceptConsent()`**

Replace:
```php
    /**
     * Accept consent for verification.
     */
    public function acceptConsent(array $data): ?array
```
with:
```php
    /**
     * Accept consent for verification.
     *
     * @param  array{consent_version_id: int, text_sha256: string}  $data
     * @return array{consent_version_id: int, consent_accepted_at: string}|null
     */
    public function acceptConsent(array $data): ?array
```

- [ ] **Step 5: Annotate `uploadMedia()`**

Replace:
```php
    /**
     * Upload media for verification.
     */
    public function uploadMedia(array $data): ?array
```
with:
```php
    /**
     * Upload media for verification.
     *
     * `file` is sent as multipart. `side` and `document` are required when `type` is
     * `document`. `capture_resolution` and `device_info` are JSON-encoded strings.
     *
     * @param  array{
     *     file: \Illuminate\Http\UploadedFile|string,
     *     type: string,
     *     side?: string,
     *     document?: string,
     *     fingerprint?: string,
     *     head_turn_step?: int,
     *     capture_resolution?: string,
     *     device_info?: string
     * }  $data
     * @return array{message: string}|null
     */
    public function uploadMedia(array $data): ?array
```

- [ ] **Step 6: Annotate `submit()`**

Replace:
```php
    /**
     * Submit verification for processing.
     */
    public function submit(): ?array
```
with:
```php
    /**
     * Submit verification for processing.
     *
     * @return array{message: string}|null
     */
    public function submit(): ?array
```

- [ ] **Step 7: Annotate `document()`**

Replace:
```php
    /**
     * Get sanitized document fields and source media for verification.
     */
    public function document(): ?array
```
with:
```php
    /**
     * Get sanitized document fields and source media for verification.
     *
     * Media are ordered selfie, document_front, document_back; `signed_url` values expire
     * at `meta.signed_url_expires_at`.
     *
     * @return array{
     *     document: array{fields: array{first_name: string|null, last_name: string|null, date_of_birth: string|null, document_number: string|null}},
     *     media: list<array{id: string, type: string, signed_url: string|null, expires_at: string}>,
     *     meta: array{attempt_id: string|null, signed_url_ttl_seconds: int, signed_url_expires_at: string}
     * }|null
     */
    public function document(): ?array
```

> `estimation()` already has a complete `@return` shape — leave it unchanged.

- [ ] **Step 8: Annotate `blockFace()`**

Replace:
```php
    /**
     * Block the verification face for future AML checks.
     *
     * Optionally pass body data such as ['reason' => 'text here'].
     */
    public function blockFace(?array $data = null): ?array
```
with:
```php
    /**
     * Block the verification face for future AML checks.
     *
     * The API responds 204 No Content, so this returns null.
     *
     * @param  array{reason?: string}|null  $data
     * @return null
     */
    public function blockFace(?array $data = null): ?array
```

- [ ] **Step 9: Annotate `WorkspaceResource::get()` and `getConsent()`**

In `src/Resources/WorkspaceResource.php`, replace:
```php
    /**
     * Get workspace information.
     */
    public function get(): ?array
```
with:
```php
    /**
     * Get workspace information.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     flow_type: string,
     *     mode: string,
     *     age_mode: string|null,
     *     age_threshold: int|null,
     *     verification_type: string,
     *     redirect_url: string|null,
     *     webhook_url: string|null,
     *     allow_expired_documents: bool,
     *     allow_duplicate_accounts: bool
     * }|null
     */
    public function get(): ?array
```

and replace:
```php
    /**
     * Get consent information.
     */
    public function getConsent(): ?array
```
with:
```php
    /**
     * Get consent information.
     *
     * @return array{id: int, version: string, text_sha256: string, url: string}|null
     */
    public function getConsent(): ?array
```

- [ ] **Step 10: Run Pint and the full suite**

Run: `vendor/bin/pint && vendor/bin/phpunit`
Expected: no style errors; all tests pass (docblock-only changes don't alter behavior).

- [ ] **Step 11: Commit**

```bash
git add src/Resources/VerificationResource.php src/Resources/WorkspaceResource.php
git commit -m "docs: add request/response array-shape PHPDoc to resource methods

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: AGENTS.md contract + README + Cursor rule + completeness test

**Files:**
- Create: `AGENTS.md`
- Create: `.cursor/rules/api-contract.mdc`
- Modify: `README.md`
- Modify: `tests/ApiContractTest.php` (add one test method)

**Interfaces:**
- Consumes: `ApiContractMap::operations()` (Task 2) for the completeness test.
- Produces: shipped `AGENTS.md` (read by the new test via `dirname(__DIR__).'/AGENTS.md'`).

- [ ] **Step 1: Write the failing AGENTS.md completeness test**

In `tests/ApiContractTest.php`, add this method (after the existing test methods, before the private helpers):

```php
    public function test_agents_doc_covers_every_endpoint(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__) . '/AGENTS.md');

        foreach (ApiContractMap::operations() as $name => $op) {
            $token = $op['method'] . ' ' . $op['path'];
            $this->assertStringContainsString(
                $token,
                $doc,
                "AGENTS.md is missing the [{$name}] endpoint line ({$token})."
            );
        }
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter test_agents_doc_covers_every_endpoint`
Expected: FAIL — `file_get_contents(...AGENTS.md): Failed to open stream` / missing-string assertion.

- [ ] **Step 3: Create `AGENTS.md`**

Create `AGENTS.md` (every `METHOD /path` header below must match `ApiContractMap` exactly — the test depends on it):

````markdown
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

- `status`: `created`, `started`, `submitted`, `resubmission_requested`, `approved`, `declined`, `abandoned`, `expired`, `review`, `documents_required`. See `ProofAge\Laravel\Enums\VerificationStatus`.
- `reason` (on declined / resubmission_requested): dotted codes from the server's reason catalog, e.g. `aml.blocklist.face_match`, `document.face.mismatch`, `verification.age_threshold.failed`. See `ProofAge\Laravel\Enums\WebhookReason`.

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
````

- [ ] **Step 4: Run the completeness test to verify it passes**

Run: `vendor/bin/phpunit --filter test_agents_doc_covers_every_endpoint`
Expected: PASS.

- [ ] **Step 5: Create the Cursor rule**

Create `.cursor/rules/api-contract.mdc`:

```mdc
---
description: ProofAge Laravel client API request/response contract
alwaysApply: true
---

# API contract

The exact request/response shape of every `ProofAge` facade / `ProofAgeClient` method is in:

- `AGENTS.md` (root) — endpoint-by-endpoint request/response/error contract.
- `resources/openapi.json` — machine-readable OpenAPI 3.1 (endpoints + request bodies).
- `@param` / `@return` array shapes on the methods in `src/Resources/`.

Responses are authoritative in the PHPDoc `@return` shapes and `AGENTS.md` (the bundled
spec's response side is incomplete by generator limitation). Keep all of these in sync; the
drift guard is `tests/ApiContractTest.php` against `resources/openapi.json`.
```

- [ ] **Step 6: Update the README**

In `README.md`, replace the "### Verifications" sub-list under "## API Methods":
```md
- `verifications()->create(array $data)` - Create a new verification
- `verifications()->find(string $id)` - Get verification by ID
- `verifications(string $id)->acceptConsent(array $data)` - Accept consent
- `verifications(string $id)->uploadMedia(array $data)` - Upload media files
- `verifications(string $id)->submit()` - Submit verification for processing
```
with:
```md
- `verifications()->create(array $data)` - Create a new verification
- `verifications()->find(string $id)` - Get verification by ID
- `verifications(string $id)->acceptConsent(array $data)` - Accept consent
- `verifications(string $id)->uploadMedia(array $data)` - Upload media files
- `verifications(string $id)->submit()` - Submit verification for processing
- `verifications(string $id)->document()` - Get sanitized document fields and source media
- `verifications(string $id)->estimation()` - Get age-threshold and gender estimation
- `verifications(string $id)->blockFace(?array $data)` - Block the verification face for AML

Every method's exact request and response shape is documented in `AGENTS.md`, in the
`@param`/`@return` PHPDoc on `src/Resources/`, and in the bundled `resources/openapi.json`.
```

Then add this section at the end of the README, before "## License":
```md
## Keeping the API contract in sync

The package bundles a machine-readable spec at `resources/openapi.json` and a drift test
(`tests/ApiContractTest.php`) that fails if the SDK surface diverges from it. To refresh
after an API change:

1. In the app, regenerate the spec: `cd developer-docs && npm run generate:openapi`.
2. In this package: `composer run sync-spec` (copies the spec into `resources/`).
3. Run `composer test`. If the drift test fails, update `tests/Support/ApiContractMap.php`,
   the `@param`/`@return` shapes in `src/Resources/`, and `AGENTS.md` to match.

```

- [ ] **Step 7: Run Pint and the full suite**

Run: `vendor/bin/pint && vendor/bin/phpunit`
Expected: no style errors; all tests pass (5 tests in `ApiContractTest` plus the existing suite).

- [ ] **Step 8: Commit**

```bash
git add AGENTS.md .cursor/rules/api-contract.mdc README.md tests/ApiContractTest.php
git commit -m "docs: ship AGENTS.md API contract + sync workflow, guard completeness in tests

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Bundled machine-readable spec (layer 1) → Task 1.
- `@param`/`@return` shapes (layer 2) → Task 3.
- `AGENTS.md` contract + `.cursor` (layer 3) → Task 4.
- Drift test: endpoint existence, coverage, request parity, response parity (reliable only) → Task 2; AGENTS.md completeness → Task 4.
- Update workflow documented → Task 1 (`sync-spec`), README + AGENTS.md (Task 4).
- App-side one-command regenerate → Task 1 Step 3.
- README API-methods gap (`document`/`estimation`/`blockFace`) → Task 4 Step 6.
- Node follow-up → explicitly out of scope (separate later pass).

**Placeholder scan:** No TBD/TODO; all code and file contents are complete.

**Type consistency:** `ApiContractMap::operations()` field names match the `@return` shapes (Task 3) and the `AGENTS.md` response lines (Task 4). The `path`/`method` tokens in the map match the `### METHOD /path` headers in `AGENTS.md` that the completeness test checks. The describable-response set asserted in Task 2 (`workspace.get`, `verifications.estimation`, `verifications.document`) matches the three endpoints whose spec response schema exposes properties.
