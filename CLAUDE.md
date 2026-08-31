# Working in this repo

## Releasing

**Take the next version from Packagist, never from `git tag`.**
Run `composer run check-release <version>` first — it prints what Packagist serves and refuses a
number that is already taken or below the published latest.

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

The API contract lives in the app repo, not here: see `developer-docs/README.md` §
"Keeping the SDK clients in sync" in `proofageapp`. In short — `composer run sync-spec`, then make
`tests/ApiContractTest.php` pass by updating `tests/Support/ApiContractMap.php`, the `@param`/
`@return` shapes in `src/Resources/`, and `AGENTS.md` together. Run tests with
`vendor/bin/phpunit --no-coverage` (no coverage driver locally). `AGENTS.md` ships to consumers
and is the authoritative response contract; this file does not ship (see `.gitattributes`), so
maintainer notes belong here.
