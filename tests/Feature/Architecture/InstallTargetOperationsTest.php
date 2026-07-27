<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 4 slice 2b: transactional installation and real-VPS parity
 * verification for the read-only target operations.
 *
 * These tests exercise the real shipped installer — never a reimplementation
 * of its logic — at three levels:
 *
 *   - real subprocess runs of the script itself, for argument parsing and the
 *     require-root gate (no fixtures needed: --check is genuinely read-only
 *     against the committed repository);
 *   - the installer's two marked, sourceable blocks ("installer core" and
 *     "runtime verification"), extracted and run standalone against scratch
 *     paths, the same technique already established for install-mail-capture
 *     and common's target-registry block;
 *   - the whole constants+functions section sourced with SRC_, DST_,
 *     BACKUP_ROOT and INSTALL_ constants reassigned to scratch paths, calling
 *     perform_apply()/perform_verify() directly (bypassing require_root) for
 *     full end-to-end transactional coverage.
 *
 * No test touches the real host filesystem or the network. Where a full
 * apply/verify run needs installed health-check/status binaries that work
 * without /home/www/rateguru, self-contained stub scripts stand in for them;
 * the registry and the `targets` CLI are used unmodified, since `targets` is
 * already fully standalone (no `common`, no environment dependency).
 */
function installOpsScript(): string
{
    return base_path('infrastructure/scripts/install-target-operations');
}

function installOpsSource(): string
{
    return File::get(installOpsScript());
}

/**
 * Extract one of the installer's marked, sourceable blocks so the behavioural
 * tests run the shipped code itself instead of a copy of it.
 */
function installOpsBlock(string $marker): string
{
    $quoted = preg_quote($marker, '/');
    $pattern = '/^# --- '.$quoted.' \(begin\) ---$\R(.*?)^# --- '.$quoted.' \(end\) ---$/ms';

    expect(preg_match($pattern, installOpsSource(), $matches))
        ->toBe(1, "could not locate the '{$marker}' block in scripts/install-target-operations");

    return $matches[1];
}

/**
 * The whole constants+functions section: everything from `set -Eeuo
 * pipefail` up to (not including) the final `parse_mode_args "$@"` dispatch
 * line. Inert when sourced — nothing in it runs until a caller explicitly
 * invokes one of its functions — which is what lets a test reassign the
 * SRC_, DST_, BACKUP_ROOT and INSTALL_ constants and then call
 * perform_apply() or perform_verify() directly, bypassing require_root.
 */
function installOpsFunctionsSection(): string
{
    $source = installOpsSource();
    $start = strpos($source, "set -Eeuo pipefail\n");
    $end = strpos($source, "\nparse_mode_args \"\$@\"");

    expect($start)->not->toBeFalse('could not locate the functions-section start');
    expect($end)->not->toBeFalse('could not locate the functions-section end');

    return substr($source, $start, $end - $start);
}

/**
 * A fresh scratch directory for one test, removed by the caller via
 * installOpsCleanup(). Pre-creates every subdirectory the fixtures need.
 */
function installOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/install-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/src', '/dst-config', '/dst-bin', '/backups', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function installOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

function installOpsWriteExecutable(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * Minimal `log`/`fail` so an extracted block can run standalone, matching
 * the real script's own contract (fail() prints to stderr and exits 1).
 */
function installOpsHarnessPreamble(): string
{
    return "set -Eeuo pipefail\n"
        ."log()  { printf '[log] %s\\n' \"\$*\"; }\n"
        ."fail() { printf '[ERR] %s\\n' \"\$*\" >&2; exit 1; }\n";
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports), so PATH and every other variable are exactly
 * what the test sets.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function installOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [$exit, $stdout.$stderr];
}

/**
 * Run the real installer script as a subprocess (CLI-level tests: argument
 * parsing, --help, the require-root gate, --check against the real repo).
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function installOpsRunScript(array $arguments, ?string $scratchBin = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'install-ops-cli-');
    file_put_contents($tmp, 'exec '.escapeshellarg(installOpsScript())
        .' '.implode(' ', array_map('escapeshellarg', $arguments))."\n");

    $path = ($scratchBin !== null ? $scratchBin.':' : '').(getenv('PATH') ?: '/usr/bin:/bin');

    try {
        return installOpsExec($tmp, ['PATH' => $path, 'HOME' => getenv('HOME') ?: '/tmp']);
    } finally {
        @unlink($tmp);
    }
}

/**
 * Build and run a harness that sources the installer's whole functions
 * section, reassigns constants to scratch paths, then runs $body.
 *
 * @param  array<string, string>  $vars
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function installOpsRunHarness(string $scratch, array $vars, string $body, array $env = []): array
{
    file_put_contents($scratch.'/functions-section.sh', installOpsFunctionsSection());

    $script = 'source '.escapeshellarg($scratch.'/functions-section.sh')."\n";

    foreach ($vars as $name => $value) {
        $script .= $name.'='.escapeshellarg($value)."\n";
    }

    $script .= $body."\n";

    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return installOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * Run just the "installer core" block (record_target,
 * install_regular_file_transactional, verify_installed_regular_file,
 * rollback_installed_files, files_differ) standalone.
 *
 * @return array{0: int, 1: string}
 */
function installOpsRunCoreHarness(string $scratch, string $driverCode): array
{
    $script = installOpsHarnessPreamble().installOpsBlock('installer core')."\n".$driverCode."\n";
    $harnessPath = $scratch.'/core-harness.sh';
    file_put_contents($harnessPath, $script);

    return installOpsExec($harnessPath, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);
}

/**
 * Run just the "runtime verification" block (no_overrides_env,
 * status_body_after_header, verify_legacy_environment_health,
 * verify_target_health, verify_status_parity, verify_planned_target_rejected,
 * verify_runtime_parity — and verify_installed_files, whose own dependency on
 * verify_installed_regular_file is only resolved if a driver actually calls
 * it) standalone.
 *
 * @param  array<string, string>  $vars
 * @return array{0: int, 1: string}
 */
function installOpsRunRuntimeHarness(string $scratch, array $vars, string $driverCode): array
{
    $script = installOpsHarnessPreamble().installOpsBlock('runtime verification')."\n";

    foreach ($vars as $name => $value) {
        $script .= $name.'='.escapeshellarg($value)."\n";
    }

    $script .= $driverCode."\n";

    $harnessPath = $scratch.'/runtime-harness.sh';
    file_put_contents($harnessPath, $script);

    return installOpsExec($harnessPath, [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);
}

/**
 * A self-contained fake health-check: understands --environment/--target,
 * rejects tits-guru the way the real script does by default, and ignores
 * every RATEGURU_* variable entirely — so it behaves identically whether or
 * not the gated override contract is active, which is exactly what's needed
 * to prove no_overrides_env's unset actually has no effect on a well-behaved
 * installed binary.
 *
 * $failStagingAtPath: if given, --environment staging fails (after
 * installation only) when invoked as exactly that path — lets a test make
 * the *staged* copy (a different, mktemp'd path) succeed while the *final
 * installed* copy fails, to prove a genuine post-install parity failure
 * triggers rollback.
 */
function installOpsHealthCheckStub(?string $failStagingAtPath = null, string $titsGuru = 'reject'): string
{
    $titsGuruClause = match ($titsGuru) {
        'unexpected-success' => 'printf "tits-guru reachable (test stub)\n"; exit 0',
        'wrong-reason' => 'printf "some unrelated stub failure\n" >&2; exit 1',
        default => 'printf "ERROR: target tits-guru has lifecycle=planned, not active\n" >&2; exit 1',
    };

    $failClause = $failStagingAtPath !== null
        ? 'if [[ "$0" == '.escapeshellarg($failStagingAtPath).' ]]; then printf "forced staging failure (test)\n" >&2; exit 1; fi'
        : ':';

    return <<<SH
#!/usr/bin/env bash
set -uo pipefail
target=""
environment=""
while [[ \$# -gt 0 ]]; do
    case "\$1" in
        --target) target="\$2"; shift 2 ;;
        --environment) environment="\$2"; shift 2 ;;
        *) shift ;;
    esac
done

if [[ "\$target" == "tits-guru" ]]; then
    {$titsGuruClause}
fi

if [[ "\$target" == "staging-main" ]]; then
    printf 'health OK (target staging-main, stub)\\n'
    exit 0
fi

if [[ "\$environment" == "staging" ]]; then
    {$failClause}
    printf 'health OK (environment staging, stub)\\n'
    exit 0
fi

printf 'unrecognized selector in health-check stub\\n' >&2
exit 1

SH;
}

/**
 * A self-contained fake status matching the exact literal strings
 * verify_status_parity greps for, with a "Checked at:" header
 * status_body_after_header can normalize. $body/$legacyBody let a test make
 * the two modes agree (parity) or genuinely disagree (mismatch detection).
 */
function installOpsStatusStub(string $body = "Release: v1.2.3\n", ?string $legacyBody = null): string
{
    $legacyBody ??= $body;

    return <<<SH
#!/usr/bin/env bash
set -uo pipefail
target=""
environment=""
while [[ \$# -gt 0 ]]; do
    case "\$1" in
        --target) target="\$2"; shift 2 ;;
        --environment) environment="\$2"; shift 2 ;;
        *) shift ;;
    esac
done

ts="\$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

if [[ "\$target" == "staging-main" ]]; then
    printf 'Target:      staging-main\\nLifecycle:   active\\nEnvironment class: staging\\nChecked at: %s\\n{$body}' "\$ts"
    exit 0
fi

if [[ "\$environment" == "staging" ]]; then
    printf 'Environment: staging\\nChecked at: %s\\n{$legacyBody}' "\$ts"
    exit 0
fi

printf 'unrecognized selector in status stub\\n' >&2
exit 1

SH;
}

/**
 * The standard scratch layout for a full perform_apply/perform_verify
 * integration test: real registry/targets/common (targets is fully
 * standalone; common is never sourced by the stub health-check/status, only
 * bash -n'd), self-contained stub health-check/status as the *candidates*.
 * INSTALL_OWNER/GROUP are derived from the deployment.conf fixture's actual
 * on-disk ownership, never assumed, so this is correct regardless of
 * platform-specific directory setgid inheritance.
 *
 * @return array<string, string>
 */
function installOpsBaseVars(string $scratch, ?string $healthCheckStub = null, ?string $statusStub = null): array
{
    installOpsWriteExecutable($scratch.'/src/health-check', $healthCheckStub ?? installOpsHealthCheckStub());
    installOpsWriteExecutable($scratch.'/src/status', $statusStub ?? installOpsStatusStub());

    $confPath = $scratch.'/dst-config/deployment.conf';
    file_put_contents($confPath, "# scratch deployment.conf fixture\nSTAGING_ROOT=/tmp/unused\n");
    chmod($confPath, 0o640);
    $stat = stat($confPath);
    $ownerId = (string) $stat['uid'];
    $groupId = (string) $stat['gid'];

    return [
        'SRC_REGISTRY' => base_path('infrastructure/config/deployment-targets.json'),
        'SRC_TARGETS' => base_path('infrastructure/scripts/targets'),
        'SRC_COMMON' => base_path('infrastructure/scripts/common'),
        'SRC_HEALTH_CHECK' => $scratch.'/src/health-check',
        'SRC_STATUS' => $scratch.'/src/status',
        'DST_CONFIG_ROOT' => $scratch.'/dst-config',
        'DST_BIN_ROOT' => $scratch.'/dst-bin',
        'DST_REGISTRY' => $scratch.'/dst-config/deployment-targets.json',
        'DST_TARGETS' => $scratch.'/dst-bin/targets',
        'DST_COMMON' => $scratch.'/dst-bin/common',
        'DST_HEALTH_CHECK' => $scratch.'/dst-bin/health-check',
        'DST_STATUS' => $scratch.'/dst-bin/status',
        'DEPLOYMENT_CONF' => $confPath,
        'BACKUP_ROOT' => $scratch.'/backups',
        'REGISTRY_MODE' => '0640',
        'SCRIPT_MODE' => '0755',
        'INSTALL_OWNER' => $ownerId,
        'INSTALL_GROUP' => $groupId,
        'INSTALL_OWNER_ID' => $ownerId,
        'INSTALL_GROUP_ID' => $groupId,
    ];
}

/**
 * Places a healthy, self-contained "currently installed legacy" health-check
 * at the scratch DST_HEALTH_CHECK path — required by every apply preflight,
 * since perform_apply refuses to touch anything unless the *existing*
 * installed health-check already succeeds for --environment staging.
 */
function installOpsPlaceHealthyLegacyHealthCheck(array $vars): void
{
    installOpsWriteExecutable($vars['DST_HEALTH_CHECK'], installOpsHealthCheckStub());
}

// =============================================================================
// Shipping, syntax and architecture
// =============================================================================

it('ships the installer script and its runbook', function () {
    expect(File::exists(installOpsScript()))->toBeTrue();
    expect(File::exists(base_path('infrastructure/runbooks/install-target-operations.md')))->toBeTrue();
});

it('passes bash -n syntax check on the installer', function () {
    $output = [];
    $exit = 0;
    exec('bash -n '.escapeshellarg(installOpsScript()).' 2>&1', $output, $exit);

    expect($exit)->toBe(0, implode("\n", $output));
});

it('keeps every destination a fixed, hardcoded constant — never env- or CLI-overridable', function () {
    $source = installOpsSource();

    // DST_CONFIG_ROOT/DST_BIN_ROOT are plain literals; the five destination
    // paths compose from those two (e.g. "${DST_CONFIG_ROOT}/..."), which is
    // fine — it's still built entirely from fixed constants. What must never
    // appear is a fallback to an environment variable (":-"/":+") or a read
    // of anything RATEGURU_*-shaped.
    foreach (['DST_CONFIG_ROOT', 'DST_BIN_ROOT', 'DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS'] as $name) {
        expect(preg_match('/^'.preg_quote($name, '/').'="[^\n]*"$/m', $source, $matches))
            ->toBe(1, "{$name} must be assigned exactly once as a double-quoted literal");

        expect($matches[0])
            ->not->toContain(':-', "{$name} must not fall back to an environment variable")
            ->not->toContain(':+', "{$name} must not fall back to an environment variable")
            ->not->toContain('RATEGURU_', "{$name} must not be influenced by a RATEGURU_* override");
    }

    // No flag exists to redirect a destination; parse_mode_args only ever
    // recognizes --check/--apply/--verify/-h/--help.
    expect($source)->not->toContain('--dest')
        ->not->toContain('--target-dir')
        ->not->toContain('--prefix');
});

it('never sources common or deployment.conf itself', function () {
    $source = installOpsSource();

    foreach (preg_split('/\R/', $source) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        expect(preg_match('/(^|[;&|]\s*)(source|\.)\s+.*\b(common|deployment\.conf)\b/', $trimmed))
            ->toBe(0, "installer must never source common or deployment.conf: {$trimmed}");
    }
});

it('documents exactly the five files it owns, and what it does not touch, in the runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/install-target-operations.md'));

    expect($runbook)
        ->toContain('infrastructure/config/deployment-targets.json')
        ->toContain('/home/www/rateguru/config/deployment-targets.json')
        ->toContain('infrastructure/scripts/targets')
        ->toContain('/home/www/rateguru/bin/targets')
        ->toContain('infrastructure/scripts/common')
        ->toContain('/home/www/rateguru/bin/common')
        ->toContain('infrastructure/scripts/health-check')
        ->toContain('/home/www/rateguru/bin/health-check')
        ->toContain('infrastructure/scripts/status')
        ->toContain('/home/www/rateguru/bin/status')
        ->toContain('fixed, hardcoded constants')
        ->toContain('/home/www/rateguru/config/deployment.conf')
        ->toContain('deploy`, `rollback`, `cleanup`')
        ->toContain('Why tits-guru remains planned')
        ->toContain('Why no deploy/rollback/cleanup/backup script is changed');
});

it('documents backup location, rollback behaviour and manual restore in the runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/install-target-operations.md'));

    expect($runbook)
        ->toContain('/var/backups/rateguru-target-operations/')
        ->toContain('never deletes old backup directories')
        ->toContain('Rollback failure is reported, never hidden')
        ->toContain('Manually restoring a backup, if automatic rollback itself fails')
        ->toContain('sudo cp -a');
});

// =============================================================================
// CLI-level: argument parsing and the require-root gate (real subprocess,
// real repository — --check is genuinely read-only, so it needs no fixture)
// =============================================================================

it('requires exactly one of --check, --apply or --verify', function () {
    [$exit, $output] = installOpsRunScript([]);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('exactly one of --check, --apply or --verify is required');
});

it('rejects two modes given together', function () {
    [$exit, $output] = installOpsRunScript(['--check', '--apply']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('only one mode may be given');
});

it('rejects the same mode given twice', function () {
    [$exit, $output] = installOpsRunScript(['--verify', '--verify']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('only one mode may be given');
});

it('rejects an unknown argument', function () {
    [$exit, $output] = installOpsRunScript(['--bogus']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('unknown argument: --bogus');
});

it('rejects a stray positional argument', function () {
    [$exit, $output] = installOpsRunScript(['--check', 'extra']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('unknown argument: extra');
});

it('--help prints usage and exits 0 without requiring a mode or root', function () {
    [$exit, $output] = installOpsRunScript(['--help']);

    expect($exit)->toBe(0);
    expect($output)->toContain('install-target-operations --check')
        ->toContain('install-target-operations --apply')
        ->toContain('install-target-operations --verify');
});

it('--check succeeds read-only against the real repository, with no root required', function () {
    [$exit, $output] = installOpsRunScript(['--check']);

    expect($exit)->toBe(0, $output);
    expect($output)
        ->toContain('all five source files are present regular files')
        ->toContain('bash -n passed for all four source shell scripts')
        ->toContain('source registry is valid JSON')
        ->toContain('required host tools present')
        ->toContain('check passed');
});

it('--apply requires root', function () {
    [$exit, $output] = installOpsRunScript(['--apply']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('this command must be executed as root');
});

it('--verify requires root', function () {
    [$exit, $output] = installOpsRunScript(['--verify']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('this command must be executed as root');
});

// =============================================================================
// Installer core block: record_target, install_regular_file_transactional,
// verify_installed_regular_file, rollback_installed_files, files_differ —
// extracted and exercised directly against scratch paths owned by the
// current (non-root) test user.
// =============================================================================

it('installs a new file with the exact requested owner, group, mode and content', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "committed content\n");
        $dst = $scratch.'/dst-file';
        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            verify_installed_regular_file {$dst} {$uid} {$gid} 0640 {$src}
            printf 'OK\\n'
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OK');
        expect(file_get_contents($dst))->toBe("committed content\n");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('refuses to install over an existing destination symlink, leaving it untouched', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $realTarget = $scratch.'/real-target';
        file_put_contents($realTarget, "original protected content\n");
        $dst = $scratch.'/dst-symlink';
        symlink($realTarget, $dst);

        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            BASH);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing symlink');
        expect(is_link($dst))->toBeTrue('the symlink itself must be left in place');
        expect(file_get_contents($realTarget))->toBe("original protected content\n");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_installed_regular_file catches ownership, mode, group-writability and content mismatches independently', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "reference content\n");
        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        // Ownership mismatch: a bogus expected uid the file was never given.
        $dst1 = $scratch.'/dst-owner';
        file_put_contents($dst1, "reference content\n");
        chmod($dst1, 0o640);
        [$exit1, $out1] = installOpsRunCoreHarness($scratch, "verify_installed_regular_file {$dst1} 999999 {$gid} 0640 {$src}");
        expect($exit1)->not->toBe(0);
        expect($out1)->toContain('wrong ownership');

        // Mode mismatch, compared numerically: stat never prints a leading
        // zero, so a naive string comparison against "0640" always fails —
        // this is the regression test for that bug.
        $dst2 = $scratch.'/dst-mode';
        file_put_contents($dst2, "reference content\n");
        chmod($dst2, 0o640);
        [$exit2, $out2] = installOpsRunCoreHarness($scratch, "verify_installed_regular_file {$dst2} {$uid} {$gid} 0640 {$src}");
        expect($exit2)->toBe(0, $out2);

        $dst2b = $scratch.'/dst-mode-mismatch';
        file_put_contents($dst2b, "reference content\n");
        chmod($dst2b, 0o644);
        [$exit2b, $out2b] = installOpsRunCoreHarness($scratch, "verify_installed_regular_file {$dst2b} {$uid} {$gid} 0640 {$src}");
        expect($exit2b)->not->toBe(0);
        expect($out2b)->toContain('wrong mode');

        // Group- or other-writable, even with the right owner/mode-family.
        $dst3 = $scratch.'/dst-writable';
        file_put_contents($dst3, "reference content\n");
        chmod($dst3, 0o646);
        [$exit3, $out3] = installOpsRunCoreHarness($scratch, "verify_installed_regular_file {$dst3} {$uid} {$gid} 0646 {$src}");
        expect($exit3)->not->toBe(0);
        expect($out3)->toContain('must not be group- or other-writable');

        // Content differs from the committed source, everything else correct.
        $dst4 = $scratch.'/dst-content';
        file_put_contents($dst4, "tampered content\n");
        chmod($dst4, 0o640);
        [$exit4, $out4] = installOpsRunCoreHarness($scratch, "verify_installed_regular_file {$dst4} {$uid} {$gid} 0640 {$src}");
        expect($exit4)->not->toBe(0);
        expect($out4)->toContain('content differs');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rollback restores the previous content of a pre-existing destination', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-file';
        file_put_contents($dst, "OLD PREVIOUS CONTENT\n");
        chmod($dst, 0o640);
        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            [[ "\$(cat {$dst})" == "new content" ]] || fail "install did not take effect"
            rollback_installed_files
            printf 'ROLLBACK_STATUS:%d\\n' \$?
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ROLLBACK_STATUS:0');
        expect(file_get_contents($dst))->toBe("OLD PREVIOUS CONTENT\n");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rollback removes a destination that did not exist before this run', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "brand new content\n");
        $dst = $scratch.'/dst-file';
        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        expect(file_exists($dst))->toBeFalse();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            [[ -e {$dst} ]] || fail "install did not create the destination"
            rollback_installed_files
            printf 'ROLLBACK_STATUS:%d\\n' \$?
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ROLLBACK_STATUS:0');
        expect(file_exists($dst))->toBeFalse('rollback must remove a destination this run created');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rollback reports incomplete, without masking the original failure, when a backup has gone missing', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-file';
        file_put_contents($dst, "OLD PREVIOUS CONTENT\n");
        $uid = (string) posix_geteuid();
        $gid = (string) posix_getegid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            rm -rf "\${BACKUP_DIR}"
            rollback_status=0
            rollback_installed_files || rollback_status=\$?
            printf 'ROLLBACK_STATUS:%d\\n' "\${rollback_status}"
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ROLLBACK_STATUS:1');
        expect($output)->toContain('no backup found');
    } finally {
        installOpsCleanup($scratch);
    }
});

// =============================================================================
// Runtime verification block: no_overrides_env, status_body_after_header,
// verify_status_parity, verify_planned_target_rejected — extracted and
// exercised against self-contained stub health-check/status.
// =============================================================================

it('no_overrides_env unsets every RATEGURU_* override regardless of what the caller set', function () {
    $scratch = installOpsScratchDir();

    try {
        $probe = $scratch.'/bin/probe';
        installOpsWriteExecutable($probe, "#!/usr/bin/env bash\n"
            .'for v in RATEGURU_ALLOW_TEST_OVERRIDES RATEGURU_COMMON_FILE RATEGURU_DEPLOYMENT_CONF_FILE RATEGURU_TARGET_REGISTRY_FILE RATEGURU_TARGETS_CLI RATEGURU_HEALTH_CHECK_CLI; do'."\n"
            .'  if [[ -n "${!v:-}" ]]; then printf "LEAKED:%s\n" "$v"; fi'."\n"
            .'done'."\n"
            .'printf "PROBE_DONE\n"'."\n");

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, [], <<<BASH
            RATEGURU_ALLOW_TEST_OVERRIDES=true
            RATEGURU_COMMON_FILE=/tmp/poisoned-common
            RATEGURU_TARGETS_CLI=/tmp/poisoned-targets
            export RATEGURU_ALLOW_TEST_OVERRIDES RATEGURU_COMMON_FILE RATEGURU_TARGETS_CLI
            no_overrides_env {$probe}
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PROBE_DONE');
        expect($output)->not->toContain('LEAKED:');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('status_body_after_header normalizes the timestamp and strips the mode-specific header', function () {
    $scratch = installOpsScratchDir();

    [$exit, $output] = installOpsRunRuntimeHarness($scratch, [], <<<'BASH'
        target="$(printf 'Target:      staging-main\nLifecycle:   active\nEnvironment class: staging\nChecked at: 2026-01-01T00:00:00Z\nRelease: v1.2.3')"
        legacy="$(printf 'Environment: staging\nChecked at: 2026-01-01T00:00:05Z\nRelease: v1.2.3')"
        a="$(status_body_after_header "${target}")"
        b="$(status_body_after_header "${legacy}")"
        [[ "${a}" == "${b}" ]] && printf 'BODIES_MATCH\n' || printf 'BODIES_DIFFER\n'
        printf 'A:[%s]\n' "${a}"
        BASH);

    installOpsCleanup($scratch);

    expect($exit)->toBe(0, $output);
    expect($output)->toContain('BODIES_MATCH');
    expect($output)->toContain('Checked at: NORMALIZED');
    expect($output)->not->toContain('2026-01-01T00:00:00Z');
});

it('verify_status_parity fails with a clear message when target and legacy bodies genuinely disagree', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars(
            $scratch,
            null,
            installOpsStatusStub("Release: v1.2.3\n", "Release: v9.9.9-DIFFERENT\n"),
        );
        $vars['DST_STATUS'] = $vars['SRC_STATUS'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_status_parity');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('disagree beyond their headers');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_planned_target_rejected fails when tits-guru unexpectedly succeeds', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, installOpsHealthCheckStub(null, 'unexpected-success'));
        $vars['DST_HEALTH_CHECK'] = $vars['SRC_HEALTH_CHECK'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_planned_target_rejected');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unexpectedly succeeded');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_planned_target_rejected fails when the rejection happens for the wrong reason', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, installOpsHealthCheckStub(null, 'wrong-reason'));
        $vars['DST_HEALTH_CHECK'] = $vars['SRC_HEALTH_CHECK'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_planned_target_rejected');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed for the wrong reason');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_planned_target_rejected does not roll back already-installed files when it runs under the apply trap', function () {
    // Regression test: trap on_apply_error ERR EXIT is inherited into every
    // $(...) command substitution's subshell. The tits-guru check is
    // *deliberately* expected to fail — without `trap - ERR EXIT` running
    // first inside that subshell (see the comment at both call sites in the
    // real script), that expected failure fires on_apply_error *inside the
    // subshell*, silently rolling back a real installation while the outer
    // script still reports success. This must call the *real*
    // rollback_installed_files (not a no-op stand-in) against a backup whose
    // content is deliberately different from what's currently installed —
    // only that way does a spurious rollback become observable at all.
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);

        // Simulate perform_apply's exact conditions: the candidate is already
        // installed at DST_HEALTH_CHECK, with a *different* backup on record
        // (standing in for "whatever preceded this install"), and the real
        // apply trap armed.
        $installedContent = installOpsHealthCheckStub();
        file_put_contents($vars['DST_HEALTH_CHECK'], $installedContent);
        chmod($vars['DST_HEALTH_CHECK'], 0o755);
        $backupDir = $scratch.'/backups/pre-existing-run';
        mkdir($backupDir.dirname($vars['DST_HEALTH_CHECK']), 0o700, true);
        $backupContent = "PRE-EXISTING-BACKUP-MARKER (must never appear after a correct call)\n";
        file_put_contents($backupDir.$vars['DST_HEALTH_CHECK'], $backupContent);

        $script = installOpsHarnessPreamble()
            .installOpsBlock('installer core')."\n"
            .installOpsBlock('runtime verification')."\n";

        foreach ($vars as $name => $value) {
            $script .= $name.'='.escapeshellarg($value)."\n";
        }

        $script .= <<<BASH
            APPLY_COMMITTED=false
            BACKUP_DIR={$backupDir}
            ROLLBACK_RESTORE=({$vars['DST_HEALTH_CHECK']})
            ROLLBACK_REMOVE=()
            on_apply_error() {
                local code=\$?
                trap - ERR EXIT
                rollback_installed_files || true
                printf 'ON_APPLY_ERROR_FIRED code=%s\\n' "\${code}" >&2
                exit "\${code}"
            }
            trap on_apply_error ERR EXIT
            verify_planned_target_rejected
            trap - ERR EXIT
            printf 'SURVIVED_WITHOUT_ROLLBACK\\n'

            BASH;

        $harnessPath = $scratch.'/trap-regression-harness.sh';
        file_put_contents($harnessPath, $script);
        [$exit, $output] = installOpsExec($harnessPath, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
        ]);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SURVIVED_WITHOUT_ROLLBACK');
        expect($output)->not->toContain('ON_APPLY_ERROR_FIRED');
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($installedContent);
    } finally {
        installOpsCleanup($scratch);
    }
});

// =============================================================================
// Full perform_apply / perform_verify integration: the whole functions
// section sourced with SRC_*/DST_*/BACKUP_ROOT/INSTALL_* reassigned to
// scratch paths, self-contained stub health-check/status as the candidates,
// the real registry/targets/common otherwise.
// =============================================================================

it('a successful apply installs all five files with correct ownership, mode and content, and creates a timestamped backup', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('apply complete');

        foreach ([
            ['DST_REGISTRY', 'SRC_REGISTRY', '0640'],
            ['DST_TARGETS', 'SRC_TARGETS', '0755'],
            ['DST_COMMON', 'SRC_COMMON', '0755'],
            ['DST_HEALTH_CHECK', 'SRC_HEALTH_CHECK', '0755'],
            ['DST_STATUS', 'SRC_STATUS', '0755'],
        ] as [$dstKey, $srcKey, $mode]) {
            $dst = $vars[$dstKey];
            expect(file_exists($dst))->toBeTrue("{$dstKey} must exist");
            expect(is_link($dst))->toBeFalse();
            expect(file_get_contents($dst))->toBe(file_get_contents($vars[$srcKey]));
            expect(substr(sprintf('%o', fileperms($dst)), -4))->toBe($mode);
        }

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->not->toBeEmpty('apply must create a timestamped backup directory');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('apply is idempotent: running it again succeeds and leaves the same correct files in place', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$exit1, $out1] = installOpsRunHarness($scratch, $vars, 'perform_apply');
        expect($exit1)->toBe(0, $out1);

        [$exit2, $out2] = installOpsRunHarness($scratch, $vars, 'perform_apply');
        expect($exit2)->toBe(0, $out2);
        expect($out2)->toContain('apply complete');

        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS'] as $key) {
            expect(file_exists($vars[$key]))->toBeTrue();
        }

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect(count($backups))->toBeGreaterThanOrEqual(1);
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify passes against a successfully installed set and makes no filesystem changes', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$applyExit, $applyOut] = installOpsRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0, $applyOut);

        $before = [];
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS'] as $key) {
            clearstatcache(true, $vars[$key]);
            $before[$key] = [filemtime($vars[$key]), md5_file($vars[$key])];
        }
        $backupsBefore = glob($scratch.'/backups/*', GLOB_ONLYDIR);

        [$verifyExit, $verifyOut] = installOpsRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->toBe(0, $verifyOut);
        expect($verifyOut)->toContain('PASS: installed files and runtime behaviour verified');

        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS'] as $key) {
            clearstatcache(true, $vars[$key]);
            expect([filemtime($vars[$key]), md5_file($vars[$key])])->toBe($before[$key], "{$key} must be unchanged by --verify");
        }

        $backupsAfter = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backupsAfter)->toBe($backupsBefore, '--verify must create no backup');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a failing legacy preflight check changes no destination file', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);

        // The *currently installed* legacy health-check is broken — apply
        // must refuse before touching anything, including itself.
        installOpsWriteExecutable($vars['DST_HEALTH_CHECK'], "#!/usr/bin/env bash\nexit 1\n");

        $sentinels = [];
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_STATUS'] as $key) {
            $sentinels[$key] = "OLD SENTINEL: {$key}\n";
            file_put_contents($vars[$key], $sentinels[$key]);
        }

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('currently installed legacy staging health check failed');

        foreach ($sentinels as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be untouched");
        }
        expect(file_exists($vars['DST_HEALTH_CHECK']))->toBeTrue();

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->toBeEmpty('no backup directory should be created before the legacy preflight passes');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a failing staged candidate check changes no destination file', function () {
    $scratch = installOpsScratchDir();

    try {
        // The candidate health-check (this run's source) fails
        // --target staging-main — staged verification must catch this before
        // any real destination is written.
        $brokenCandidate = <<<'SH'
            #!/usr/bin/env bash
            printf 'candidate is broken (test)\n' >&2
            exit 1
            SH;
        $vars = installOpsBaseVars($scratch, $brokenCandidate);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        $sentinels = [];
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_STATUS'] as $key) {
            $sentinels[$key] = "OLD SENTINEL: {$key}\n";
            file_put_contents($vars[$key], $sentinels[$key]);
        }
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('staged health-check');

        foreach ($sentinels as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be untouched");
        }
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($healthCheckBefore);
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a post-install runtime-parity failure rolls back every touched destination: restores what existed, removes what this run created', function () {
    $scratch = installOpsScratchDir();

    try {
        // The candidate health-check fails --environment staging, but only
        // once invoked as the exact final installed path — the staged copy
        // (a different, mktemp'd path) passes, so this is a genuine
        // post-install parity failure, not a caught-earlier one.
        $vars = installOpsBaseVars($scratch);
        $failingCandidate = installOpsHealthCheckStub($vars['DST_HEALTH_CHECK']);
        installOpsWriteExecutable($vars['SRC_HEALTH_CHECK'], $failingCandidate);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        // registry/targets/common pre-exist (restore path); status does not
        // (remove path).
        $oldContent = [
            'DST_REGISTRY' => "OLD registry\n",
            'DST_TARGETS' => "OLD targets\n",
            'DST_COMMON' => "OLD common\n",
        ];
        foreach ($oldContent as $key => $content) {
            file_put_contents($vars[$key], $content);
        }
        expect(file_exists($vars['DST_STATUS']))->toBeFalse();
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('files installed; verifying before committing');
        expect($output)->toContain('rollback complete: previous files restored');
        expect($output)->toContain('confirmed: legacy staging health check still succeeds after rollback');

        foreach ($oldContent as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be restored to its previous content");
        }
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($healthCheckBefore, 'health-check must be restored to its previous content');
        expect(file_exists($vars['DST_STATUS']))->toBeFalse('status must be removed — it did not exist before this run');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a successful apply never creates, contacts, or provisions anything for tits-guru', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('correctly rejected (lifecycle=planned)');

        $scratchFiles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $scratchFiles[] = $file->getPathname();
        }

        foreach ($scratchFiles as $path) {
            expect($path)->not->toContain('tits-guru', "no tits-guru path should exist under the scratch install tree: {$path}");
        }
    } finally {
        installOpsCleanup($scratch);
    }
});
