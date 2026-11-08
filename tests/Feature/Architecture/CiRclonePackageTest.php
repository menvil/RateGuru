<?php

use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/*
 * The Architecture suite runs a real rclone, which CI installs from one pinned
 * package: cached by its pin, else downloaded from GitHub's release or, when
 * GitHub does not answer, from rclone's own download server. A second source
 * may widen only where the bytes come from, never what they may be — every
 * source is held to the pinned checksum, and the package once more before
 * dpkg sees it.
 */

/** @return array{env: array<string, mixed>, steps: Collection<int, array<string, mixed>>} */
function architectureJobOfCi(): array
{
    $job = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['tests-architecture'];

    return ['env' => $job['env'], 'steps' => collect($job['steps'])];
}

it('pins the rclone package once, for the cache and the install alike', function () {
    ['env' => $env, 'steps' => $steps] = architectureJobOfCi();
    $names = $steps->pluck('name')->all();

    expect($env['RCLONE_VERSION'])->toMatch('/^v\d+\.\d+\.\d+$/')
        ->and($env['RCLONE_DEB_SHA256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($steps->firstWhere('name', 'Cache the rclone package')['with']['key'])
        ->toBe('rclone-${{ env.RCLONE_VERSION }}-${{ env.RCLONE_DEB_SHA256 }}')
        // The cache is restored before the install looks for it.
        ->and(array_search('Cache the rclone package', $names, true))->toBeLessThan(array_search('Install rclone', $names, true))
        // One pin: the install step keeps none of its own.
        ->and($steps->firstWhere('name', 'Install rclone'))->not->toHaveKey('env');
});

it('downloads rclone from GitHub first and rclone\'s own server second, holding each to the pinned checksum', function () {
    $install = (string) architectureJobOfCi()['steps']->firstWhere('name', 'Install rclone')['run'];
    $github = strpos($install, '"https://github.com/rclone/rclone/releases/download/${RCLONE_VERSION}/rclone-${RCLONE_VERSION}-linux-amd64.deb"');
    $server = strpos($install, '"https://downloads.rclone.org/${RCLONE_VERSION}/rclone-${RCLONE_VERSION}-linux-amd64.deb"');

    expect($github)->toBeInt()
        ->and($server)->toBeInt()->toBeGreaterThan($github)
        // Bytes are kept only once they match the pin...
        ->and($install)->toContain('-o "${package}.part" "${source}" && pinned "${package}.part"; then')
        ->and($install)->toContain('pinned() { echo "${RCLONE_DEB_SHA256}  $1" | sha256sum --check --status -; }')
        // ...and the package is checked once more, whichever way it came, right before dpkg.
        ->and($install)->toMatch('/echo "\$\{RCLONE_DEB_SHA256\}  \$\{package\}" \| sha256sum --check -\n\s*sudo dpkg -i "\$\{package\}"\s*$/');
});
