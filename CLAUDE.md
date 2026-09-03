# Working in this repo

## Releasing

**Take the next version from Packagist, never from `git tag`.**
Run `composer run check-release <version>` first — it prints what Packagist serves and refuses a
number that is already taken or below the published latest.

**It also refuses to release ahead of `proofage/php-sdk`.** This package requires the SDK, which
consumers resolve from Packagist like everything else. A tag that goes out before Packagist serves
an SDK version satisfying the `^` constraint in `composer.json` — a bumped constraint, or a first
release — gives every consumer an unresolvable dependency, so the script checks for one and exits 1
until it exists. When the SDK gains a minor version this package needs, publish the SDK first,
bump the constraint here, then release here.

This repo's tags genuinely lie: versions 0.2.9 through 0.5.0 are published on Packagist with no
tag behind them any more (nobody has established who removed them — the org audit log is not
reachable and GitHub's events feed carries no tag events for this repo). Reading `git tag` in
Aug 2026 produced a `v0.3.0` tag on a fresh commit while Packagist already served `0.3.0` from a
different commit; a re-crawl could have changed the contents of a released version.

There is no `version` field in `composer.json` — the git tag alone is the release. Steps: verify
with `check-release`, then `git tag vX.Y.Z && git push origin vX.Y.Z`, then confirm the pickup at
`https://repo.packagist.org/p2/proofage/laravel-client.json` (each entry shows `version` and the
`source.reference` it came from).

## Changing the API surface

Since 0.7.0 the resources, enums, exceptions, signing and the bundled OpenAPI spec live in
`proofage/php-sdk` (checked out as the sibling `../proofage-php-sdk`), and so does the contract
workflow: `composer run sync-spec` and `tests/ApiContractTest.php` are run there, and the
`@param`/`@return` shapes are on `ProofAge\Sdk\Resources\*`. The classes under `src/Resources/`
and `src/Exceptions/` here are empty subclasses kept for backwards compatibility; a new resource
method needs no change in this package beyond the SDK version constraint and, if the facade
gains a method, its docblock.

What is still owned here: the provider, facade, `IlluminateHttpClient`, the webhook middleware
(its check order is pinned by `tests/BackwardCompatibilityTest.php`), `VerifySetupCommand`,
`ConfigResolver`. `tests/BackwardCompatibilityTest.php` is the list of pre-0.7 names that must
keep working; `tests/IlluminateHttpClientTest.php` is the adapter's contract with the SDK.

Run tests with `vendor/bin/phpunit --no-coverage` (no coverage driver locally). `AGENTS.md` ships
to consumers and points at the SDK's for the endpoint contract; this file does not ship (see
`.gitattributes`), so maintainer notes belong here.
