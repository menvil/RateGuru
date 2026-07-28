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
    // fd 2 is redirected onto fd 1 at the descriptor level (not read via a
    // second pipe) so there is only ever one stream to drain: reading all of
    // stdout, then separately reading all of stderr, deadlocks if the child
    // fills the stderr pipe's OS buffer while nobody is reading it yet —
    // exactly the failure mode a harness that logs a lot (or one day gains
    // -x tracing) could hit.
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
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

    // $scratch/bin first: lets a test shadow a single coreutil (e.g. a stub
    // `mv` that always fails) without touching anything else on PATH.
    return installOpsExec($harnessPath, [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);
}

/**
 * Snapshot a directory's ownership and mode, for asserting it is byte-for-
 * byte unchanged (this installer only ever validates its two destination
 * directories, never mutates them) across an apply, a failed apply, or a
 * rollback.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function installOpsStatDir(string $dir): array
{
    clearstatcache(true, $dir);
    $stat = stat($dir);
    expect($stat)->not->toBeFalse("could not stat directory: {$dir}");

    return [$stat['uid'], $stat['gid'], $stat['mode'] & 0o7777];
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
 * Renders one mode's realistic status body: the same Releases / Current
 * release metadata / Health / Recent deployment history section structure
 * status_deployment_state expects (each preceded by its own header line and
 * a dashed separator, exactly matching the real infrastructure/scripts/status),
 * with a literal, bash-unexpanded `${ts}` wherever a timestamp belongs — the
 * caller's bash heredoc expands it at runtime, once per invocation, so target
 * and legacy naturally get *different* timestamps in their Health section
 * the same way two real, separate health-check runs would.
 *
 * @param  array{current: string, currentPath: string, previous: string, previousPath: string, releaseJson: string, history: string, healthy: bool}  $state
 */
function installOpsStatusBody(array $state, string $label, ?string $omitSection = null, ?string $duplicateSection = null): string
{
    $healthyText = $state['healthy'] ? 'healthy' : 'unhealthy';

    $sections = [
        'Releases' => "Releases\n----------------------------------------\n"
            ."Current:      {$state['current']}\n"
            ."Current path: {$state['currentPath']}\n"
            ."Previous:     {$state['previous']}\n"
            ."Previous path: {$state['previousPath']}\n",
        'Current release metadata' => "Current release metadata\n----------------------------------------\n"
            ."{$state['releaseJson']}\n",
        'Health' => "Health\n----------------------------------------\n"
            ."[\${ts}] {$label} health check passed: http://127.0.0.1/up\n"
            ."Status: {$healthyText}\n",
        'Recent deployment history' => "Recent deployment history\n----------------------------------------\n"
            ."{$state['history']}\n",
    ];

    if ($omitSection !== null) {
        unset($sections[$omitSection]);
    }

    // Emits the named section's header line (and content) a second time
    // immediately after the first — covering assert_status_sections_present's
    // upper bound: a section marker appearing *twice* must fail exactly like
    // one appearing zero times, not silently pass because "at least one" was
    // found.
    $rendered = [];
    foreach ($sections as $name => $content) {
        $rendered[] = $content;
        if ($duplicateSection === $name) {
            $rendered[] = $content;
        }
    }

    return "Checked at: \${ts}\n\n".implode("\n", $rendered);
}

/**
 * A self-contained fake status producing the same realistic section
 * structure the real status script does. Every piece of deployment state
 * (current/previous release and path, release.json, history) defaults to
 * the same value on both --target and --environment output — parity as it
 * should be — and a `legacyXxx` override lets a test make exactly one piece
 * genuinely disagree. Health always legitimately differs regardless (its own
 * selector label plus a fresh `${ts}` each run), which is exactly the
 * scenario status_deployment_state must ignore rather than compare.
 */
function installOpsStatusStub(
    string $current = 'v1.0.0-20260101-000000-abc1234',
    string $currentPath = '/srv/releases/v1.0.0-20260101-000000-abc1234',
    string $previous = 'v0.9.0-20251201-000000-def5678',
    string $previousPath = '/srv/releases/v0.9.0-20251201-000000-def5678',
    string $releaseJson = '{"version": "v1.0.0"}',
    string $history = '{"release": "v1.0.0"}',
    bool $healthy = true,
    ?string $legacyCurrent = null,
    ?string $legacyCurrentPath = null,
    ?string $legacyPrevious = null,
    ?string $legacyPreviousPath = null,
    ?string $legacyReleaseJson = null,
    ?string $legacyHistory = null,
    ?bool $legacyHealthy = null,
    ?string $legacyOmitSection = null,
    ?string $legacyDuplicateSection = null,
): string {
    $targetState = compact('current', 'currentPath', 'previous', 'previousPath', 'releaseJson', 'history', 'healthy');

    $legacyState = [
        'current' => $legacyCurrent ?? $current,
        'currentPath' => $legacyCurrentPath ?? $currentPath,
        'previous' => $legacyPrevious ?? $previous,
        'previousPath' => $legacyPreviousPath ?? $previousPath,
        'releaseJson' => $legacyReleaseJson ?? $releaseJson,
        'history' => $legacyHistory ?? $history,
        'healthy' => $legacyHealthy ?? $healthy,
    ];

    $targetBody = installOpsStatusBody($targetState, 'staging-main');
    $legacyBody = installOpsStatusBody($legacyState, 'staging', $legacyOmitSection, $legacyDuplicateSection);

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
    cat <<STATUS_STUB_BODY
Target:      staging-main
Lifecycle:   active
Environment class: staging
{$targetBody}
STATUS_STUB_BODY
    exit 0
fi

if [[ "\$environment" == "staging" ]]; then
    cat <<STATUS_STUB_BODY
Environment: staging
{$legacyBody}
STATUS_STUB_BODY
    exit 0
fi

printf 'unrecognized selector in status stub\\n' >&2
exit 1

SH;
}

/**
 * A self-contained stub `cleanup`: understands --environment/--target plus
 * --dry-run/--apply (ignored), rejects tits-guru the way the real script
 * does by default, and prints a configurable, fixed set of "DRY RUN would
 * delete: ..." lines per selector — defaulting to none, so both modes agree
 * trivially unless a test deliberately gives them different sets. Mirrors
 * installOpsHealthCheckStub()'s shape and options exactly.
 *
 * @param  list<string>  $envCandidates
 * @param  ?list<string>  $targetCandidates  defaults to $envCandidates when null
 */
function installOpsCleanupStub(
    ?string $failStagingAtPath = null,
    string $titsGuru = 'reject',
    array $envCandidates = [],
    ?array $targetCandidates = null,
): string {
    $targetCandidates ??= $envCandidates;

    $titsGuruClause = match ($titsGuru) {
        'unexpected-success' => 'printf "tits-guru reachable (test stub)\n"; exit 0',
        'wrong-reason' => 'printf "some unrelated stub failure\n" >&2; exit 1',
        default => 'printf "ERROR: target tits-guru has lifecycle=planned, not active\n" >&2; exit 1',
    };

    $failClause = $failStagingAtPath !== null
        ? 'if [[ "$0" == '.escapeshellarg($failStagingAtPath).' ]]; then printf "forced staging failure (test)\n" >&2; exit 1; fi'
        : ':';

    $renderCandidates = fn (array $ids): string => implode("\n", array_map(
        fn (string $id) => "printf 'DRY RUN would delete: {$id}\\n'",
        $ids,
    ));

    $envLines = $renderCandidates($envCandidates);
    $targetLines = $renderCandidates($targetCandidates);

    return <<<SH
#!/usr/bin/env bash
set -uo pipefail
target=""
environment=""
while [[ \$# -gt 0 ]]; do
    case "\$1" in
        --target) target="\$2"; shift 2 ;;
        --environment) environment="\$2"; shift 2 ;;
        --dry-run|--apply) shift ;;
        *) shift ;;
    esac
done

if [[ "\$target" == "tits-guru" ]]; then
    {$titsGuruClause}
fi

if [[ "\$target" == "staging-main" ]]; then
    {$targetLines}
    printf 'cleanup dry run finished (stub, target)\\n'
    exit 0
fi

if [[ "\$environment" == "staging" ]]; then
    {$failClause}
    {$envLines}
    printf 'cleanup dry run finished (stub, environment)\\n'
    exit 0
fi

printf 'unrecognized selector in cleanup stub\\n' >&2
exit 1

SH;
}

/**
 * The standard scratch layout for a full perform_apply/perform_verify
 * integration test: real registry/targets/common (targets is fully
 * standalone; common is never sourced by the stub health-check/status/
 * cleanup, only bash -n'd), self-contained stub health-check/status/cleanup
 * as the *candidates*.
 *
 * INSTALL_OWNER/GROUP are the current process's own euid/egid, explicitly
 * applied (chown/chgrp — always permitted onto one's own identity, even
 * without root) to deployment.conf *and* the dst-config/dst-bin directories
 * this installer now only validates, never creates. Explicit, not inferred
 * from whatever a platform's directory-creation defaults happen to produce
 * (macOS's /tmp setgid inheritance can otherwise give a scratch directory a
 * different group than the file created inside it) — this is what makes
 * validate_destination_directories's ownership check pass deterministically
 * on every platform this suite runs on.
 *
 * @return array<string, string>
 */
function installOpsBaseVars(
    string $scratch,
    ?string $healthCheckStub = null,
    ?string $statusStub = null,
    ?string $cleanupStub = null,
): array {
    installOpsWriteExecutable($scratch.'/src/health-check', $healthCheckStub ?? installOpsHealthCheckStub());
    installOpsWriteExecutable($scratch.'/src/status', $statusStub ?? installOpsStatusStub());
    installOpsWriteExecutable($scratch.'/src/cleanup', $cleanupStub ?? installOpsCleanupStub());

    $ownerId = (string) getmyuid();
    $groupId = (string) getmygid();

    $confPath = $scratch.'/dst-config/deployment.conf';
    file_put_contents($confPath, "# scratch deployment.conf fixture\nSTAGING_ROOT=/tmp/unused\n");
    chmod($confPath, 0o640);
    chown($confPath, (int) $ownerId);
    chgrp($confPath, (int) $groupId);

    foreach ([$scratch.'/dst-config', $scratch.'/dst-bin'] as $dir) {
        chmod($dir, 0o755);
        chown($dir, (int) $ownerId);
        chgrp($dir, (int) $groupId);
    }

    return [
        'SRC_SELF' => base_path('infrastructure/scripts/install-target-operations'),
        'SRC_REGISTRY' => base_path('infrastructure/config/deployment-targets.json'),
        'SRC_TARGETS' => base_path('infrastructure/scripts/targets'),
        'SRC_COMMON' => base_path('infrastructure/scripts/common'),
        'SRC_HEALTH_CHECK' => $scratch.'/src/health-check',
        'SRC_STATUS' => $scratch.'/src/status',
        'SRC_CLEANUP' => $scratch.'/src/cleanup',
        'DST_CONFIG_ROOT' => $scratch.'/dst-config',
        'DST_BIN_ROOT' => $scratch.'/dst-bin',
        'DST_REGISTRY' => $scratch.'/dst-config/deployment-targets.json',
        'DST_TARGETS' => $scratch.'/dst-bin/targets',
        'DST_COMMON' => $scratch.'/dst-bin/common',
        'DST_HEALTH_CHECK' => $scratch.'/dst-bin/health-check',
        'DST_STATUS' => $scratch.'/dst-bin/status',
        'DST_CLEANUP' => $scratch.'/dst-bin/cleanup',
        'DEPLOYMENT_CONF' => $confPath,
        'BACKUP_ROOT' => $scratch.'/backups',
        'REGISTRY_MODE' => '0640',
        'COMMON_MODE' => '0644',
        'CLI_MODE' => '0755',
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

    // DST_CONFIG_ROOT/DST_BIN_ROOT are plain literals; the six destination
    // paths compose from those two (e.g. "${DST_CONFIG_ROOT}/..."), which is
    // fine — it's still built entirely from fixed constants. What must never
    // appear is a fallback to an environment variable (":-"/":+") or a read
    // of anything RATEGURU_*-shaped.
    foreach (['DST_CONFIG_ROOT', 'DST_BIN_ROOT', 'DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS', 'DST_CLEANUP'] as $name) {
        // preg_match alone only proves "at least one match" — it stops at
        // the first hit, so a second, later (and possibly unsafe)
        // assignment to the same name — the one bash would actually use at
        // runtime — would go unnoticed. preg_match_all against a broader
        // pattern (any assignment, not just the safe literal shape) counts
        // every occurrence and requires exactly one.
        preg_match_all('/^'.preg_quote($name, '/').'=.*$/m', $source, $allAssignments);
        expect($allAssignments[0])->toHaveCount(1, "{$name} must be assigned exactly once");

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

it('documents exactly the six files it owns, and what it does not touch, in the runbook', function () {
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
        ->toContain('infrastructure/scripts/cleanup')
        ->toContain('/home/www/rateguru/bin/cleanup')
        ->toContain('fixed, hardcoded constants')
        ->toContain('/home/www/rateguru/config/deployment.conf')
        ->toContain('deploy`, `rollback`')
        ->toContain('Why tits-guru remains planned')
        ->toContain('Why no deploy/rollback/backup script is changed');
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
        ->toContain('all six source files are present regular files')
        ->toContain('install-target-operations, targets, health-check, status and cleanup are all executable')
        ->toContain('bash -n passed for all five source shell scripts')
        ->toContain('source registry is valid JSON')
        ->toContain('required host tools present')
        ->toContain('check passed');
});

// =============================================================================
// validate_source_executable_modes: the fix for the regression that made
// this installer unusable on the real staging VPS after every file under
// infrastructure/scripts was deployed as mode 0640. This must fail clearly,
// by name, before validate_source_registry ever directly invokes targets and
// hits a bare "Permission denied". common is deliberately exempt — it is
// only ever sourced, never executed directly.
// =============================================================================

/**
 * @return array<string, string> SRC_* overrides: five executable dummy CLI
 *                               files plus one non-executable common.
 */
function installOpsExecutableModeVars(string $scratch): array
{
    foreach (['self', 'targets', 'health-check', 'status', 'cleanup'] as $name) {
        installOpsWriteExecutable("{$scratch}/{$name}", "#!/usr/bin/env bash\nexit 0\n");
    }

    $commonPath = "{$scratch}/common";
    file_put_contents($commonPath, "#!/usr/bin/env bash\n");
    chmod($commonPath, 0o644);

    return [
        'SRC_SELF' => "{$scratch}/self",
        'SRC_TARGETS' => "{$scratch}/targets",
        'SRC_HEALTH_CHECK' => "{$scratch}/health-check",
        'SRC_STATUS' => "{$scratch}/status",
        'SRC_CLEANUP' => "{$scratch}/cleanup",
        'SRC_COMMON' => $commonPath,
    ];
}

it('validate_source_executable_modes passes when self, targets, health-check, status and cleanup are all executable', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsExecutableModeVars($scratch);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'validate_source_executable_modes');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('install-target-operations, targets, health-check, status and cleanup are all executable');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('validate_source_executable_modes does not require common to be executable', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsExecutableModeVars($scratch);
        expect(is_executable($vars['SRC_COMMON']))->toBeFalse('fixture setup error: common must not be executable');

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'validate_source_executable_modes');

        expect($exit)->toBe(0, $output);
    } finally {
        installOpsCleanup($scratch);
    }
});

it('validate_source_executable_modes fails, naming the specific file, for each required CLI', function () {
    foreach (['SRC_SELF', 'SRC_TARGETS', 'SRC_HEALTH_CHECK', 'SRC_STATUS', 'SRC_CLEANUP'] as $key) {
        $scratch = installOpsScratchDir();

        try {
            $vars = installOpsExecutableModeVars($scratch);
            chmod($vars[$key], 0o644);

            [$exit, $output] = installOpsRunHarness($scratch, $vars, 'validate_source_executable_modes');

            expect($exit)->not->toBe(0, "{$key}: expected failure when not executable");
            expect($output)->toContain("required source CLI is not executable: {$vars[$key]}");
        } finally {
            installOpsCleanup($scratch);
        }
    }
});

it('reports the specific non-executable source CLI before validate_source_registry ever invokes it directly, instead of a generic Permission denied', function () {
    // Uses a real, working copy of the committed targets CLI — content
    // identical to what ships — with only the executable bit removed, so a
    // missing check here would genuinely reach validate_source_registry's
    // direct invocation and fail with a bare, unhelpful "Permission denied"
    // exactly as it did on the real VPS.
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsExecutableModeVars($scratch);
        copy(base_path('infrastructure/scripts/targets'), $vars['SRC_TARGETS']);
        chmod($vars['SRC_TARGETS'], 0o644);

        $vars['SRC_REGISTRY'] = base_path('infrastructure/config/deployment-targets.json');

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'run_source_validation');

        expect($exit)->not->toBe(0);
        expect($output)->toContain("required source CLI is not executable: {$vars['SRC_TARGETS']}");
        expect($output)->not->toContain('Permission denied');
        expect($output)->not->toContain('source registry is valid JSON');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('--apply requires root', function () {
    // If the test runner itself is root (some CI containers run as root by
    // default), require_root would succeed instead of failing, and --apply
    // would proceed into the real flow against /home/www/rateguru instead of
    // stopping at the gate this test exists to prove.
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    [$exit, $output] = installOpsRunScript(['--apply']);

    expect($exit)->not->toBe(0);
    expect($output)->toContain('this command must be executed as root');
});

it('--verify requires root', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

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
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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

        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

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

/**
 * Runs verify_status_parity standalone against a given status stub, the
 * technique every test below uses: DST_STATUS points directly at the stub
 * (there is no real installed-file layer in a runtime-verification-block-only
 * test), so no_overrides_env "${DST_STATUS}" ... invokes it exactly the way
 * the installed status binary would be invoked for real.
 *
 * @return array{0: int, 1: string}
 */
function installOpsRunStatusParity(string $scratch, string $statusStub): array
{
    $vars = installOpsBaseVars($scratch, null, $statusStub);
    $vars['DST_STATUS'] = $vars['SRC_STATUS'];

    return installOpsRunRuntimeHarness($scratch, $vars, 'verify_status_parity');
}

it('passes when target and legacy agree on deployment state, even though their Health log lines differ in timestamp and selector label', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub());

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('current release, previous release, release.json and history: identical between modes');
        // The stub's own Health section genuinely differs between the two
        // invocations (selector label always; the log timestamp too, given
        // two separate `date -u` calls a moment apart) — and that must not
        // be what makes this fail.
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the current release differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyCurrent: 'v1.0.1-DIFFERENT'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the current release path differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyCurrentPath: '/srv/releases/DIFFERENT'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the previous release differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyPrevious: 'v0.8.0-DIFFERENT'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the previous release path differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyPreviousPath: '/srv/releases/DIFFERENT-PREV'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when release.json metadata differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyReleaseJson: '{"version": "DIFFERENT"}'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when deployment history differs between target and legacy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyHistory: '{"release": "DIFFERENT"}'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('prints a diff -u diagnostic naming the actual mismatch before failing', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyHistory: '{"release": "DIFFERENT"}'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target vs. legacy deployment-state diff')
            ->toContain('{"release": "v1.0.0"}')
            ->toContain('{"release": "DIFFERENT"}');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the target status reports Status: unhealthy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(healthy: false));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("target status output does not report 'Status: healthy'");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails when the legacy status reports Status: unhealthy', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyHealthy: false));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("legacy status output does not report 'Status: healthy'");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails clearly when the legacy status is missing its Releases section', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyOmitSection: 'Releases'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("0 occurrence(s) of the 'Releases' section");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails clearly when the legacy status is missing its Current release metadata section', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyOmitSection: 'Current release metadata'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("0 occurrence(s) of the 'Current release metadata' section");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails clearly when the legacy status is missing its Health section', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyOmitSection: 'Health'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("0 occurrence(s) of the 'Health' section");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails clearly when the legacy status is missing its Recent deployment history section', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyOmitSection: 'Recent deployment history'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("0 occurrence(s) of the 'Recent deployment history' section");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('fails clearly when the legacy status has its Releases section duplicated', function () {
    $scratch = installOpsScratchDir();

    try {
        [$exit, $output] = installOpsRunStatusParity($scratch, installOpsStatusStub(legacyDuplicateSection: 'Releases'));

        expect($exit)->not->toBe(0);
        expect($output)->toContain("2 occurrence(s) of the 'Releases' section");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('still requires the selector-specific headers regardless of the corrected deployment-state comparison', function () {
    $scratch = installOpsScratchDir();

    try {
        // A target status stub that never claims to be for staging-main at
        // all: the corrected parity logic must not have accidentally made
        // the header assertions unreachable or redundant.
        $brokenHeaderStub = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
if [[ "$2" == "staging-main" ]]; then
    printf 'Target:      WRONG-TARGET\nLifecycle:   active\nEnvironment class: staging\nChecked at: 2026-01-01T00:00:00Z\n\nReleases\n----------------------------------------\nCurrent:      v1\nCurrent path: /a\nPrevious:     v0\nPrevious path: /b\n\nCurrent release metadata\n----------------------------------------\n{}\n\nHealth\n----------------------------------------\nStatus: healthy\n\nRecent deployment history\n----------------------------------------\n{}\n'
    exit 0
fi
printf 'Environment: staging\nChecked at: 2026-01-01T00:00:00Z\n\nReleases\n----------------------------------------\nCurrent:      v1\nCurrent path: /a\nPrevious:     v0\nPrevious path: /b\n\nCurrent release metadata\n----------------------------------------\n{}\n\nHealth\n----------------------------------------\nStatus: healthy\n\nRecent deployment history\n----------------------------------------\n{}\n'
exit 0
SH;

        [$exit, $output] = installOpsRunStatusParity($scratch, $brokenHeaderStub);

        expect($exit)->not->toBe(0);
        expect($output)->toContain("target status output is missing 'Target: staging-main'");
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

// =============================================================================
// Phase 4 slice 4: verify_cleanup_dry_run_parity / verify_cleanup_planned_
// target_rejected — the runtime-verification-block additions for cleanup.
// =============================================================================

it('verify_cleanup_dry_run_parity passes when target and legacy candidate sets match', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, null, null, installOpsCleanupStub(envCandidates: ['v1.0.0-20260101-000000-abc1234']));
        $vars['DST_CLEANUP'] = $vars['SRC_CLEANUP'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_cleanup_dry_run_parity');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('dry-run candidate parity: OK');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_cleanup_dry_run_parity fails when target and legacy candidate sets differ', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, null, null, installOpsCleanupStub(
            envCandidates: ['v1.0.0-20260101-000000-abc1234'],
            targetCandidates: ['v2.0.0-20260202-000000-def5678'],
        ));
        $vars['DST_CLEANUP'] = $vars['SRC_CLEANUP'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_cleanup_dry_run_parity');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('candidate parity mismatch');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_cleanup_planned_target_rejected fails when tits-guru unexpectedly succeeds', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, null, null, installOpsCleanupStub(titsGuru: 'unexpected-success'));
        $vars['DST_CLEANUP'] = $vars['SRC_CLEANUP'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_cleanup_planned_target_rejected');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unexpectedly succeeded');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('verify_cleanup_planned_target_rejected fails when the rejection happens for the wrong reason', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch, null, null, installOpsCleanupStub(titsGuru: 'wrong-reason'));
        $vars['DST_CLEANUP'] = $vars['SRC_CLEANUP'];

        [$exit, $output] = installOpsRunRuntimeHarness($scratch, $vars, 'verify_cleanup_planned_target_rejected');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed for the wrong reason');
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

it('a successful apply installs all six files with correct ownership, mode and content, and creates a timestamped backup', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        $configDirBefore = installOpsStatDir($vars['DST_CONFIG_ROOT']);
        $binDirBefore = installOpsStatDir($vars['DST_BIN_ROOT']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('apply complete');

        foreach ([
            ['DST_REGISTRY', 'SRC_REGISTRY', '0640'],
            ['DST_TARGETS', 'SRC_TARGETS', '0755'],
            // common is a sourced library, never a CLI — 0644, not 0755, and
            // (checked separately below) never executable.
            ['DST_COMMON', 'SRC_COMMON', '0644'],
            ['DST_HEALTH_CHECK', 'SRC_HEALTH_CHECK', '0755'],
            ['DST_STATUS', 'SRC_STATUS', '0755'],
            ['DST_CLEANUP', 'SRC_CLEANUP', '0755'],
        ] as [$dstKey, $srcKey, $mode]) {
            $dst = $vars[$dstKey];
            expect(file_exists($dst))->toBeTrue("{$dstKey} must exist");
            expect(is_link($dst))->toBeFalse();
            expect(file_get_contents($dst))->toBe(file_get_contents($vars[$srcKey]));
            expect(substr(sprintf('%o', fileperms($dst)), -4))->toBe($mode);
        }

        expect(is_executable($vars['DST_COMMON']))->toBeFalse('installed common must not be executable');

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->not->toBeEmpty('apply must create a timestamped backup directory');

        // The two containing directories are validated, never mutated — a
        // successful apply must leave their ownership and mode exactly as
        // they were found.
        expect(installOpsStatDir($vars['DST_CONFIG_ROOT']))->toBe($configDirBefore);
        expect(installOpsStatDir($vars['DST_BIN_ROOT']))->toBe($binDirBefore);
    } finally {
        installOpsCleanup($scratch);
    }
});

// =============================================================================
// common's corrected 0755 -> 0644 mode: a regression test for the real
// existing-state upgrade (an already-installed, executable common from
// before this fix), and for rollback restoring that exact prior 0755 state
// when a later step fails.
// =============================================================================

it('upgrades a pre-existing executable (0755) common to 0644, and runtime parity still passes', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        // Simulates the real state this fix corrects: common installed
        // executable, the contract before this MR.
        installOpsWriteExecutable($vars['DST_COMMON'], "#!/usr/bin/env bash\nOLD COMMON CONTENT (0755)\n");
        expect(is_executable($vars['DST_COMMON']))->toBeTrue('fixture setup: common must start executable');

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('apply complete');

        clearstatcache(true, $vars['DST_COMMON']);
        expect(substr(sprintf('%o', fileperms($vars['DST_COMMON'])), -4))->toBe('0644');
        expect(is_executable($vars['DST_COMMON']))->toBeFalse('common must no longer be executable after apply');
        expect(file_get_contents($vars['DST_COMMON']))->toBe(file_get_contents($vars['SRC_COMMON']));
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rolls back common to its exact previous 0755 mode and content when a later verification step fails', function () {
    $scratch = installOpsScratchDir();

    try {
        // A genuine post-install status-parity mismatch, forcing rollback
        // after common has already been re-installed at 0644.
        $vars = installOpsBaseVars($scratch, null, installOpsStatusStub(legacyHistory: '{"release": "DIFFERENT"}'));
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        $oldCommonContent = "#!/usr/bin/env bash\nOLD COMMON CONTENT (0755)\n";
        installOpsWriteExecutable($vars['DST_COMMON'], $oldCommonContent);
        expect(is_executable($vars['DST_COMMON']))->toBeTrue('fixture setup: common must start executable at 0755');

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('rollback complete: previous files restored');

        clearstatcache(true, $vars['DST_COMMON']);
        expect(file_get_contents($vars['DST_COMMON']))->toBe($oldCommonContent, 'common content must be restored exactly');
        expect(substr(sprintf('%o', fileperms($vars['DST_COMMON'])), -4))->toBe('0755', 'common mode must be restored to its exact previous 0755');
        expect(is_executable($vars['DST_COMMON']))->toBeTrue('common must be restored to executable, matching its pre-existing state');
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

        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS', 'DST_CLEANUP'] as $key) {
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
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS', 'DST_CLEANUP'] as $key) {
            clearstatcache(true, $vars[$key]);
            $before[$key] = [filemtime($vars[$key]), md5_file($vars[$key])];
        }
        $backupsBefore = glob($scratch.'/backups/*', GLOB_ONLYDIR);

        [$verifyExit, $verifyOut] = installOpsRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->toBe(0, $verifyOut);
        expect($verifyOut)->toContain('PASS: installed files and runtime behaviour verified');

        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_HEALTH_CHECK', 'DST_STATUS', 'DST_CLEANUP'] as $key) {
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
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_STATUS', 'DST_CLEANUP'] as $key) {
            $sentinels[$key] = "OLD SENTINEL: {$key}\n";
            file_put_contents($vars[$key], $sentinels[$key]);
        }
        $configDirBefore = installOpsStatDir($vars['DST_CONFIG_ROOT']);
        $binDirBefore = installOpsStatDir($vars['DST_BIN_ROOT']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('currently installed legacy staging health check failed');

        foreach ($sentinels as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be untouched");
        }
        expect(file_exists($vars['DST_HEALTH_CHECK']))->toBeTrue();

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->toBeEmpty('no backup directory should be created before the legacy preflight passes');

        expect(installOpsStatDir($vars['DST_CONFIG_ROOT']))->toBe($configDirBefore);
        expect(installOpsStatDir($vars['DST_BIN_ROOT']))->toBe($binDirBefore);
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
        foreach (['DST_REGISTRY', 'DST_TARGETS', 'DST_COMMON', 'DST_STATUS', 'DST_CLEANUP'] as $key) {
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

        // registry/targets/common pre-exist (restore path); status and
        // cleanup do not (remove path).
        $oldContent = [
            'DST_REGISTRY' => "OLD registry\n",
            'DST_TARGETS' => "OLD targets\n",
            'DST_COMMON' => "OLD common\n",
        ];
        foreach ($oldContent as $key => $content) {
            file_put_contents($vars[$key], $content);
        }
        expect(file_exists($vars['DST_STATUS']))->toBeFalse();
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse();
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);
        $configDirBefore = installOpsStatDir($vars['DST_CONFIG_ROOT']);
        $binDirBefore = installOpsStatDir($vars['DST_BIN_ROOT']);

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
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse('cleanup must be removed — it did not exist before this run');

        expect(installOpsStatDir($vars['DST_CONFIG_ROOT']))->toBe($configDirBefore, 'a rollback must leave the containing directory exactly as found');
        expect(installOpsStatDir($vars['DST_BIN_ROOT']))->toBe($binDirBefore, 'a rollback must leave the containing directory exactly as found');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a post-install cleanup dry-run parity failure rolls back every touched destination', function () {
    $scratch = installOpsScratchDir();

    try {
        // The candidate cleanup fails --environment staging --dry-run, but
        // only once invoked as the exact final installed path — the staged
        // copy (a different, mktemp'd path) passes, so this is a genuine
        // post-install parity failure caught specifically by
        // verify_cleanup_dry_run_parity, the last check in
        // verify_runtime_parity's sequence — proving rollback still fires
        // correctly even when every earlier check (health-check, status)
        // already passed.
        $vars = installOpsBaseVars($scratch);
        $failingCleanupCandidate = installOpsCleanupStub($vars['DST_CLEANUP']);
        installOpsWriteExecutable($vars['SRC_CLEANUP'], $failingCleanupCandidate);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        $oldContent = [
            'DST_REGISTRY' => "OLD registry\n",
            'DST_TARGETS' => "OLD targets\n",
            'DST_COMMON' => "OLD common\n",
        ];
        foreach ($oldContent as $key => $content) {
            file_put_contents($vars[$key], $content);
        }
        expect(file_exists($vars['DST_STATUS']))->toBeFalse();
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse();
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);
        $configDirBefore = installOpsStatDir($vars['DST_CONFIG_ROOT']);
        $binDirBefore = installOpsStatDir($vars['DST_BIN_ROOT']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('files installed; verifying before committing');
        expect($output)->toContain('forced staging failure (test)');
        expect($output)->toContain('rollback complete: previous files restored');
        expect($output)->toContain('confirmed: legacy staging health check still succeeds after rollback');

        foreach ($oldContent as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be restored to its previous content");
        }
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($healthCheckBefore, 'health-check must be restored to its previous content');
        expect(file_exists($vars['DST_STATUS']))->toBeFalse('status must be removed — it did not exist before this run');
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse('cleanup must be removed — it did not exist before this run');

        expect(installOpsStatDir($vars['DST_CONFIG_ROOT']))->toBe($configDirBefore, 'a rollback must leave the containing directory exactly as found');
        expect(installOpsStatDir($vars['DST_BIN_ROOT']))->toBe($binDirBefore, 'a rollback must leave the containing directory exactly as found');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a genuine post-install status-parity mismatch rolls back every touched destination exactly once', function () {
    $scratch = installOpsScratchDir();

    try {
        // Both health checks pass; the installed status candidate genuinely
        // disagrees with legacy on deployment history — a real parity
        // failure caught only after installation, distinct from the
        // health-check-failure rollback test above.
        $vars = installOpsBaseVars($scratch, null, installOpsStatusStub(legacyHistory: '{"release": "DIFFERENT"}'));
        installOpsPlaceHealthyLegacyHealthCheck($vars);
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);

        $oldContent = [
            'DST_REGISTRY' => "OLD registry\n",
            'DST_TARGETS' => "OLD targets\n",
            'DST_COMMON' => "OLD common\n",
        ];
        foreach ($oldContent as $key => $content) {
            file_put_contents($vars[$key], $content);
        }
        expect(file_exists($vars['DST_STATUS']))->toBeFalse();
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse();
        $configDirBefore = installOpsStatDir($vars['DST_CONFIG_ROOT']);
        $binDirBefore = installOpsStatDir($vars['DST_BIN_ROOT']);

        // Same file-based invocation-counting technique as the other
        // rollback regression tests: immune to a duplicate handler's output
        // being swallowed inside a failing subshell's own captured stdout.
        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_apply';

        [$exit, $output] = installOpsRunHarness($scratch, $vars, $driver);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target and legacy status disagree in deployment state');
        expect($output)->toContain('rollback complete: previous files restored');

        foreach ($oldContent as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be restored to its previous content");
        }
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($healthCheckBefore, 'health-check must be restored to its previous content');
        expect(file_exists($vars['DST_STATUS']))->toBeFalse('status must be removed — it did not exist before this run');
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse('cleanup must be removed — it did not exist before this run');

        expect(installOpsStatDir($vars['DST_CONFIG_ROOT']))->toBe($configDirBefore);
        expect(installOpsStatDir($vars['DST_BIN_ROOT']))->toBe($binDirBefore);

        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, 'apply failed (exit'))->toBe(1, 'on_apply_error\'s real body must run exactly once');
        expect(substr_count($logInvocations, 'rollback complete: previous files restored'))->toBe(1);
    } finally {
        installOpsCleanup($scratch);
    }
});

it('--verify catches a genuine status-parity mismatch using the same corrected comparison as --apply', function () {
    $scratch = installOpsScratchDir();

    try {
        // Install successfully first with a fully consistent status stub —
        // perform_verify must be exercised against a real, already-installed
        // set, not a synthetic runtime-verification-block-only harness.
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$applyExit, $applyOut] = installOpsRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0, $applyOut);

        // Now replace the installed status (and, so verify_installed_files'
        // own content-matches-source check keeps passing, its committed
        // source alongside it) with one whose two modes genuinely disagree —
        // the same corrected status_deployment_state comparison must catch
        // this through --verify too, not just --apply.
        $mismatchedStatusStub = installOpsStatusStub(legacyReleaseJson: '{"version": "DIFFERENT"}');
        installOpsWriteExecutable($vars['SRC_STATUS'], $mismatchedStatusStub);
        installOpsWriteExecutable($vars['DST_STATUS'], $mismatchedStatusStub);

        [$verifyExit, $verifyOut] = installOpsRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOut)->toContain('--- status parity ---');
        expect($verifyOut)->toContain('target and legacy status disagree in deployment state');
        expect($verifyOut)->toContain('FAIL: verification did not pass');
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

// =============================================================================
// The two destination directories are validated, never mutated: no
// install -d against DST_CONFIG_ROOT/DST_BIN_ROOT exists anywhere in the
// script, and --apply refuses to proceed — before creating a backup or
// touching any destination — when either directory is missing or unsafe.
// =============================================================================

it('never creates, chowns or chmods either destination directory', function () {
    $source = installOpsSource();

    // install -d is the only command capable of creating or chmod/chowning a
    // directory in this script. It legitimately targets BACKUP_ROOT/
    // BACKUP_DIR and the per-file backup mirror directories under
    // record_target — this installer fully owns and manages its own backup
    // tree, which is a different thing from the two directories it only
    // ever reads. It must never target DST_CONFIG_ROOT or DST_BIN_ROOT.
    foreach (preg_split('/\R/', $source) as $line) {
        if (! str_contains($line, 'install -d')) {
            continue;
        }

        expect($line)->not->toContain('DST_CONFIG_ROOT')
            ->not->toContain('DST_BIN_ROOT');
    }
});

it('apply fails and changes nothing when a destination directory is missing', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);

        // validate_destination_directories runs before verify_legacy_staging_
        // health, so this aborts before that check is ever reached — no
        // healthy legacy health-check needs to be in place for this test.
        rmdir($vars['DST_BIN_ROOT']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('destination directory is missing');
        expect($output)->toContain($vars['DST_BIN_ROOT']);

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->toBeEmpty();
    } finally {
        installOpsCleanup($scratch);
    }
});

it('apply fails and changes nothing when a destination directory is a symlink', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        $realDir = $scratch.'/real-bin-elsewhere';
        rename($vars['DST_BIN_ROOT'], $realDir);
        symlink($realDir, $vars['DST_BIN_ROOT']);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('destination directory must not be a symlink');
        expect(is_link($vars['DST_BIN_ROOT']))->toBeTrue('the symlink itself must be left in place');

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->toBeEmpty();
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rejects a destination directory not owned by the expected owner', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        // The directory's real on-disk owner is unchanged; only the expected
        // owner this call checks against is wrong — directly exercises the
        // comparison itself without needing a second real user account.
        // Called directly (not through perform_apply) because
        // validate_installed_deployment_conf also compares against
        // INSTALL_OWNER_ID and runs first — mismatching it globally would
        // trip that check instead of the one this test targets.
        $vars['INSTALL_OWNER_ID'] = '999999';

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'validate_destination_directories');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('destination directory must be owned by root:root');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('apply fails and changes nothing when a destination directory is group-writable', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        chmod($vars['DST_CONFIG_ROOT'], 0o775);

        [$exit, $output] = installOpsRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('destination directory must not be group- or other-writable');

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->toBeEmpty();
    } finally {
        installOpsCleanup($scratch);
    }
});

// =============================================================================
// Generic subshell-safe ERR/EXIT handling: set -E means every trap armed by
// perform_apply/perform_verify is inherited by every $(...) subshell, not
// just the two deliberately-failing tits-guru checks. on_apply_error/
// on_verify_error record the main process's BASHPID before the trap is
// armed and compare against it on every invocation, so a genuine failure
// inside *any* command substitution is handled exactly once, by the main
// process, regardless of which command substitution it happens to be.
// =============================================================================

it('a genuine failure inside an ordinary command substitution after installation rolls back exactly once, preserves the exit code, and prints no duplicate handler output', function () {
    $scratch = installOpsScratchDir();

    try {
        // verify_status_parity's target_state/legacy_state assignments
        // ("target_state=\"$(status_deployment_state ...)\"") are *not*
        // protected by an `if`/`||` at their call site, unlike the
        // deliberately-failing tits-guru checks — a bare, unprotected
        // command substitution is exactly the shape that still double-fires
        // an inherited ERR/EXIT trap (once inside its subshell, once more in
        // the main process) without the generic BASHPID guard. A stub `awk`
        // that always fails forces status_deployment_state's own pipeline to
        // fail — awk is used nowhere else in this installer's own code, and
        // neither the stub health-check/status nor a real `targets` call in
        // this flow ever invoke it (both `targets` calls always pass
        // --file), so this is safe for the whole run.
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);
        installOpsWriteExecutable($scratch.'/bin/awk', "#!/usr/bin/env bash\n"
            ."printf 'stub awk: forced failure (test)\\n' >&2\n"
            ."exit 1\n");

        $oldContent = [
            'DST_REGISTRY' => "OLD registry\n",
            'DST_TARGETS' => "OLD targets\n",
            'DST_COMMON' => "OLD common\n",
        ];
        foreach ($oldContent as $key => $content) {
            file_put_contents($vars[$key], $content);
        }
        expect(file_exists($vars['DST_STATUS']))->toBeFalse();
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse();
        $healthCheckBefore = file_get_contents($vars['DST_HEALTH_CHECK']);

        // log()'s own text is *not* a reliable signal on its own: when the
        // trap fires inside the failing substitution's subshell, that
        // subshell's stdout — including whatever log() prints — is the very
        // thing being captured into target_body, so a duplicate invocation
        // there is invisible in $output, not merely quiet. Redefining log()
        // to *also* append to a real file sidesteps that: unlike a
        // captured $(...)'s stdout, a file append is a genuine, global
        // filesystem effect no matter which process (subshell or main)
        // performs it, so counting lines in it reliably proves how many
        // times on_apply_error's real body actually ran.
        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_apply';

        [$exit, $output] = installOpsRunHarness($scratch, $vars, $driver);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('files installed; verifying before committing');
        expect($output)->toContain('stub awk: forced failure (test)');

        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, 'apply failed (exit'))->toBe(1, 'on_apply_error\'s real body must run exactly once, from the main process');
        expect(substr_count($logInvocations, 'rollback complete: previous files restored'))->toBe(1);

        foreach ($oldContent as $key => $content) {
            expect(file_get_contents($vars[$key]))->toBe($content, "{$key} must be restored to its previous content");
        }
        expect(file_get_contents($vars['DST_HEALTH_CHECK']))->toBe($healthCheckBefore);
        expect(file_exists($vars['DST_STATUS']))->toBeFalse('status must be removed — it did not exist before this run');
        expect(file_exists($vars['DST_CLEANUP']))->toBeFalse('cleanup must be removed — it did not exist before this run');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('a genuine failure inside an ordinary command substitution during --verify prints no duplicate final-result handler', function () {
    $scratch = installOpsScratchDir();

    try {
        $vars = installOpsBaseVars($scratch);
        installOpsPlaceHealthyLegacyHealthCheck($vars);

        [$applyExit, $applyOut] = installOpsRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0, $applyOut);

        // See the matching apply-mode regression test above: target_state/
        // legacy_state's substitutions are unprotected, and a stub `awk`
        // forces status_deployment_state's own pipeline to fail — an
        // ordinary, not tits-guru-related, failure, introduced only now,
        // after a genuinely successful apply already installed everything
        // correctly.
        installOpsWriteExecutable($scratch.'/bin/awk', "#!/usr/bin/env bash\n"
            ."printf 'stub awk: forced failure (test)\\n' >&2\n"
            ."exit 1\n");

        // Same reasoning as the apply-mode test above: log()'s file append
        // is immune to the subshell-stdout-swallowing that makes a
        // duplicate invisible in $output.
        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_verify';

        [$exit, $output] = installOpsRunHarness($scratch, $vars, $driver);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('stub awk: forced failure (test)');

        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, '--- final result ---'))->toBe(1, 'on_verify_error\'s real body must run exactly once, from the main process');
        expect(substr_count($logInvocations, 'FAIL: verification did not pass'))->toBe(1);
    } finally {
        installOpsCleanup($scratch);
    }
});

// =============================================================================
// install_regular_file_transactional rejects every non-regular existing
// destination (not just symlinks), and stages into a same-directory,
// mktemp-created temporary file that is always removed on failure.
// =============================================================================

it('allows installation when the destination is absent', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "brand new content\n");
        $dst = $scratch.'/dst-file';
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        expect(file_exists($dst))->toBeFalse();

        [$exit, $output] = installOpsRunCoreHarness($scratch, "install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640");

        expect($exit)->toBe(0, $output);
        expect(file_get_contents($dst))->toBe("brand new content\n");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('allows installation over an existing plain regular file, backing up its previous content', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-file';
        file_put_contents($dst, "old content\n");
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            BASH);

        expect($exit)->toBe(0, $output);
        expect(file_get_contents($dst))->toBe("new content\n");
        expect(file_get_contents("{$scratch}/backups/run1{$dst}"))->toBe("old content\n");
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rejects an existing directory at the destination, leaving it untouched with no backup recorded', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-dir';
        mkdir($dst.'/inside', 0o755, true);
        file_put_contents($dst.'/inside/marker', "must survive\n");
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            BASH);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing non-regular-file destination');
        expect(is_dir($dst))->toBeTrue('the directory itself must be left in place');
        expect(file_get_contents($dst.'/inside/marker'))->toBe("must survive\n");
        expect(file_exists("{$scratch}/backups/run1{$dst}"))->toBeFalse('a rejected destination must never be backed up');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('rejects an existing FIFO at the destination, leaving it untouched with no backup recorded', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-fifo';

        $mkfifoOutput = [];
        $mkfifoExit = 0;
        exec('mkfifo '.escapeshellarg($dst).' 2>&1', $mkfifoOutput, $mkfifoExit);

        if ($mkfifoExit !== 0) {
            test()->markTestSkipped('mkfifo is not available on this host');
        }

        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = installOpsRunCoreHarness($scratch, <<<BASH
            BACKUP_DIR="{$scratch}/backups/run1"
            install -d -m 0700 "\${BACKUP_DIR}"
            install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640
            BASH);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing non-regular-file destination');
        expect(filetype($dst))->toBe('fifo');
        expect(file_exists("{$scratch}/backups/run1{$dst}"))->toBeFalse('a rejected destination must never be backed up');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('removes the temporary file and leaves the destination untouched when install fails to stage it', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-file';
        $gid = (string) getmygid();

        // A nonexistent owner name is a reliable, portable way to make
        // `install` itself fail without touching the filesystem or needing
        // root — no such user exists on any CI or dev machine.
        [$exit, $output] = installOpsRunCoreHarness(
            $scratch,
            "install_regular_file_transactional {$src} {$dst} install-ops-test-nonexistent-user {$gid} 0640",
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed to stage');
        expect(file_exists($dst))->toBeFalse('destination must not exist after a failed install');

        $leftovers = glob($dst.'.*');
        expect($leftovers)->toBeEmpty('no temporary file should remain after a failed install');
    } finally {
        installOpsCleanup($scratch);
    }
});

it('removes the temporary file and leaves the destination untouched when the rename into place fails', function () {
    $scratch = installOpsScratchDir();

    try {
        $src = $scratch.'/src-file';
        file_put_contents($src, "new content\n");
        $dst = $scratch.'/dst-file';
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        // A stub `mv` that always fails, placed first in PATH — `install`
        // still succeeds in writing the temporary file, so this isolates the
        // rename step specifically.
        installOpsWriteExecutable($scratch.'/bin/mv', "#!/usr/bin/env bash\n"
            ."printf 'stub mv: forced failure (test)\\n' >&2\n"
            ."exit 1\n");

        [$exit, $output] = installOpsRunCoreHarness($scratch, "install_regular_file_transactional {$src} {$dst} {$uid} {$gid} 0640");

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed to rename the staged file into place');
        expect(file_exists($dst))->toBeFalse('destination must not exist after a failed rename');

        $leftovers = glob($dst.'.*');
        expect($leftovers)->toBeEmpty('no temporary file should remain after a failed rename');
    } finally {
        installOpsCleanup($scratch);
    }
});
