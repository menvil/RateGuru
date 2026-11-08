<?php

use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/*
 * The Architecture suite runs a real rclone, which CI installs from one pinned
 * package: cached by its pin, else downloaded from GitHub's release or, when
 * GitHub does not serve it, from rclone's own download server. A second source
 * may widen only where the bytes come from, never what they may be — every
 * source is held to the pinned checksum, and the package once more before
 * dpkg sees it.
 *
 * The install step's own script is run here, as CI runs it, with `curl` and
 * `sudo` stood in for: what each source serves is the test's to say, and dpkg
 * is never called for real.
 */

const RCLONE_GITHUB_PACKAGE = 'https://github.com/rclone/rclone/releases/download/v1.75.0/rclone-v1.75.0-linux-amd64.deb';
const RCLONE_SERVER_PACKAGE = 'https://downloads.rclone.org/v1.75.0/rclone-v1.75.0-linux-amd64.deb';
const RCLONE_PINNED_BYTES = "the pinned rclone package\n";

/** @return array{env: array<string, mixed>, steps: Collection<int, array<string, mixed>>} */
function architectureJobOfCi(): array
{
    $job = Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['tests-architecture'];

    return ['env' => $job['env'], 'steps' => collect($job['steps'])];
}

/**
 * The install step's script, run in a scratch home of its own, the package
 * pinned to RCLONE_PINNED_BYTES. `curl` serves each URL what $sources gives
 * it — bytes, or nothing at all, as a 504 does — and `sudo` records what it is
 * asked to do.
 *
 * @param  array<string, string|null>  $sources  what each URL serves; a URL not named serves nothing
 * @return array{exit: int, output: string, asked: list<string>, installed: list<string>}
 */
function runRcloneInstallStep(string $scratch, array $sources, ?string $cached = null): array
{
    $script = (string) architectureJobOfCi()['steps']->firstWhere('name', 'Install rclone')['run'];
    $package = $scratch.'/home/.cache/rclone-package/rclone-v1.75.0-linux-amd64.deb';

    if ($cached !== null) {
        mkdir(dirname($package), 0o755, true);
        file_put_contents($package, $cached);
    }

    foreach ($sources as $url => $bytes) {
        if ($bytes !== null) {
            file_put_contents($scratch.'/served/'.preg_replace('/[^A-Za-z0-9]/', '_', $url), $bytes);
        }
    }

    file_put_contents($scratch.'/step.sh', $script);
    file_put_contents($scratch.'/bin/curl', <<<BASH
        #!/bin/bash
        # The URL is the last argument, the file to write follows -o.
        out=''; url=''
        while [ \$# -gt 0 ]; do
          case "\$1" in -o) out="\$2"; shift 2 ;; *) url="\$1"; shift ;; esac
        done
        echo "\${url}" >> '{$scratch}/asked'
        served='{$scratch}/served/'"\${url//[^A-Za-z0-9]/_}"
        [ -f "\${served}" ] || { echo 'curl: (22) The requested URL returned error: 504' >&2; exit 22; }
        cp "\${served}" "\${out}"
        BASH);
    file_put_contents($scratch.'/bin/sudo', "#!/bin/bash\necho \"\$*\" >> '{$scratch}/installed'\n");
    chmod($scratch.'/bin/curl', 0o755);
    chmod($scratch.'/bin/sudo', 0o755);

    $process = proc_open(['bash', '--noprofile', '--norc', '-e', $scratch.'/step.sh'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $scratch, [
        'PATH' => $scratch.'/bin:'.getenv('PATH'),
        'HOME' => $scratch.'/home',
        'RCLONE_VERSION' => 'v1.75.0',
        'RCLONE_DEB_SHA256' => hash('sha256', RCLONE_PINNED_BYTES),
    ]);

    expect($process)->not->toBeFalse('could not start the install step');

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    $lines = fn (string $file): array => is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

    return ['exit' => $exit, 'output' => $output, 'asked' => $lines($scratch.'/asked'), 'installed' => $lines($scratch.'/installed')];
}

it('pins the rclone package once, for the cache and the install alike', function () {
    ['env' => $env, 'steps' => $steps] = architectureJobOfCi();
    $names = $steps->pluck('name')->all();

    expect($env['RCLONE_VERSION'])->toBe('v1.75.0')
        ->and($env['RCLONE_DEB_SHA256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($steps->firstWhere('name', 'Cache the rclone package')['with']['key'])
        ->toBe('rclone-${{ env.RCLONE_VERSION }}-${{ env.RCLONE_DEB_SHA256 }}')
        // The cache is restored before the install looks for it.
        ->and(array_search('Cache the rclone package', $names, true))->toBeLessThan(array_search('Install rclone', $names, true))
        // One pin: the install step keeps none of its own.
        ->and($steps->firstWhere('name', 'Install rclone'))->not->toHaveKey('env');
});

it('installs only the pinned package, whichever source has it', function (array $sources, ?string $cached, array $asked, array $warned) {
    $scratch = makeScratchDir('ci-rclone', ['', '/bin', '/served', '/home']);
    $package = $scratch.'/home/.cache/rclone-package/rclone-v1.75.0-linux-amd64.deb';

    try {
        ['exit' => $exit, 'output' => $output, 'asked' => $askedFor, 'installed' => $installed] = runRcloneInstallStep($scratch, $sources, $cached);

        expect($exit)->toBe(0, $output)
            ->and($askedFor)->toBe($asked)
            ->and($installed)->toBe(["dpkg -i {$package}"])
            // What dpkg was given, and what the cache keeps for the next leg.
            ->and(file_get_contents($package))->toBe(RCLONE_PINNED_BYTES)
            ->and(glob($scratch.'/home/.cache/rclone-package/*.part'))->toBe([]);

        foreach ([RCLONE_GITHUB_PACKAGE, RCLONE_SERVER_PACKAGE] as $source) {
            in_array($source, $warned, true)
                ? expect($output)->toContain("::warning title=rclone download::rclone v1.75.0 was not taken from {$source}")
                : expect($output)->not->toContain("was not taken from {$source}");
        }
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'from GitHub' => [[RCLONE_GITHUB_PACKAGE => RCLONE_PINNED_BYTES], null, [RCLONE_GITHUB_PACKAGE], []],
    'from rclone\'s own server while GitHub answers 504' => [[RCLONE_SERVER_PACKAGE => RCLONE_PINNED_BYTES], null, [RCLONE_GITHUB_PACKAGE, RCLONE_SERVER_PACKAGE], [RCLONE_GITHUB_PACKAGE]],
    'from rclone\'s own server while GitHub serves other bytes' => [[RCLONE_GITHUB_PACKAGE => 'something else', RCLONE_SERVER_PACKAGE => RCLONE_PINNED_BYTES], null, [RCLONE_GITHUB_PACKAGE, RCLONE_SERVER_PACKAGE], [RCLONE_GITHUB_PACKAGE]],
    'from the cache, asking no source' => [[], RCLONE_PINNED_BYTES, [], []],
    'from GitHub again when the cached copy is not the pinned package' => [[RCLONE_GITHUB_PACKAGE => RCLONE_PINNED_BYTES], 'a damaged copy', [RCLONE_GITHUB_PACKAGE], []],
]);

it('installs nothing when no source serves the pinned package', function (array $sources) {
    $scratch = makeScratchDir('ci-rclone', ['', '/bin', '/served', '/home']);

    try {
        ['exit' => $exit, 'output' => $output, 'asked' => $asked, 'installed' => $installed] = runRcloneInstallStep($scratch, $sources);

        expect($exit)->not->toBe(0, $output)
            ->and($asked)->toBe([RCLONE_GITHUB_PACKAGE, RCLONE_SERVER_PACKAGE])
            ->and($installed)->toBe([])
            ->and(glob($scratch.'/home/.cache/rclone-package/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'both answering 504' => [[]],
    'both serving other bytes' => [[RCLONE_GITHUB_PACKAGE => 'something else', RCLONE_SERVER_PACKAGE => 'something else']],
]);
