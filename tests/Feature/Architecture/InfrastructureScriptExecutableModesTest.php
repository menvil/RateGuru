<?php

use Illuminate\Support\Facades\File;

/**
 * Guards the regression that made install-target-operations unusable on the
 * real staging VPS: every file under infrastructure/scripts arrived at mode
 * 0640 because a Git blob's own stored index mode was wrong. deploy's own
 * permission normalization (see InfrastructureBaselineTest.php) only ever
 * preserves whatever executable bit the release archive already carries —
 * it cannot invent one that Git never recorded. This test is the thing that
 * must catch a bad mode before it ever reaches an artifact build.
 *
 * Deliberately does *not* infer executability from a shebang line: common
 * has one too (it is a plain bash file, just never invoked directly), so a
 * shebang-based check would incorrectly demand it be executable.
 */
function infrastructureScriptGitModes(): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', '-C', base_path(), 'ls-files', '--stage', '--', 'infrastructure/scripts'],
        $descriptors,
        $pipes,
    );

    expect($process)->not->toBeFalse('could not start git ls-files');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, "git ls-files --stage failed: {$stderr}");

    $modes = [];

    foreach (preg_split('/\R/', trim($stdout)) as $line) {
        if ($line === '') {
            continue;
        }

        // "<mode> <blob-sha> <stage>\t<path>"
        expect(preg_match('/^(\d+) [0-9a-f]+ \d+\t(.+)$/', $line, $matches))
            ->toBe(1, "unexpected git ls-files --stage line: {$line}");

        $mode = $matches[1];
        $path = $matches[2];

        // Only flat files directly in infrastructure/scripts/, matching
        // "git ls-files --stage infrastructure/scripts" from the runbook —
        // a subdirectory would need its own classification, not a blanket one.
        if (str_contains(substr($path, strlen('infrastructure/scripts/')), '/')) {
            continue;
        }

        $modes[$path] = $mode;
    }

    return $modes;
}

it('keeps every infrastructure CLI script executable and common non-executable in the Git index', function () {
    $cliAllowlist = [
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/cleanup',
        'infrastructure/scripts/deploy',
        'infrastructure/scripts/health-check',
        'infrastructure/scripts/install-mail-capture',
        'infrastructure/scripts/install-public-storage-access',
        'infrastructure/scripts/install-target-operations',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/restore-test',
        'infrastructure/scripts/rollback',
        'infrastructure/scripts/status',
        'infrastructure/scripts/status-mail-capture',
        'infrastructure/scripts/targets',
        'infrastructure/scripts/verify-mail-capture',
    ];

    $sourcedLibrary = 'infrastructure/scripts/common';

    $modes = infrastructureScriptGitModes();

    foreach ($cliAllowlist as $path) {
        expect(array_key_exists($path, $modes))->toBeTrue("expected CLI is missing from the repository: {$path}");
        expect($modes[$path])->toBe('100755', "{$path} must be Git mode 100755 (executable) — is {$modes[$path]}");
    }

    expect(array_key_exists($sourcedLibrary, $modes))->toBeTrue("sourced library is missing from the repository: {$sourcedLibrary}");
    expect($modes[$sourcedLibrary])
        ->toBe('100644', "{$sourcedLibrary} is a sourced library, never executed directly, and must be Git mode 100644 — is {$modes[$sourcedLibrary]}");

    // No expected CLI is missing from the allowlist, and nothing untracked
    // slipped in unclassified: the flat files directly under
    // infrastructure/scripts/ are exactly the allowlist plus common.
    $expectedPaths = [...$cliAllowlist, $sourcedLibrary];
    sort($expectedPaths);
    $actualPaths = array_keys($modes);
    sort($actualPaths);

    expect($actualPaths)->toBe($expectedPaths, 'infrastructure/scripts/ contains a file this test does not know how to classify — add it to the CLI allowlist or the sourced-library exemption above');
});

it('carries every infrastructure script through checkout and deploy normalization with the correct final mode', function () {
    // Ties the Git-index guarantee above to InfrastructureBaselineTest.php's
    // generic proof of deploy's own `-perm /111` normalization, end to end,
    // for the *real* shipped files rather than synthetic fixtures: git
    // checkout is a standard, well-defined operation (mode 100755 checks out
    // executable, 100644 does not, on any Linux runner), and rsync --archive
    // / tar both preserve whatever mode is already on disk — so the working
    // tree's current on-disk permissions are exactly what a fresh checkout,
    // rsync'd and tarred, would also produce.
    $modes = infrastructureScriptGitModes();

    foreach ($modes as $path => $mode) {
        clearstatcache(true, base_path($path));
        $onDisk = is_executable(base_path($path));
        $expectedExecutable = $mode === '100755';

        expect($onDisk)->toBe(
            $expectedExecutable,
            "working tree permissions for {$path} (executable={$onDisk}) do not match its Git index mode {$mode} — checkout, rsync and tar would disagree with what Git actually records",
        );
    }

    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    expect(preg_match(
        '/# --- normalize release permissions \(begin\) ---\n(.*?)\n# --- normalize release permissions \(end\) ---/s',
        $deploy,
        $matches,
    ))->toBe(1, 'could not locate the release permission normalization block in scripts/deploy');

    $block = $matches[1];

    exec('find '.escapeshellarg(sys_get_temp_dir()).' -maxdepth 0 -perm /111 >/dev/null 2>&1', $probe, $probeExit);
    if ($probeExit !== 0) {
        test()->markTestSkipped('host find does not support `-perm /111` (GNU find only)');
    }

    $root = sys_get_temp_dir().'/infra-script-modes-e2e-'.uniqid('', true);

    try {
        mkdir($root.'/infrastructure/scripts', 0o755, true);

        foreach (array_keys($modes) as $path) {
            $dest = $root.'/'.$path;
            copy(base_path($path), $dest);
            chmod($dest, is_executable(base_path($path)) ? 0o755 : 0o644);
        }

        $script = 'set -euo pipefail'."\n".'TEMP_RELEASE_ROOT='.escapeshellarg($root)."\n".$block;

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "normalization block failed:\n".implode("\n", $output));

        clearstatcache();

        foreach (array_keys($modes) as $path) {
            $finalMode = fileperms($root.'/'.$path) & 0o777;
            $expected = $modes[$path] === '100755' ? 0o750 : 0o640;

            expect($finalMode)->toBe(
                $expected,
                sprintf('%s ended up mode %o after deploy normalization, expected %o', $path, $finalMode, $expected),
            );
        }
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
