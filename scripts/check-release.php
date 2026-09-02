<?php

/**
 * Prints the versions Packagist actually serves, and refuses a version that is
 * already taken — or one that would ship with a dependency nobody can install.
 *
 * The git tags in this repository are NOT a reliable picture of what has been
 * released: several published versions (0.2.9 through 0.5.0) have no tag behind
 * them any more. Reading `git tag` to pick the next version once produced a tag
 * on a number Packagist already served from a different commit — which would
 * have changed the contents of a published release. Ask the registry instead.
 *
 * Dependency gate: this package requires proofage/php-sdk, which consumers
 * resolve from Packagist. A laravel-client tag that goes out ahead of the SDK
 * release its constraint needs (a bumped ^ constraint, or a first release) gives
 * every consumer an unresolvable dependency, so a candidate is refused until
 * Packagist serves an SDK version satisfying the constraint in composer.json.
 *
 * Usage:
 *   php scripts/check-release.php            # show what is published
 *   php scripts/check-release.php v0.7.0     # also verify that version is free
 */
$composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
$package = $composer['name'];

/**
 * Versions Packagist serves for a package, oldest first: version => short source reference.
 * An empty array means Packagist has never heard of the package. Exits when it is unreachable.
 *
 * @return array<string, string>
 */
function packagistReleases(string $package): array
{
    $url = "https://repo.packagist.org/p2/{$package}.json";
    $body = @file_get_contents($url);

    if ($body === false) {
        $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;

        if ($status === 404) {
            return [];
        }

        fwrite(STDERR, "Could not reach Packagist ({$url}). Check the published versions by hand before tagging.\n");
        exit(2);
    }

    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $released = [];

    foreach (reset($payload['packages']) ?: [] as $release) {
        $released[ltrim((string) $release['version'], 'v')] = substr((string) $release['source']['reference'], 0, 8);
    }

    uksort($released, 'version_compare');

    return $released;
}

/**
 * Whether a version satisfies a plain caret constraint (^0.1, ^1.2.3), the only form this
 * package uses for the SDK. Any other form is left to Composer and treated as satisfied.
 */
function satisfiesCaret(string $version, string $constraint): bool
{
    if (! preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $constraint, $m)) {
        return true;
    }

    [$major, $minor, $patch] = [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    $lower = "{$major}.{$minor}.{$patch}";

    // Caret allows changes up to the next major; below 1.0 up to the next minor; below 0.1 the next patch.
    if ($major > 0 || ! isset($m[2])) {
        $upper = ($major + 1).'.0.0';
    } elseif ($minor > 0 || ! isset($m[3])) {
        $upper = '0.'.($minor + 1).'.0';
    } else {
        $upper = '0.0.'.($patch + 1);
    }

    return version_compare($version, $lower, '>=') && version_compare($version, $upper, '<');
}

$candidate = $argv[1] ?? null;

$sdk = 'proofage/php-sdk';
$constraint = $composer['require'][$sdk] ?? null;

if ($constraint !== null) {
    $sdkReleases = packagistReleases($sdk);
    $usable = array_filter(array_keys($sdkReleases), fn (string $version) => satisfiesCaret($version, $constraint));

    if ($usable === []) {
        $serves = $sdkReleases === [] ? 'nothing' : implode(', ', array_keys($sdkReleases));
        $verdict = $candidate === null ? 'WARNING' : 'REFUSED';

        fwrite(STDERR, "{$verdict}: {$package} requires {$sdk} {$constraint}, but Packagist serves {$serves} for it.\n");
        fwrite(STDERR, "No consumer could install this release. Publish {$sdk} first, then tag this package.\n");

        if ($candidate !== null) {
            exit(1);
        }

        fwrite(STDERR, "\n");
    } else {
        echo "{$sdk} {$constraint} resolves from Packagist (".implode(', ', $usable).").\n\n";
    }
}

$released = packagistReleases($package);

if ($released === []) {
    fwrite(STDERR, "Packagist serves no versions of {$package}.\n");
    exit(2);
}

$latest = array_key_last($released);

echo "Published on Packagist ({$package}):\n";

foreach (array_slice($released, -5, 5, true) as $version => $reference) {
    echo "  {$version}\t{$reference}\n";
}

[$major, $minor, $patch] = array_map('intval', explode('.', $latest));

echo "\nLatest published: {$latest}\n";
echo "Next patch: {$major}.{$minor}.".($patch + 1)."  |  next minor: {$major}.".($minor + 1).".0\n";

if ($candidate === null) {
    exit(0);
}

$candidate = ltrim($candidate, 'v');

if (isset($released[$candidate])) {
    fwrite(STDERR, "\nREFUSED: {$candidate} is already published, from commit {$released[$candidate]}.\n");
    fwrite(STDERR, "Tagging it again would change the contents of a released version.\n");
    exit(1);
}

if (version_compare($candidate, $latest, '<')) {
    fwrite(STDERR, "\nREFUSED: {$candidate} is below the published {$latest}, so it would never resolve as latest.\n");
    exit(1);
}

echo "\n{$candidate} is free and above the published latest.\n";
