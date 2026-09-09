<?php

use Illuminate\Support\Facades\File;

/**
 * Prepare Host: infrastructure/scripts/prepare-host — the one authoritative
 * server-side preparation entry point, which turns a clean supported VPS into
 * infrastructure ready to host one RateGuru target.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against fixture/shim children: one stub per slice that
 * logs every invocation, answers its read-only modes from a per-child
 * "compliant" toggle, converges that toggle on --apply, and records every
 * mutating invocation in a dedicated mutation log. All injected through
 * RATEGURU_PREPAREHOST_* overrides the script honors only alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here touches the CI host.
 *
 * What matters architecturally: the exact slice order, the lifecycle gate
 * firing before any target-specific mutation, verify-skip-apply convergence
 * (so a second run is SKIPs rather than blind reinstallation), bootstrap-host
 * being reused rather than decomposed, and the complete absence of deploy,
 * migration, restore and secret-generation behaviour.
 */

// =============================================================================
// Harness
// =============================================================================

function prepScript(): string
{
    return base_path('infrastructure/scripts/prepare-host');
}

function prepSource(): string
{
    return File::get(prepScript());
}

function prepScratchDir(): string
{
    $dir = sys_get_temp_dir().'/prepare-host-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/log', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

/**
 * A registry fixture for the lifecycle gate.
 *
 * The committed registry supplies the SHAPE — every field the real `targets`
 * validator demands, without this file having to restate a schema it does not
 * own — but the fixture then states the two facts these tests are about
 * outright: one active target and one planned one. Flipping a lifecycle in the
 * committed registry therefore cannot change what the tests below mean, which a
 * verbatim copy would not have achieved.
 *
 * The separate test at the end of this file is what asserts the real registry
 * still gives those two targets those two lifecycles.
 */
function prepRegistryFixture(string $scratch): string
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    foreach (['staging-main' => 'active', 'tits-guru' => 'planned'] as $target => $lifecycle) {
        // toHaveKey's second argument is an expected VALUE, not a message, so
        // the diagnostic lives here: the fixture borrows the committed
        // registry's shape and needs both entries to exist to borrow it from.
        expect($registry['targets'])->toHaveKey($target);

        $registry['targets'][$target]['lifecycle'] = $lifecycle;
    }

    $path = $scratch.'/deployment-targets.json';

    file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $path;
}

function prepCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function prepRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', prepScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start prepare-host subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function prepWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/** @return list<string> */
function prepLog(string $scratch, string $name): array
{
    $path = $scratch.'/log/'.$name.'.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

/**
 * One stub per child. Read-only modes answer from the "<slice>-compliant"
 * toggle and print a child-shaped SUMMARY the orchestrator excerpts; --apply
 * logs a mutation and converges the toggle unless a failure toggle is set.
 *
 * The prerequisite installer is one binary serving two slices, exactly as it
 * is in production, so the stub keys its toggle off the --scope it was given —
 * which is also how these tests prove the two scopes really are ordered around
 * the bootstrap slice.
 */
function prepWriteChildStubs(string $scratch): void
{
    // The one child whose REAL implementation reaches install-target-operations,
    // which takes the two data-operation locks itself. Under
    // STUB_REACQUIRE_LOCKS it does the same thing on fresh descriptors, which is
    // the exact shape that deadlocks if the orchestrator above it is holding
    // them: flock treats independently opened descriptors independently, even
    // inside one process tree.
    prepWriteStub($scratch.'/bin/lock-taker', <<<'STUB'
        #!/bin/bash
        # Numbered descriptors, not {fd}>: these stubs run under /bin/bash,
        # which on the macOS development machines is 3.2 and has no
        # varname-redirection.
        for prefix in restore-target recover-host; do
            lock="${STUB_LOCK_ROOT}/${prefix}-${STUB_LOCK_NAMESPACE}.lock"
            [[ -e "${lock}" ]] || touch "${lock}"
            exec 7>>"${lock}"
            if flock -n 7; then
                echo "${prefix} acquired" >> "${STUB_LOG}/locks.log"
            else
                echo "${prefix} DENIED" >> "${STUB_LOG}/locks.log"
            fi
            exec 7>&-
        done
        STUB);

    prepWriteStub($scratch.'/bin/prerequisites', <<<'STUB'
        #!/bin/bash
        printf 'prerequisites %s\n' "$*" >> "${STUB_LOG}/children.log"
        scope=host
        case "$*" in *"--scope target"*) scope=target ;; esac
        key="prerequisites-${scope}"
        # What the material directory held when this child ran, and whether
        # the host-global offsite-write hold already existed: a recovery
        # preparation places it between the host and target slices.
        material=""
        args=("$@")
        for i in "${!args[@]}"; do
            if [[ "${args[$i]}" == --material-dir ]]; then
                material="${args[$((i + 1))]}"
            fi
        done
        if [[ -n "${material}" ]]; then
            printf '%s %s: %s\n' "${key}" "${material}" "$(ls "${material}" | tr '\n' ' ')" >> "${STUB_LOG}/material-seen.log"
        fi
        case "$*" in
            *--apply*)
                printf '%s %s\n' "${key}" "$*" >> "${STUB_LOG}/mutations.log"
                if [[ -e "${STUB_LOCK_ROOT}/offsite-write-hold" ]]; then
                    printf '%s hold-present\n' "${key}" >> "${STUB_LOG}/hold-seen.log"
                else
                    printf '%s hold-absent\n' "${key}" >> "${STUB_LOG}/hold-seen.log"
                fi
                # Simulates the hold vanishing between the slice that placed
                # it and the end of the run.
                if [[ "${key}" == prerequisites-target ]] && [[ -e "${STUB_TOGGLES}/remove-hold-after-target" ]]; then
                    rm -f "${STUB_LOCK_ROOT}/offsite-write-hold"
                fi
                if [[ -e "${STUB_TOGGLES}/${key}-apply-fail" ]]; then
                    echo "ERROR: external prerequisite tls-private-key: already present and DIFFERS from the supplied material"
                    exit 1
                fi
                touch "${STUB_TOGGLES}/${key}-compliant"
                echo "${key} apply done"
                exit 0
                ;;
            *--check*)
                # The material-aware mode: satisfied only when the destination
                # holds what the run supplied. A conflict toggle makes it
                # refuse even though the material-blind verify would pass.
                if [[ -e "${STUB_TOGGLES}/${key}-material-conflict" ]]; then
                    echo "SUMMARY"
                    echo "conflicts 1"
                    exit 1
                fi
                if [[ -e "${STUB_TOGGLES}/${key}-compliant" ]]; then
                    echo "SUMMARY"
                    echo "${key} CONTRACT: SATISFIED"
                    exit 0
                fi
                echo "SUMMARY"
                echo "${key} CONTRACT: NOT SATISFIED"
                exit 1
                ;;
            *)
                if [[ -e "${STUB_TOGGLES}/${key}-compliant" ]]; then
                    echo "SUMMARY"
                    echo "${key} CONTRACT: SATISFIED"
                    exit 0
                fi
                echo "SUMMARY"
                echo "${key} CONTRACT: NOT SATISFIED"
                exit 1
                ;;
        esac
        STUB);

    // The recovery-material helper: records its arguments, the output
    // directory it was given and that directory's mode, then fills it the way
    // the real helper does — or refuses, on a toggle, the way the real one
    // refuses a backup that is not recovery-capable.
    prepWriteStub($scratch.'/bin/fetch', <<<'STUB'
        #!/bin/bash
        printf 'fetch %s\n' "$*" >> "${STUB_LOG}/children.log"
        out=""
        seed=""
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --output-dir) out="$2"; shift 2 ;;
                --seed-dir) seed="$2"; shift 2 ;;
                *) shift ;;
            esac
        done
        printf '%s\n' "${out}" > "${STUB_LOG}/fetch-output-dir"
        printf '%s\n' "${seed}" > "${STUB_LOG}/fetch-seed-dir"
        # GNU stat on Linux, BSD stat where GNU syntax is unsupported.
        { stat -c '%a' "${out}" 2>/dev/null || stat -f '%Lp' "${out}"; } > "${STUB_LOG}/fetch-output-mode"
        if [[ -e "${STUB_TOGGLES}/fetch-fail" ]]; then
            echo "ERROR: backup 20260115-023000 is not clean-host-recovery-capable (stub)"
            exit 1
        fi
        for name in laravel-env basic-auth tls-certificate tls-private-key tls-dhparams nginx-tls-options mail-tls-certificate mail-tls-private-key deploy-authorized-keys rclone-config; do
            printf 'stub material\n' > "${out}/${name}"
        done
        echo "recovery material staged for staging-main from offsite backup (stub)"
        STUB);

    foreach (['runtime', 'bootstrap', 'database'] as $child) {
        prepWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            # bootstrap's real counterpart reaches install-target-operations,
            # which takes the two data-operation locks itself.
            if [[ "${me}" == bootstrap ]] && [[ -n "${STUB_REACQUIRE_LOCKS:-}" ]]; then
                "${STUB_LOCK_TAKER}"
            fi
            case "$*" in
                *--apply*)
                    printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/mutations.log"
                    if [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]]; then
                        echo "ERROR: ${me} simulated apply failure"
                        exit 1
                    fi
                    if [[ ! -e "${STUB_TOGGLES}/${me}-apply-no-converge" ]]; then
                        touch "${STUB_TOGGLES}/${me}-compliant"
                    fi
                    # bootstrap-host lays out the operational run root; the
                    # offsite-write hold a recovery preparation places lives
                    # there, so the stub creates it exactly when bootstrap
                    # converges.
                    if [[ "${me}" == bootstrap ]]; then
                        mkdir -p "${STUB_LOCK_ROOT}"
                    fi
                    echo "${me} apply done"
                    exit 0
                    ;;
                *)
                    if [[ -e "${STUB_TOGGLES}/${me}-readonly-exit-130" ]]; then
                        exit 130
                    fi
                    if [[ -e "${STUB_TOGGLES}/${me}-compliant" ]]; then
                        echo "SUMMARY"
                        echo "${me} CONTRACT: SATISFIED"
                        exit 0
                    fi
                    echo "SUMMARY"
                    echo "${me} CONTRACT: NOT SATISFIED"
                    exit 1
                    ;;
            esac
            STUB);
    }
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function prepFixture(string $scratch, array $options = []): array
{
    prepWriteChildStubs($scratch);

    if (! is_dir($scratch.'/root-home')) {
        expect(@mkdir($scratch.'/root-home', 0o700, true))->toBeTrue("could not create {$scratch}/root-home");
    }

    foreach ($options['compliant'] ?? [] as $slice) {
        touch($scratch.'/toggles/'.$slice.'-compliant');
    }

    return array_merge([
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_PREPAREHOST_EUID' => $options['euid'] ?? '0',
        // The real `targets` CLI — it is the authoritative reader and the thing
        // whose behaviour matters — pointed at a registry fixture inside the
        // scratch tree. Both are named explicitly, so a run can never pick up
        // an installed runtime registry, and an edit to the committed one
        // cannot silently change what these tests mean.
        'RATEGURU_PREPAREHOST_TARGETS_CLI_BIN' => $options['targets_cli']
            ?? base_path('infrastructure/scripts/targets'),
        'RATEGURU_PREPAREHOST_SOURCE_REGISTRY' => $options['registry']
            ?? prepRegistryFixture($scratch),
        'RATEGURU_PREPAREHOST_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime',
        'RATEGURU_PREPAREHOST_PREREQUISITES_INSTALLER_BIN' => $scratch.'/bin/prerequisites',
        'RATEGURU_PREPAREHOST_BOOTSTRAP_HOST_BIN' => $scratch.'/bin/bootstrap',
        'RATEGURU_PREPAREHOST_DATABASE_INSTALLER_BIN' => $scratch.'/bin/database',
        'RATEGURU_PREPAREHOST_RECOVERY_MATERIAL_BIN' => $scratch.'/bin/fetch',
        // Where a recovery preparation stages the effective material: root's
        // home on a real host, a scratch directory here.
        'RATEGURU_PREPAREHOST_RECOVERY_MATERIAL_PARENT' => $scratch.'/root-home',
        // The run root the data-operation guards live under. Pointed inside
        // the scratch tree so a test can plant one without touching the host.
        'RATEGURU_PREPAREHOST_RUN_ROOT' => $scratch.'/run',
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_LOCK_TAKER' => $scratch.'/bin/lock-taker',
        'STUB_LOCK_ROOT' => $scratch.'/run',
        'STUB_LOCK_NAMESPACE' => 'staging',
    ], $options['env'] ?? []);
}

/**
 * Plants one data-operation guard for staging-main under the scratch run root,
 * in the shape the operation that owns it actually writes.
 */
function prepPlantGuard(string $scratch, string $namespace, string $name, array $document = []): string
{
    $dir = $scratch.'/run/'.$namespace.'/staging-main';
    expect(@mkdir($dir, 0o700, true))->toBeTrue("could not create {$dir}");

    $path = $dir.'/'.$name;

    file_put_contents($path, json_encode(array_merge([
        'operation' => '20260115-041233-9be21c',
        'target' => 'staging-main',
        'status' => 'awaiting-code',
    ], $document), JSON_PRETTY_PRINT));

    return $path;
}

/** Every slice compliant — an already prepared host. */
function prepPreparedFixture(string $scratch): array
{
    return prepFixture($scratch, [
        'compliant' => [
            'runtime',
            'prerequisites-host',
            'bootstrap',
            'prerequisites-target',
            'database',
        ],
    ]);
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships prepare-host as an executable script in the infrastructure bundle', function () {
    expect(File::exists(prepScript()))->toBeTrue();
    expect(is_executable(prepScript()))->toBeTrue('prepare-host must be executable');
    expect(prepSource())->toStartWith("#!/usr/bin/env bash\n");
});

it('requires --target in every mode', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = prepRun([$mode], prepFixture($scratch));

            expect($exit)->toBe(1, "{$mode} without --target must fail");
            expect($output)->toContain('--target is required');
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('requires exactly one mode and rejects unknown arguments', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);

        [$exit, $output] = prepRun(['--target', 'staging-main'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('one of --check, --apply or --verify is required');

        [$exit, $output] = prepRun(['--check', '--apply', '--target', 'staging-main'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('mode given more than once');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main', '--force'], $env);
        expect($exit)->toBe(1);
        expect($output)->toContain('unknown argument: --force');
    } finally {
        prepCleanup($scratch);
    }
});

it('has no --force, --skip or --continue-on-error escape hatch', function () {
    $source = prepSource();

    foreach (['--force', '--skip', '--continue-on-error'] as $flag) {
        expect($source)->not->toMatch('/^\s*'.preg_quote($flag, '/').'\)/m',
            "prepare-host must not accept {$flag}");
    }
});

it('requires root in every mode', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = prepRun(
                [$mode, '--target', 'staging-main'],
                prepFixture($scratch, ['euid' => '1000']),
            );

            expect($exit)->toBe(1);
            expect($output)->toContain('must run as root');
        }

        expect(prepLog($scratch, 'children'))->toBe([], 'no child may run for a non-root caller');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Target lifecycle — the gate that keeps production unprovisioned
// =============================================================================

it('refuses a lifecycle=planned target before any child runs', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'tits-guru'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned, not active');

        // The whole point: nothing ran at all, so no host-global bootstrap
        // could have provisioned a planned production target as a side effect.
        expect(prepLog($scratch, 'children'))->toBe([]);
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses an unknown target before any child runs', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'not-a-real-target'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('unknown or unusable target: not-a-real-target');
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('accepts the lifecycle=active staging target', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main'],
            prepPreparedFixture($scratch),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('Target staging-main: lifecycle=active');
        expect($output)->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('rejects a malformed target ID without consulting the registry', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'Staging Main; rm -rf /'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('invalid target ID');
        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Slice order and reuse of bootstrap-host
// =============================================================================

it('runs the slices in the only order that can succeed on a clean host', function () {
    $scratch = prepScratchDir();

    try {
        [$exit] = prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        expect($exit)->toBe(0);

        $mutations = prepLog($scratch, 'mutations');
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            $mutations,
        );

        // Host-scope material before bootstrap, because install-bootstrap-services fails closed
        // without it; target-scope material after, because its parent
        // directories and owning accounts are created by install-bootstrap-host-layout; the
        // database last, because it needs both PostgreSQL and the credentials
        // inside shared/.env.
        expect($order)->toBe([
            'runtime',
            'prerequisites-host',
            'bootstrap',
            'prerequisites-target',
            'database',
        ]);
    } finally {
        prepCleanup($scratch);
    }
});

it('reuses bootstrap-host rather than duplicating its slices', function () {
    $source = prepSource();

    // The bootstrap pipeline is invoked, not reimplemented. The children
    // prepare-host resolves are exactly the four it declares — bootstrap-host
    // among them — and never 5.3's, 5.4's or the preflight's own installers,
    // which stay bootstrap-host's to sequence. (The header prose names them to
    // explain the ordering; only the resolved binaries are asserted here.)
    preg_match_all('/^([A-Z_]+_BIN)="\$\(gated_default \S+ "\$\{SCRIPT_DIR\}\/([a-z-]+)"\)"/m', $source, $matches);

    expect($matches[2])->toBe([
        'install-bootstrap-runtime',
        'install-target-prerequisites',
        'bootstrap-host',
        'install-target-database',
        'targets',
        'fetch-recovery-material',
    ]);

    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        $bootstrapCalls = array_values(array_filter(
            prepLog($scratch, 'children'),
            static fn (string $line): bool => str_starts_with($line, 'bootstrap '),
        ));

        // Exactly the child's own modes, never a per-slice flag of its own.
        foreach ($bootstrapCalls as $call) {
            expect($call)->toMatch('/^bootstrap --(verify|apply)$/');
        }

        expect($bootstrapCalls)->not->toBeEmpty();
    } finally {
        prepCleanup($scratch);
    }
});

it('passes --target only to the target-aware children, never to bootstrap-host', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepFixture($scratch));

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_starts_with($call, 'bootstrap ') || str_starts_with($call, 'runtime ')) {
                // Host-global children take no target: a host is
                // bootstrapped once and can carry several targets.
                expect($call)->not->toContain('--target');

                continue;
            }

            expect($call)->toContain('--target staging-main');
        }
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Convergence — verify, skip, apply
// =============================================================================

it('skips a slice whose own verify already passes instead of reapplying it', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--apply', '--target', 'staging-main'],
            prepFixture($scratch, ['compliant' => ['runtime', 'prerequisites-host', 'bootstrap']]),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('SKIP — already satisfied');

        // Only the two genuinely unsatisfied slices mutated anything.
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        expect($order)->toBe(['prerequisites-target', 'database']);
    } finally {
        prepCleanup($scratch);
    }
});

it('surfaces conflicting material on a prepared host instead of skipping past it', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepPreparedFixture($scratch);

        // The host is fully prepared and its material-blind verify passes, but
        // the operator supplied a rotated secret. Preparation must report the
        // conflict, not quietly succeed.
        touch($scratch.'/toggles/prerequisites-host-material-conflict');
        touch($scratch.'/toggles/prerequisites-host-apply-fail');

        [$exit, $output] = prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('DIFFERS from the supplied material');
        expect($output)->toContain('resume at slice host-prerequisites');
    } finally {
        prepCleanup($scratch);
    }
});

it('is idempotent: a second run on a prepared host mutates nothing at all', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);

        [$firstExit] = prepRun(['--apply', '--target', 'staging-main'], $env);
        expect($firstExit)->toBe(0);
        expect(prepLog($scratch, 'mutations'))->toHaveCount(5);

        // The convergence the whole safe-to-press-twice contract rests on.
        @unlink($scratch.'/log/mutations.log');

        [$secondExit, $secondOutput] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($secondExit)->toBe(0);
        expect(prepLog($scratch, 'mutations'))->toBe([],
            'a second prepare run must reinstall nothing');
        expect(substr_count($secondOutput, 'SKIP — already satisfied'))->toBe(5);
        expect($secondOutput)->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('stops at the first failing slice and never reaches later ones', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/prerequisites-host-apply-fail');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('re-run prepare-host --apply to resume at slice host-prerequisites');

        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        // The runtime slice converged and stays converged; bootstrap, the
        // target material and the database were never touched.
        expect($order)->toBe(['runtime', 'prerequisites-host']);
    } finally {
        prepCleanup($scratch);
    }
});

it('fails when a child apply reports success but its own verify still does not pass', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/database-apply-no-converge');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('slice database post-apply verification failed');
    } finally {
        prepCleanup($scratch);
    }
});

it('propagates an abnormal child status verbatim and never escalates it into an apply', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch, ['compliant' => ['runtime', 'prerequisites-host']]);
        touch($scratch.'/toggles/bootstrap-readonly-exit-130');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(130, 'a signal-derived child status must survive');
        expect($output)->toContain('slice bootstrap pre-apply verification failed');
        expect(prepLog($scratch, 'mutations'))->toBe([],
            'an interrupted verification must never trigger a child apply');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Read-only modes really are read-only
// =============================================================================

it('uses the material-aware check as the pre-apply gate for the prerequisite slices', function () {
    $scratch = prepScratchDir();

    try {
        // A prerequisite slice's --verify is deliberately material-blind, so
        // skipping on it would let a re-run whose supplied material CONFLICTS
        // with the host sail past the one diagnostic that exists to catch it.
        prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepPreparedFixture($scratch),
        );

        $prerequisiteChecks = array_values(array_filter(
            prepLog($scratch, 'children'),
            static fn (string $line): bool => str_starts_with($line, 'prerequisites '),
        ));

        expect($prerequisiteChecks)->not->toBeEmpty();

        foreach ($prerequisiteChecks as $call) {
            expect($call)->toContain('--check');
            expect($call)->toContain('--material-dir /root/material');
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('falls back to the material-blind verify when no material was supplied', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(['--apply', '--target', 'staging-main'], prepPreparedFixture($scratch));

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_starts_with($call, 'prerequisites ')) {
                expect($call)->toContain('--verify');
                expect($call)->not->toContain('--material-dir');
            }
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('never invokes a child apply from --check or --verify', function () {
    $scratch = prepScratchDir();

    try {
        foreach (['--check', '--verify'] as $mode) {
            prepRun([$mode, '--target', 'staging-main'], prepFixture($scratch));
        }

        expect(prepLog($scratch, 'mutations'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('reports downstream slices as BLOCKED rather than misjudging them', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--check', '--target', 'staging-main'],
            prepFixture($scratch, ['compliant' => ['runtime']]),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('NEEDS_APPLY');
        expect($output)->toContain('BLOCKED until slice host-prerequisites is satisfied');
        expect($output)->toContain('TARGET PREPARED: NO');

        // A database installer cannot meaningfully judge a host that has no
        // PostgreSQL on it yet, so it is never asked.
        $children = prepLog($scratch, 'children');
        $databaseCalls = array_filter($children, static fn (string $l): bool => str_starts_with($l, 'database '));
        expect($databaseCalls)->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('never passes the material directory to a verification', function () {
    $scratch = prepScratchDir();

    try {
        prepRun(
            ['--apply', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepFixture($scratch),
        );

        foreach (prepLog($scratch, 'children') as $call) {
            if (str_contains($call, '--verify')) {
                // A prepared host is judged by what is installed on it,
                // never by what was uploaded beside the run.
                expect($call)->not->toContain('--material-dir');
            }
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses --material-dir on --verify', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main', '--material-dir', '/root/material'],
            prepFixture($scratch),
        );

        expect($exit)->toBe(1);
        expect($output)->toContain('--verify never consults supplied material');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// What preparation deliberately is not
// =============================================================================

it('never deploys, migrates, restores or fabricates release state', function () {
    $source = prepSource();

    // Not a single one of these verbs may appear as an operation this script
    // performs. They are named only in the header that explains why they are
    // absent, so the assertions target executable shapes.
    foreach ([
        '/artisan\s+migrate/',
        '/\bpg_restore\b/',
        '/\brateguru-deploy\b/',
        '/\bcurrent\b\s*->/',
        '/ln\s+-s/',
        '/tar\s+-x/',
    ] as $pattern) {
        expect($source)->not->toMatch($pattern,
            "prepare-host must not contain {$pattern}");
    }

    // And it takes no application-source input of any kind.
    foreach (['--ref', '--release', '--source-sha', '--branch', '--tag', '--migrate'] as $flag) {
        expect($source)->not->toMatch('/^\s*'.preg_quote($flag, '/').'\)/m',
            "prepare-host must not accept {$flag}");
    }
});

it('generates no secret material of any kind', function () {
    $source = prepSource();

    foreach ([
        '/openssl\s+(genrsa|req|rand|dhparam)/',
        '/ssh-keygen\s+-t/',
        '/htpasswd\s/',
        '/artisan\s+key:generate/',
        '/certbot\s/',
    ] as $pattern) {
        expect($source)->not->toMatch($pattern,
            "prepare-host must never generate secret material ({$pattern})");
    }
});

it('reports plainly that a prepared target has no application release', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(
            ['--verify', '--target', 'staging-main'],
            prepPreparedFixture($scratch),
        );

        expect($exit)->toBe(0);
        expect($output)->toContain('APPLICATION DEPLOYED: NOT REQUIRED');
    } finally {
        prepCleanup($scratch);
    }
});

it('gates on the same lifecycle values the committed registry actually declares', function () {
    // The tests above run against a fixture that states its own lifecycles, so
    // this is what ties them back to reality: the two target IDs they exercise,
    // with the lifecycles the committed registry really gives them.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['staging-main']['lifecycle'])->toBe('active');
    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // And `active` is the only value prepare-host accepts, however it is
    // spelled.
    preg_match_all('/lifecycle[^\n]*?[!=]=\s*"?([a-z]+)"?/', prepSource(), $matches);

    expect(array_values(array_unique($matches[1])))->toBe(['active']);
});

it('is not installed into the operational bundle or reachable through a deploy sudo wrapper', function () {
    // Preparation is a root/operator operation run from the trusted bootstrap
    // bundle, exactly like bootstrap-host. Handing the restricted deploy user
    // a sudo path to it would turn a deployment credential into a host
    // administration credential.
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toContain('prepare-host');

    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('prepare-host');

    foreach (glob(base_path('infrastructure/config/wrappers/*')) ?: [] as $wrapper) {
        expect(File::get($wrapper))->not->toContain('prepare-host');
    }
});

// =============================================================================
// The data-operation interlock
// =============================================================================
//
// A live restore or a host recovery owns a target's DATA while its guard
// exists, and preparation is not a data decision. The hazard is concrete: this
// orchestrator's children reconverge the target's Supervisor program and its
// scheduler cron entry, and both operations hold exactly those two aside on
// purpose. GitHub concurrency does not cover it — a hold outlives the workflow
// that created it.

it('refuses to apply while a data operation owns the target, and runs no child', function (string $namespace, string $name) {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        $marker = prepPlantGuard($scratch, $namespace, $name);

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('DATA OPERATION HOLD')
            ->toContain($marker)
            ->toContain('preparation is refused while a data operation owns staging-main')
            ->toContain('nothing was changed');

        // Refused before ANY child ran — not one installer, not even the
        // target-agnostic runtime slice.
        foreach (['runtime', 'prerequisites', 'bootstrap', 'database'] as $child) {
            expect(prepLog($scratch, $child))->toBe([]);
        }
    } finally {
        prepCleanup($scratch);
    }
})->with([
    'a restore guard' => ['restores', 'restore-guard'],
    'a recovery guard' => ['recoveries', 'recovery-guard'],
]);

it('names both guards as a conflict when a target somehow carries both', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        prepPlantGuard($scratch, 'restores', 'restore-guard', ['status' => 'held']);
        prepPlantGuard($scratch, 'recoveries', 'recovery-guard');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('carries BOTH a restore guard and a recovery guard')
            ->toContain('can never legitimately hold the same target at once');
    } finally {
        prepCleanup($scratch);
    }
});

it('reports a hold in read-only modes and keeps going, because an operator needs the diagnosis', function (string $mode) {
    $scratch = prepScratchDir();

    try {
        $env = prepPreparedFixture($scratch);
        prepPlantGuard($scratch, 'recoveries', 'recovery-guard');

        [$exit, $output] = prepRun(['--'.$mode, '--target', 'staging-main'], $env);

        // A prepared host still verifies: the hold is reported, not fatal.
        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('DATA OPERATION HOLD')
            ->toContain('This is a read-only run, so it continues')
            ->toContain('nothing may be APPLIED');
    } finally {
        prepCleanup($scratch);
    }
})->with(['check', 'verify']);

it('prepares normally when no data operation owns the target', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepPreparedFixture($scratch);

        // The run root exists and is empty — the ordinary state of a host that
        // has never had a restore or a recovery held.
        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();

        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->not->toContain('DATA OPERATION HOLD')
            ->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses to apply while a data operation holds its lock, before its guard exists', function (string $prefix, string $expected) {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);

        // The window the guard cannot cover: an operation takes its lock the
        // moment it starts, and its guard appears a beat later. A marker check
        // alone lets a prepare-host --apply through in between — and this
        // orchestrator's children would then reconverge the very Supervisor
        // program and cron entry that operation is about to hold aside.
        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();

        $lockFile = $scratch.'/run/'.$prefix.'-staging.lock';
        touch($lockFile);

        $holder = proc_open(
            ['flock', '-x', $lockFile, 'sleep', '30'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

            expect($exit)->not->toBe(0);
            expect($output)
                ->toContain($expected.' is running for backup namespace staging')
                ->toContain('preparation is refused while it holds '.$lockFile)
                ->toContain('nothing was changed');

            // No guard exists at all — the lock is the whole of what stopped it.
            expect(file_exists($scratch.'/run/recoveries/staging-main/recovery-guard'))->toBeFalse();

            // And not one child ran.
            foreach (['runtime', 'prerequisites', 'bootstrap', 'database'] as $child) {
                expect(prepLog($scratch, $child))->toBe([]);
            }
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        prepCleanup($scratch);
    }
})->with([
    'a live restore' => ['restore-target', 'A restore'],
    'a host recovery' => ['recover-host', 'A host recovery'],
]);

it('does not deadlock against its own grandchild taking the same locks', function () {
    $scratch = prepScratchDir();

    try {
        // The repeatable case Prepare exists for: an already prepared host and
        // a newer infrastructure revision, so the operational bundle genuinely
        // needs updating — which means install-target-operations runs and takes
        // the two data-operation locks itself. If this orchestrator held them,
        // flock would deny its own grandchild, because independently opened
        // descriptors conflict even inside one process tree.
        $env = prepFixture($scratch, ['env' => ['STUB_REACQUIRE_LOCKS' => '1']]);

        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();

        // Both lock files must EXIST and be free, or preparation skips them
        // entirely and the nesting this test exists for never happens.
        touch($scratch.'/run/restore-target-staging.lock');
        touch($scratch.'/run/recover-host-staging.lock');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);

        $locks = File::exists($scratch.'/log/locks.log')
            ? trim(File::get($scratch.'/log/locks.log'))
            : '';

        expect($locks)
            ->toContain('restore-target acquired')
            ->toContain('recover-host acquired')
            ->not->toContain('DENIED');

        // Preparation still claimed its OWN lock for the whole run — that is
        // what a data operation starting mid-Prepare collides with.
        expect(file_exists($scratch.'/run/prepare-host-staging.lock'))->toBeTrue();
    } finally {
        prepCleanup($scratch);
    }
});

it('claims the preparation lock so a data operation starting later refuses', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();

        [$exit] = prepRun(['--apply', '--target', 'staging-main'], $env);
        expect($exit)->toBe(0);

        $preparationLock = $scratch.'/run/prepare-host-staging.lock';
        expect(file_exists($preparationLock))->toBeTrue();

        // The other side of the pair: restore-target and recover-host read this
        // exact path after taking their own lock.
        foreach (['restore-target', 'recover-host'] as $operation) {
            expect(File::get(base_path('infrastructure/scripts/'.$operation)))
                ->toContain('assert_no_host_preparation_running "${BACKUP_NAMESPACE}"');
        }

        expect(File::get(base_path('infrastructure/scripts/restore-common')))
            ->toContain('PREPARE_HOST_LOCK_PREFIX=prepare-host');
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses a second preparation of the same namespace', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();

        $preparationLock = $scratch.'/run/prepare-host-staging.lock';
        touch($preparationLock);

        $holder = proc_open(
            ['flock', '-x', $preparationLock, 'sleep', '30'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

            expect($exit)->not->toBe(0);
            expect($output)->toContain('another host preparation is already running for backup namespace staging');

            foreach (['runtime', 'prerequisites', 'bootstrap', 'database'] as $child) {
                expect(prepLog($scratch, $child))->toBe([]);
            }
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        prepCleanup($scratch);
    }
});

it('prepares normally when the locks exist but nobody holds them', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepPreparedFixture($scratch);

        expect(@mkdir($scratch.'/run', 0o700, true))->toBeTrue();
        touch($scratch.'/run/recover-host-staging.lock');
        touch($scratch.'/run/restore-target-staging.lock');

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->not->toContain('is running for backup namespace');
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// Recovery preparation: the material comes out of the backup
// =============================================================================

/** The seed directory a recovery workflow stages: exactly the two seed files. */
function prepSeedDir(string $scratch): string
{
    $seed = $scratch.'/seed';
    mkdir($seed, 0o700, true);
    chmod($seed, 0o700);
    file_put_contents($seed.'/rclone-config', "[rateguru-b2]\ntype = b2\n");
    file_put_contents($seed.'/deploy-authorized-keys', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleExampleExampleExampleExampleExampleExam deploy\n");
    chmod($seed.'/rclone-config', 0o600);
    chmod($seed.'/deploy-authorized-keys', 0o600);

    return $seed;
}

/** @return list<string> */
function prepRecoveryArguments(string $scratch): array
{
    return ['--apply', '--target', 'staging-main', '--material-dir', prepSeedDir($scratch), '--recovery-backup', '20260115-023000'];
}

it('prepares a replacement host in the only order that can succeed: runtime, material from the backup, host material, bootstrap, the hold, target material, database', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(prepRecoveryArguments($scratch), prepFixture($scratch));

        expect($exit)->toBe(0, $output);

        // The mutating children, in order, are the ordinary five: recovery
        // changes where the material comes from, not what is converged.
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        expect($order)->toBe(['runtime', 'prerequisites-host', 'bootstrap', 'prerequisites-target', 'database']);

        // The fetch runs after the runtime slice (rclone and jq exist only
        // then) and before the first prerequisite slice consumes its result.
        $children = prepLog($scratch, 'children');
        $firstIndexOf = static function (string $prefix) use ($children): int {
            foreach ($children as $index => $line) {
                if (str_starts_with($line, $prefix)) {
                    return $index;
                }
            }

            return -1;
        };

        expect($firstIndexOf('fetch '))->toBeGreaterThan($firstIndexOf('runtime --apply'))
            ->toBeLessThan($firstIndexOf('prerequisites --'));

        expect($children)->toContain('fetch --target staging-main --backup 20260115-023000 --seed-dir '.$scratch.'/seed --output-dir '.trim(File::get($scratch.'/log/fetch-output-dir')));

        // The hold is placed after host bootstrap created the run root and
        // before the target slice installs the offsite credential: the host
        // slice ran without it, the target slice ran with it.
        expect(prepLog($scratch, 'hold-seen'))->toBe(['prerequisites-host hold-absent', 'prerequisites-target hold-present']);

        $hold = json_decode(File::get($scratch.'/run/offsite-write-hold'), true);

        expect($hold)->toMatchArray([
            'hold' => 'offsite-writes',
            'reason' => 'host-recovery',
            'target' => 'staging-main',
            'backup' => '20260115-023000',
            'created_by' => 'prepare-host --recovery-backup',
        ]);
        expect($hold)->toHaveKey('created_at');

        expect($output)
            ->toContain('RECOVERY MATERIAL — offsite backup 20260115-023000')
            ->toContain('OFFSITE WRITES: HELD — '.$scratch.'/run/offsite-write-hold')
            ->toContain('TARGET PREPARED');
    } finally {
        prepCleanup($scratch);
    }
});

it('feeds the prerequisite slices the effective material, never the seed, and removes it however the run ends', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(prepRecoveryArguments($scratch), prepFixture($scratch));
        expect($exit)->toBe(0, $output);

        $effective = trim(File::get($scratch.'/log/fetch-output-dir'));

        // Root-only, under the recovery material parent, created for this run.
        expect($effective)->toStartWith($scratch.'/root-home/rateguru-recovery-material.');
        expect(trim(File::get($scratch.'/log/fetch-output-mode')))->toBe('700');
        expect(trim(File::get($scratch.'/log/fetch-seed-dir')))->toBe($scratch.'/seed');

        // Every prerequisite invocation that carried material carried the
        // effective directory — the seed never reaches a slice.
        $materialCalls = array_values(array_filter(
            prepLog($scratch, 'children'),
            static fn (string $line): bool => str_starts_with($line, 'prerequisites ') && str_contains($line, '--material-dir'),
        ));

        expect($materialCalls)->not->toBeEmpty();

        foreach ($materialCalls as $call) {
            expect($call)->toContain('--material-dir '.$effective)
                ->not->toContain($scratch.'/seed');
        }

        // And what the slices saw in it was the composed material: the
        // environment file, every host-scope name and both seeds.
        foreach (prepLog($scratch, 'material-seen') as $seen) {
            foreach ([
                'laravel-env', 'basic-auth', 'tls-certificate', 'tls-private-key', 'tls-dhparams',
                'nginx-tls-options', 'mail-tls-certificate', 'mail-tls-private-key', 'deploy-authorized-keys', 'rclone-config',
            ] as $name) {
                expect($seen)->toContain($name);
            }
        }

        // Gone when the run ended.
        expect(glob($scratch.'/root-home/rateguru-recovery-material.*'))->toBe([]);
        expect($output)->toContain('removed when this run ends');
    } finally {
        prepCleanup($scratch);
    }

    $scratch = prepScratchDir();

    try {
        // A refused fetch stops the preparation before any target-specific
        // slice, leaves no effective material behind and places no hold.
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/fetch-fail');

        [$exit, $output] = prepRun(prepRecoveryArguments($scratch), $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('not clean-host-recovery-capable');

        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );

        expect($order)->toBe(['runtime']);
        expect(glob($scratch.'/root-home/rateguru-recovery-material.*'))->toBe([]);
        expect(File::exists($scratch.'/run/offsite-write-hold'))->toBeFalse();
    } finally {
        prepCleanup($scratch);
    }
});

it('accepts --recovery-backup only as an exact timestamp, only with --apply, and only beside a seed directory', function (array $arguments, string $expected) {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun($arguments, prepFixture($scratch));

        expect($exit)->toBe(1);
        expect($output)->toContain($expected);
        expect(File::exists($scratch.'/log/children.log'))->toBeFalse('no child may run on a refused invocation');
    } finally {
        prepCleanup($scratch);
    }
})->with([
    'the check mode' => [
        ['--check', '--target', 'staging-main', '--material-dir', '/root/seed', '--recovery-backup', '20260115-023000'],
        'never with --check',
    ],
    'a verification given material' => [
        ['--verify', '--target', 'staging-main', '--material-dir', '/root/seed', '--recovery-backup', '20260115-023000'],
        '--verify never consults supplied material',
    ],
    'no seed directory' => [
        ['--apply', '--target', 'staging-main', '--recovery-backup', '20260115-023000'],
        '--recovery-backup requires --material-dir naming the seed directory',
    ],
    'no latest' => [
        ['--apply', '--target', 'staging-main', '--material-dir', '/root/seed', '--recovery-backup', 'latest'],
        "there is no 'latest'",
    ],
    'a date without a time' => [
        ['--apply', '--target', 'staging-main', '--material-dir', '/root/seed', '--recovery-backup', '20260115'],
        'exact offsite backup timestamp YYYYMMDD-HHMMSS',
    ],
]);

it('gates a recovery preparation on lifecycle before fetching anything', function () {
    $scratch = prepScratchDir();

    try {
        $arguments = prepRecoveryArguments($scratch);
        $arguments[2] = 'tits-guru';

        [$exit, $output] = prepRun($arguments, prepFixture($scratch));

        expect($exit)->toBe(1);
        expect($output)->toContain('lifecycle=planned');
        expect(File::exists($scratch.'/log/children.log'))->toBeFalse();
        expect(File::exists($scratch.'/log/fetch-output-dir'))->toBeFalse();
    } finally {
        prepCleanup($scratch);
    }
});

it('leaves an ordinary preparation exactly as it was: no fetch, no hold', function () {
    $scratch = prepScratchDir();

    try {
        $seed = prepSeedDir($scratch);

        [$exit, $output] = prepRun(['--apply', '--target', 'staging-main', '--material-dir', $seed], prepFixture($scratch));

        expect($exit)->toBe(0, $output);

        foreach (prepLog($scratch, 'children') as $line) {
            expect($line)->not->toStartWith('fetch ');
        }

        expect(File::exists($scratch.'/run/offsite-write-hold'))->toBeFalse();
        expect($output)->not->toContain('OFFSITE WRITES')
            ->not->toContain('RECOVERY MATERIAL');
        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        prepCleanup($scratch);
    }
});

it('keeps a hold that is already in place, and never rewrites it', function () {
    $scratch = prepScratchDir();

    try {
        mkdir($scratch.'/run', 0o700, true);
        $planted = json_encode(['hold' => 'offsite-writes', 'created_by' => 'somebody-earlier']);
        file_put_contents($scratch.'/run/offsite-write-hold', $planted);

        [$exit, $output] = prepRun(prepRecoveryArguments($scratch), prepFixture($scratch));

        expect($exit)->toBe(0, $output);
        expect(File::get($scratch.'/run/offsite-write-hold'))->toBe($planted);
        expect($output)->toContain('OFFSITE WRITES: HELD (already)');
    } finally {
        prepCleanup($scratch);
    }
});

it('never releases the offsite-write hold, in any mode', function () {
    $source = executableSourceLines(prepSource());

    // The hold is placed, reported and kept. Releasing it is part of
    // deliberately adopting the machine, which is not a preparation concern.
    foreach (preg_split('/\R/', $source) as $line) {
        if (! str_contains($line, 'offsite-write-hold') && ! str_contains($line, 'offsite_write_hold')) {
            continue;
        }

        expect($line)->not->toMatch('/\brm\b/')
            ->not->toMatch('/\bmv\b/')
            ->not->toMatch('/\bunlink\b/');
    }

    // A read-only mode reports the hold and changes nothing.
    $scratch = prepScratchDir();

    try {
        mkdir($scratch.'/run', 0o700, true);
        file_put_contents($scratch.'/run/offsite-write-hold', json_encode(['hold' => 'offsite-writes']));

        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main'], prepPreparedFixture($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OFFSITE WRITES: HELD');
        expect(File::exists($scratch.'/run/offsite-write-hold'))->toBeTrue();
    } finally {
        prepCleanup($scratch);
    }
});

// =============================================================================
// A recovery preparation is verified as one: the hold is part of "prepared"
// =============================================================================

/** The hold document prepare-host writes, for staging-main from one backup. */
function prepPlantHold(string $scratch, array $overrides = []): string
{
    @mkdir($scratch.'/run', 0o700, true);

    $path = $scratch.'/run/offsite-write-hold';
    file_put_contents($path, json_encode(array_merge([
        'hold' => 'offsite-writes',
        'reason' => 'host-recovery',
        'target' => 'staging-main',
        'backup' => '20260115-023000',
        'created_by' => 'prepare-host --recovery-backup',
        'created_at' => '2026-01-15T03:00:00Z',
    ], $overrides)));

    return $path;
}

it('verifies a recovery preparation only when the offsite-write hold is in place', function () {
    $scratch = prepScratchDir();

    try {
        prepPlantHold($scratch);

        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main', '--recovery-backup', '20260115-023000'], prepPreparedFixture($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('Recovery preparation from offsite backup 20260115-023000: the offsite-write hold is required')
            ->toContain('RECOVERY PREPARATION: HELD — offsite writes held on this host for staging-main from backup 20260115-023000')
            ->toContain('placed by prepare-host --recovery-backup for staging-main from backup 20260115-023000')
            ->toContain('OFFSITE WRITES: HELD')
            ->toContain('TARGET PREPARED: YES');

        // Read-only: no child apply, and the hold is untouched.
        expect(File::exists($scratch.'/log/mutations.log'))->toBeFalse();
        expect(File::exists($scratch.'/run/offsite-write-hold'))->toBeTrue();
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses to call a recovery preparation prepared when its hold is missing or unreadable', function (callable $arrange, string $expected) {
    $scratch = prepScratchDir();

    try {
        $arrange($scratch);

        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main', '--recovery-backup', '20260115-023000'], prepPreparedFixture($scratch));

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('recovery preparation of staging-main from backup 20260115-023000 is NOT complete')
            ->toContain($expected)
            ->not->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
})->with([
    'no hold at all' => [
        function (string $scratch): void {},
        '/run/offsite-write-hold is missing',
    ],
    'not a hold document' => [
        function (string $scratch): void {
            prepPlantHold($scratch, ['hold' => 'something-else']);
        },
        'is not an offsite-write hold document',
    ],
    'a hold that is a symlink' => [
        function (string $scratch): void {
            @mkdir($scratch.'/run', 0o700, true);
            file_put_contents($scratch.'/elsewhere.json', json_encode(['hold' => 'offsite-writes']));
            symlink($scratch.'/elsewhere.json', $scratch.'/run/offsite-write-hold');
        },
        'is not a regular file',
    ],
]);

it('accepts the hold an earlier preparation or recovery of this machine placed, and reports whose it is', function () {
    // The hold is host-global: whoever placed it, and for whichever backup,
    // the machine's offsite writers are fenced. It is kept, never rewritten,
    // so the verification reports its identity rather than enforcing it.
    $scratch = prepScratchDir();

    try {
        prepPlantHold($scratch, ['backup' => '20260101-000000', 'created_by' => 'recover-host --apply']);

        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main', '--recovery-backup', '20260115-023000'], prepPreparedFixture($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RECOVERY PREPARATION: HELD')
            ->toContain('placed by recover-host --apply for staging-main from backup 20260101-000000')
            ->toContain('TARGET PREPARED: YES');
    } finally {
        prepCleanup($scratch);
    }
});

it('refuses to report a recovery preparation applied when the hold vanished before the end of the run', function () {
    $scratch = prepScratchDir();

    try {
        $env = prepFixture($scratch);
        touch($scratch.'/toggles/remove-hold-after-target');

        [$exit, $output] = prepRun(prepRecoveryArguments($scratch), $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('recovery preparation of staging-main from backup 20260115-023000 is NOT complete')
            ->toContain('the offsite-write hold')
            ->toContain('is missing')
            ->not->toContain('TARGET PREPARED: YES');

        // Every slice ran; the refusal is about the fence, not the slices.
        $order = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            prepLog($scratch, 'mutations'),
        );
        expect($order)->toBe(['runtime', 'prerequisites-host', 'bootstrap', 'prerequisites-target', 'database']);
    } finally {
        prepCleanup($scratch);
    }
});

it('verifies an ordinary preparation without demanding a hold, and reports one when it happens to exist', function () {
    $scratch = prepScratchDir();

    try {
        [$exit, $output] = prepRun(['--verify', '--target', 'staging-main'], prepPreparedFixture($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->not->toContain('RECOVERY PREPARATION')
            ->not->toContain('OFFSITE WRITES');
    } finally {
        prepCleanup($scratch);
    }
});
