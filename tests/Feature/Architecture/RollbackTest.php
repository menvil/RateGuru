<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration: target-aware rollback.
 *
 * These tests execute the real shipped `infrastructure/scripts/rollback` —
 * never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator,
 * health-check, systemctl) supplied through the RATEGURU_* test-override
 * contract already established for health-check/status/cleanup/deploy, plus
 * one new override specific to rollback (RATEGURU_HEALTH_CHECK_BIN, the same
 * name deploy already uses for its own health-check dependency).
 *
 * rollback's own source guard (`if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
 * main "$@"; fi`) means sourcing the file never auto-runs main() — tests
 * simply `source` the real file directly, then call
 * parse_rollback_args/resolve_target/perform_rollback (bypassing
 * require_root, for parsing/resolution/pipeline coverage that doesn't need
 * real root) or invoke the script as a genuine subprocess (which does run
 * main(), requiring root first, exactly as production) — the same technique
 * DeployTest.php already established.
 *
 * No `chown`/ownership assertions are needed anywhere in this suite:
 * rollback never touches file ownership, only symlinks, history, PHP-FPM
 * reload and the target's queue worker — unlike deploy, which extracts and
 * chowns release trees. The queue worker runs against deploy's own stateful
 * supervisorctl and runuser stubs (deployOpsInstallCoreStubs in
 * tests/Pest.php), with a php stub of this file's own that records which
 * release directory each artisan call ran from.
 */
function rollbackOpsScript(): string
{
    return base_path('infrastructure/scripts/rollback');
}

function rollbackOpsSource(): string
{
    return File::get(rollbackOpsScript());
}

function rollbackOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function rollbackOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function rollbackOpsRegistryPath(): string
{
    return preActivationRegistryPath();
}

function rollbackOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function rollbackOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/rollback-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function rollbackOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports). fd 2 is redirected onto fd 1 at the descriptor
 * level so there is only one stream to drain.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Run the real rollback script as a genuine subprocess (never sourced) —
 * this is what makes BASH_SOURCE[0] == $0 true inside it, so its own source
 * guard calls main(), which calls require_root first, exactly like
 * production.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', rollbackOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start rollback subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources the real rollback script (BASH_SOURCE[0] != $0 here, so main()
 * never auto-runs) then runs $body, which can call
 * parse_rollback_args/resolve_target/perform_rollback/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function rollbackOpsRunHarness(string $scratch, string $body, array $env = []): array
{
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg(rollbackOpsScript())."\n".$body."\n";
    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return rollbackOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function rollbackOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
        // The machine's lock, in this test's own scratch: a target operation holds
        // it shared for its whole run, and never on the real host path.
        'RATEGURU_HOST_LOCK_ROOT' => $scratch,
        'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => rollbackOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => rollbackOpsTargetsCli(),
    ], $overrides);
}

/**
 * A stub matching health-check's real --target CLI shape closely enough for
 * rollback's own purposes: logs its argv, exits 0 (or 1 to simulate a failed
 * post-switch/recovery health check).
 */
function rollbackOpsHealthCheckStub(string $scratch, string $logFile, bool $fail = false): string
{
    $path = $scratch.'/bin/health-check-stub-'.uniqid('', true);
    $exitCode = $fail ? 1 : 0;
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed stub for systemctl, installed after (and so in place of)
 * deploy's core systemctl stub wherever both are used. Defaults to a shared,
 * scratch-wide log for tests that only ever run one rollback per scratch
 * dir; callers that run more than one rollback against the same scratch dir
 * must pass a distinct $logFile per run so each run's reload count can be
 * asserted independently rather than against a combined log. Returns the log
 * file path used.
 */
function rollbackOpsInstallSystemctlStub(string $scratch, ?string $logFile = null): string
{
    $logFile ??= $scratch.'/systemctl.log';

    file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
        .'echo "systemctl $*" >> '.escapeshellarg($logFile)."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/systemctl', 0o755);

    return $logFile;
}

/**
 * A target root with three real release directories: "old" (what `previous`
 * points at), "current" (what `current` points at), and "explicit" (a third
 * release, pointed at by neither — used by --release destination tests so
 * they exercise a genuinely different code path than --previous).
 *
 * @return array{root: string, old: string, current: string, explicit: string}
 */
function rollbackOpsBuildFixture(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    $releaseOld = 'v1.0.0-20260101-000000-aaa1111';
    $releaseCurrent = 'v1.0.1-20260102-000000-bbb2222';
    $releaseExplicit = 'v1.0.2-20260103-000000-ccc3333';

    foreach ([$releaseOld, $releaseCurrent, $releaseExplicit] as $releaseId) {
        $dir = $root.'/releases/'.$releaseId;
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/marker.txt', "release {$releaseId}\n");
        // Every release is a Laravel application: the queue transition
        // signals the workers through its artisan.
        file_put_contents($dir.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");
    }

    symlink($root.'/releases/'.$releaseCurrent, $root.'/current');
    symlink($root.'/releases/'.$releaseOld, $root.'/previous');

    return [
        'root' => $root,
        'old' => $releaseOld,
        'current' => $releaseCurrent,
        'explicit' => $releaseExplicit,
    ];
}

/**
 * A target root with a current release and one other release, but no
 * `previous` symlink at all — used by the failure-recovery regression test
 * proving `previous` stays absent after a restored recovery, rather than
 * being left behind by a half-finished rollback attempt.
 *
 * @return array{root: string, current: string, target: string}
 */
function rollbackOpsBuildFixtureNoPrevious(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    $releaseCurrent = 'v2.0.0-20260201-000000-ddd4444';
    $releaseTarget = 'v2.0.1-20260202-000000-eee5555';

    foreach ([$releaseCurrent, $releaseTarget] as $releaseId) {
        mkdir($root.'/releases/'.$releaseId, 0o755, true);
        file_put_contents($root.'/releases/'.$releaseId.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");
    }

    symlink($root.'/releases/'.$releaseCurrent, $root.'/current');

    return ['root' => $root, 'current' => $releaseCurrent, 'target' => $releaseTarget];
}

/**
 * A bare target root — releases/deployments/locks directories only, no
 * releases, no current/previous symlinks — for path-safety tests that build
 * their own exact, deliberately unsafe layout.
 *
 * @return array{root: string}
 */
function rollbackOpsBareFixture(string $scratch): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;

    foreach ([$root.'/releases', $root.'/deployments', $root.'/locks'] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }

    return ['root' => $root];
}

/**
 * A scratch, writable copy of the real committed deployment.conf.example,
 * verbatim. Formerly rewrote STAGING_ROOT to point at each fixture's own
 * root — but the template no longer carries any target-specific field at
 * all (see infrastructure/templates/deployment.conf.example's own header
 * comment): the application root now comes exclusively from the target
 * registry, via rollbackOpsParityRegistry(), which already uses this same
 * fixture's own root. $fixture is accepted only for call-site compatibility
 * with every existing caller (both the full and bare fixture builders, which
 * both carry a 'root' key); its contents are no longer read.
 *
 * @param  array{root: string}  $fixture
 */
function rollbackOpsDeploymentConfForFixture(string $scratch, array $fixture): string
{
    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, File::get(rollbackOpsDeploymentConfPath()));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique DeployTest.php/CleanupTest.php
 * already established, re-derived here per this codebase's convention of
 * each test file owning its own helper namespace. runtime_user/deploy_user/
 * runtime_group/code_group are the current test process's own account/
 * group — rollback itself never uses them (no chown, no runuser), but the
 * registry schema requires every target to declare them regardless.
 *
 * @param  array{root: string}  $fixture
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function rollbackOpsParityRegistry(string $scratch, array $fixture): array
{
    $account = rollbackOpsCurrentAccount();
    $group = rollbackOpsCurrentGroup();

    // See DeployTest.php's own deployOpsParityRegistry() for why each of
    // these four constraints is relaxed in this throwaway, test-only copy of
    // the committed `targets` validator.
    $patchedTargets = targetsSourceAllowingActive(File::get(rollbackOpsTargetsCli()), 'parity-target');
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'elif [[ "${incoming}" != /home/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_group}" ]]; then',
        'if false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_user}" ]]; then',
        'if false; then',
        $patchedTargets,
    );

    $targetsPath = $scratch.'/parity-targets';
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $fixture['root'],
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => $account,
                'code_group' => $group,
                'incoming_artifacts' => $scratch.'/incoming-unused',
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example', 'parity-secondary.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
                'php_fpm' => ['pool' => 'parity', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

function rollbackOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function rollbackOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

/**
 * A php stub for the queue transition: records the directory each artisan call
 * runs from — the release it signals from — and its argv, tab-separated, in
 * $scratch/artisan.log. `artisan queue:restart` fails when run from the
 * release directory written into $scratch/php-fails-in, so a test can fail
 * the forward signal (from the restored release) or the recovery one (from
 * the original release) on its own.
 */
function rollbackOpsPhpStub(string $scratch): string
{
    $path = $scratch.'/bin/fake-php';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'log='.escapeshellarg($scratch.'/artisan.log')."\n"
        .'fails_in='.escapeshellarg($scratch.'/php-fails-in')."\n"
        .<<<'STUB'
        here="$(pwd -P)"
        printf '%s\t%s\n' "${here}" "$*" >> "${log}"
        if [[ "$*" == *queue:restart* ]] && [[ -s "${fails_in}" ]] && [[ "${here}" == "$(cat "${fails_in}")" ]]; then
            echo "could not write the queue restart signal" >&2
            exit 1
        fi
        exit 0
        STUB."\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * The artisan calls a run made, in order, each with the directory it ran from.
 *
 * @return list<array{dir: string, command: string}>
 */
function rollbackOpsArtisanCalls(string $scratch): array
{
    $raw = trim((string) @file_get_contents($scratch.'/artisan.log'));

    if ($raw === '') {
        return [];
    }

    return array_map(static function (string $line): array {
        [$dir, $command] = explode("\t", $line, 2);

        return ['dir' => $dir, 'command' => $command];
    }, explode("\n", $raw));
}

/**
 * The target's queue worker as rollback meets it: deploy's stateful
 * supervisorctl and runuser stubs, this file's php stub as the deployment
 * configuration's PHP_BIN, and the worker RUNNING (or not) for the snapshot
 * to read. $sequence scripts the status answers instead, one per call
 * (deployOpsSupervisorStatusSequence); $toggles are the supervisorctl stub's
 * failure switches (activation-fail, stop-fail, ...).
 *
 * Installs deploy's systemctl stub too: install rollback's own afterwards
 * where a test asserts its reloads.
 *
 * @param  list<string>|null  $sequence
 * @param  list<string>  $toggles
 */
function rollbackOpsInstallQueue(string $scratch, string $confPath, bool $running = true, ?array $sequence = null, array $toggles = []): void
{
    deployOpsInstallCoreStubs($scratch);

    file_put_contents(
        $confPath,
        preg_replace('/^PHP_BIN=.*$/m', 'PHP_BIN='.rollbackOpsPhpStub($scratch), File::get($confPath)),
    );

    if ($running) {
        touch($scratch.'/supervisor-state/queue-running');
    }

    if ($sequence !== null) {
        deployOpsSupervisorStatusSequence($scratch, $sequence);
    }

    foreach ($toggles as $toggle) {
        touch($scratch.'/supervisor-state/'.$toggle);
    }
}

/**
 * Runs one full, real rollback (lock, current/previous validation, the queue
 * snapshot, history, atomic switch, PHP-FPM reload, health-check, the queue
 * transition) against a fresh fixture, via --target parity-target, and either
 * --previous or an explicit --release.
 *
 * $queue sets up the target's worker (rollbackOpsInstallQueue): 'running'
 * (default true — a deployed target's worker runs), 'sequence', 'toggles', and
 * 'php_fails_in' — the fixture release ('old', 'current' or 'explicit') whose
 * artisan cannot write the queue restart signal. The wait budget is three
 * attempts with no delay unless $env says otherwise.
 *
 * @param  array{running?: bool, sequence?: list<string>, toggles?: list<string>, php_fails_in?: string}  $queue
 * @param  array<string, string>  $env
 * @return array{exit: int, output: string, fixture: array, healthCheckLog: string, systemctlLog: string}
 */
function rollbackOpsRunFullRollback(string $scratch, ?string $explicitRelease, bool $failHealthCheck = false, array $queue = [], array $env = []): array
{
    $fixture = rollbackOpsBuildFixture($scratch);
    $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
    [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

    rollbackOpsInstallQueue(
        $scratch,
        $confPath,
        running: $queue['running'] ?? true,
        sequence: $queue['sequence'] ?? null,
        toggles: $queue['toggles'] ?? [],
    );

    if (isset($queue['php_fails_in'])) {
        file_put_contents($scratch.'/php-fails-in', realpath($fixture['root'].'/releases/'.$fixture[$queue['php_fails_in']]));
    }

    // A run-unique log, not the shared scratch-wide default: callers that run
    // this more than once against the same $scratch must be able to assert
    // each run's own reload count independently.
    $systemctlLog = $scratch.'/systemctl-'.uniqid('', true).'.log';
    rollbackOpsInstallSystemctlStub($scratch, $systemctlLog);

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $healthCheckStub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog, $failHealthCheck);

    $env = rollbackOpsBaseEnv($scratch, array_merge([
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
        'RATEGURU_ROLLBACK_QUEUE_WAIT_ATTEMPTS' => '3',
        'RATEGURU_ROLLBACK_QUEUE_RETRY_DELAY' => '0',
    ], $env));

    $destinationArgs = $explicitRelease !== null ? "--release {$explicitRelease}" : '--previous';

    [$exit, $output] = rollbackOpsRunHarness(
        $scratch,
        "parse_rollback_args --target parity-target {$destinationArgs}\nresolve_target\nperform_rollback",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'healthCheckLog' => $healthCheckLog,
        'systemctlLog' => $systemctlLog,
    ];
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --previous',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --environment staging --previous', rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target a --target b --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --target given without a value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --target', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty selector value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target '' --previous",
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a selector that swallows the next flag as its value', function () {
    // Regression-shaped: a value-taking flag must never blindly take
    // whatever token follows it, including another flag — mirrors deploy's
    // identical protection for --target.
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --target --previous', rollbackOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --previous');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --help', rollbackOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->toContain('--release RELEASE_ID')
            ->toContain('--previous')
            ->not->toContain('--environment');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'parse_rollback_args --bogus', rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Destination contract: --release RELEASE_ID | --previous
// =============================================================================

it('parses an explicit --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --release v1.0.0-20260101-000000-abc1234'
            .'; printf "RELEASE_ID=[%s]\nUSE_PREVIOUS=[%s]\n" "${RELEASE_ID}" "${USE_PREVIOUS}"',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[v1.0.0-20260101-000000-abc1234]')
            ->toContain('USE_PREVIOUS=[false]');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('parses --previous', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --previous'
            .'; printf "RELEASE_ID=[%s]\nUSE_PREVIOUS=[%s]\n" "${RELEASE_ID}" "${USE_PREVIOUS}"',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[]')
            ->toContain('USE_PREVIOUS=[true]');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('requires a rollback destination', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release or --previous is required');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --release and --previous used together, in either order', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --release v1.0.0-20260101-000000-abc1234 --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release and --previous cannot be combined');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --previous --release v1.0.0-20260101-000000-abc1234',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release and --previous cannot be combined');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --release v1.0.0-20260101-000000-abc1234 --release v1.0.1-20260101-000000-abc1235',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects duplicate --previous', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --previous --previous',
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--previous given more than once');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects --release given without a value, with an empty value, or with a flag-shaped value', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --release',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target staging-main --release ''",
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a non-empty value');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            'parse_rollback_args --target staging-main --release --previous',
            rollbackOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value, not another option: --previous');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an invalid release ID at the pipeline level', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --release not-a-valid-release-id
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid release ID');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects rolling back onto the release that is already current', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<BASH
            parse_rollback_args --target parity-target --release {$fixture['current']}
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target release is already current');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects rolling back onto the already-current release even when the current symlink target carries a trailing slash', function () {
    // Regression test: the "already current" guard used to compare
    // ORIGINAL_CURRENT_PATH and TARGET_RELEASE_PATH as literal strings.
    // ORIGINAL_CURRENT_PATH is the symlink's raw, single-hop target — a
    // trailing slash there is a perfectly valid way to name the same
    // release directory, but a literal-string comparison against the
    // freshly-constructed, slash-free TARGET_RELEASE_PATH never matched, so
    // the guard silently let the swap proceed and would have overwritten
    // previous with what was already current for no actual change.
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $releaseId = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$releaseId, 0o755, true);
        symlink($fixture['root'].'/releases/'.$releaseId.'/', $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<BASH
            parse_rollback_args --target parity-target --release {$releaseId}
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('target release is already current');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Target resolution
// =============================================================================

it('resolve_target resolves the root only from the registry, via require_active_target', function () {
    $source = rollbackOpsSource();

    expect(preg_match(
        '/^resolve_target\(\) \{\n(.*?)\n\}\n/ms',
        $source,
        $matches,
    ))->toBe(1, 'could not locate resolve_target() in scripts/rollback');

    expect($matches[1])
        ->toContain('require_active_target')
        ->toContain('target_root')
        ->not->toContain('environment_root')
        ->not->toContain('validate_environment');
});

// =============================================================================
// Lifecycle ordering: root-first, then lifecycle=planned, before any
// filesystem, lock, or history work
// =============================================================================

it('requires root before anything else, even for --target with an obviously invalid rollback', function () {
    if (testProcessIsRoot()) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = rollbackOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);

        [$exit, $output] = rollbackOpsRunScript(
            ['--target', 'tits-guru', '--release', 'v0.0.0-20260728-000000-abcdef0'],
            rollbackOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
        expect(deployOpsSupervisorctlLog($scratch))->toBe([], 'root authorization comes before any look at the queue worker');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a planned target before any filesystem, lock, or history work', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target tits-guru --release v0.0.0-20260728-000000-abcdef0
            resolve_target
            echo "SHOULD NOT REACH HERE"
            BASH, rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
        expect(deployOpsSupervisorctlLog($scratch))->toBe([], 'a planned target never reaches the queue worker');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target ghost-target --previous
            resolve_target
            BASH, rollbackOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Release path safety: every case must fail closed before any symlink is
// switched.
// =============================================================================

it('rejects a current release located outside the releases root', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $outside = $scratch.'/outside-root/v9.0.0-20260101-000000-fff9999';
        mkdir($outside, 0o755, true);
        symlink($outside, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a previous release located outside the releases root', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $outside = $scratch.'/outside-root/v9.0.0-20260101-000000-fff9999';
        mkdir($outside, 0o755, true);
        symlink($outside, $fixture['root'].'/previous');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an explicit --release that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $explicitId = 'v1.0.5-20260105-000000-abc5555';
        symlink($elsewhere, $fixture['root'].'/releases/'.$explicitId);

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<BASH
            parse_rollback_args --target parity-target --release {$explicitId}
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('explicit release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a current release directory that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $currentId = 'v1.0.0-20260101-000000-aaa1111';
        symlink($elsewhere, $fixture['root'].'/releases/'.$currentId);
        symlink($fixture['root'].'/releases/'.$currentId, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a previous release directory that is itself a symlink', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        $elsewhere = $scratch.'/elsewhere';
        mkdir($elsewhere, 0o755, true);
        $previousId = 'v0.9.0-20251201-000000-def5678';
        symlink($elsewhere, $fixture['root'].'/releases/'.$previousId);
        symlink($fixture['root'].'/releases/'.$previousId, $fixture['root'].'/previous');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous release must not be a symlink');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a previous that exists as a plain file, instead of silently treating it as absent', function () {
    // Regression test: the presence check used to be `-L` only, so an
    // existing non-symlink at PREVIOUS_LINK (a plain file left by a bug or
    // an operator mistake) was treated exactly like "previous does not
    // exist" — routing past validate_pointer_release entirely and leaving
    // it to be silently clobbered by the later atomic mv -Tf switch, with no
    // validation ever run against it.
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $validCurrent = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/'.$validCurrent, 0o755, true);
        symlink($fixture['root'].'/releases/'.$validCurrent, $fixture['root'].'/current');

        file_put_contents($fixture['root'].'/previous', "not a symlink\n");

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous symlink does not exist');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
        expect(file_get_contents($fixture['root'].'/previous'))->toBe("not a symlink\n", 'the pre-existing file must be left untouched, never clobbered');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a nested release path', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        $nestedId = 'v1.0.0-20260101-000000-aaa1111';
        mkdir($fixture['root'].'/releases/subdir/'.$nestedId, 0o755, true);
        symlink($fixture['root'].'/releases/subdir/'.$nestedId, $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release is not a direct child of the releases root');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects an invalid release ID basename', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        mkdir($fixture['root'].'/releases/not-a-valid-release-id', 0o755, true);
        symlink($fixture['root'].'/releases/not-a-valid-release-id', $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid release ID');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rejects a missing release target', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBareFixture($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        // A dangling symlink: the target directory is never created.
        symlink($fixture['root'].'/releases/v1.0.0-20260101-000000-missing0', $fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness($scratch, <<<'BASH'
            parse_rollback_args --target parity-target --previous
            resolve_target
            perform_rollback
            BASH, rollbackOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current release does not exist');
        expect(file_exists($fixture['root'].'/deployments/history.jsonl'))->toBeFalse('no history may be written before path safety passes');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// End-to-end scratch parity: identical rollback through both selectors
// =============================================================================

it('rolls back via --target for --previous: new current/previous, history, PHP-FPM reload, health selector', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null);
        expect($result['exit'])->toBe(0, $result['output']);

        $root = $result['fixture']['root'];
        $oldReleaseRoot = $root.'/releases/'.$result['fixture']['old'];
        $currentReleaseRoot = $root.'/releases/'.$result['fixture']['current'];

        expect(realpath($root.'/current'))->toBe(realpath($oldReleaseRoot), 'current must now point at the former previous release');
        expect(realpath($root.'/previous'))->toBe(realpath($currentReleaseRoot), 'previous must now point at the former current release');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2, 'rollback-started + rollback-finished');
        $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
        $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
        expect($started['event'])->toBe('rollback-started');
        expect($started['status'])->toBe('running');
        expect($finished['event'])->toBe('rollback-finished');
        expect($finished['status'])->toBe('success');
        expect($finished['release'])->toBe($result['fixture']['old']);

        expect(substr_count(trim(file_get_contents($result['systemctlLog'])), 'reload'))->toBe(1, 'exactly one PHP-FPM reload for a successful, non-recovering rollback');

        expect(file_exists($root.'/current.new'))->toBeFalse('temporary current symlink must be cleaned up');
        expect(file_exists($root.'/previous.new'))->toBeFalse('temporary previous symlink must be cleaned up');

        expect(trim(File::get($result['healthCheckLog'])))->toBe('--target parity-target');
        expect($result['output'])->toContain('parity-target rolled back to');

        // The running worker was told to reboot, from the restored release,
        // as the registry's runtime user — and nothing else was asked of
        // Supervisor than to report the target's own program.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([
            ['dir' => realpath($oldReleaseRoot), 'command' => 'artisan queue:restart'],
        ]);
        expect(File::get($scratch.'/runuser.log'))->toBe('runuser -u '.rollbackOpsCurrentAccount().' -- '.$scratch.'/bin/fake-php artisan queue:restart'."\n");
        expect($result['output'])
            ->toContain('supervisor worker parity-queue already RUNNING — no supervisor registration/start churn')
            ->toContain('queue restart signal issued from '.$result['fixture']['old']);
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect($call)->toBe('supervisorctl status parity-queue:*');
        }
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('rolls back via --target for an explicit --release', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // Read the canonical "explicit" release ID from the fixture builder
        // itself (a throwaway peek fixture), rather than duplicating its
        // literal value here.
        $explicitRelease = rollbackOpsBuildFixture($scratch.'/peek')['explicit'];

        $result = rollbackOpsRunFullRollback($scratch, $explicitRelease);
        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['fixture']['explicit'])->toBe($explicitRelease);

        $root = $result['fixture']['root'];
        $explicitReleaseRoot = $root.'/releases/'.$explicitRelease;
        $currentReleaseRoot = $root.'/releases/'.$result['fixture']['current'];

        expect(realpath($root.'/current'))->toBe(realpath($explicitReleaseRoot), 'current must point at the explicit release');
        expect(realpath($root.'/previous'))->toBe(realpath($currentReleaseRoot), 'previous must point at the former current release');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($finished['event'])->toBe('rollback-finished');
        expect($finished['status'])->toBe('success');
        expect($finished['release'])->toBe($explicitRelease);

        expect(trim(File::get($result['healthCheckLog'])))->toBe('--target parity-target');

        // Signalled from the explicit release — not from `previous`.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([
            ['dir' => realpath($explicitReleaseRoot), 'command' => 'artisan queue:restart'],
        ]);
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect($call)->toBe('supervisorctl status parity-queue:*');
        }
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Failure recovery
// =============================================================================

it('recovers correctly from a failed post-switch health check', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null, failHealthCheck: true);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('rollback health check failed');

        $root = $result['fixture']['root'];
        $originalCurrent = realpath($root.'/releases/'.$result['fixture']['current']);
        $originalPrevious = realpath($root.'/releases/'.$result['fixture']['old']);

        expect(realpath($root.'/current'))->toBe($originalCurrent, 'current must be restored to its original release');
        expect(realpath($root.'/previous'))->toBe($originalPrevious, 'previous must be restored to its original release');

        expect(is_dir($root.'/releases/'.$result['fixture']['old']))->toBeTrue('the target release must never be deleted, only the symlinks are touched');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2);
        $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
        $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
        expect($started['event'])->toBe('rollback-started');
        expect($finished['event'])->toBe('rollback-finished');
        expect($finished['status'])->toBe('failed-health-check');
        expect($finished['release'])->toBe($result['fixture']['old']);

        // The normal and recovery health-check calls use the identical
        // selector — two identical lines in the log.
        $healthCheckCalls = array_values(array_filter(explode("\n", trim(File::get($result['healthCheckLog'])))));
        expect($healthCheckCalls)->toBe(['--target parity-target', '--target parity-target']);

        // PHP-FPM reload happens once for the initial switch, once during
        // recovery.
        expect(substr_count(trim(file_get_contents($result['systemctlLog'])), 'reload'))->toBe(2);

        expect(file_exists($root.'/current.new'))->toBeFalse();
        expect(file_exists($root.'/current.recovery'))->toBeFalse();
        expect(file_exists($root.'/previous.new'))->toBeFalse();
        expect(file_exists($root.'/previous.recovery'))->toBeFalse();

        // The health check failed before the queue transition: the worker was
        // never signalled, and recovery only proved it still RUNNING.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect($call)->toBe('supervisorctl status parity-queue:*');
        }
        // The restored release fails its own health check too (the stub fails
        // every call), so the recovery is reported as failed, not complete.
        expect($result['output'])
            ->toContain('WARNING: rollback recovery or restored health verification failed')
            ->toContain('ERROR: rollback recovery FAILED')
            ->not->toContain('rollback recovery complete');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('leaves previous absent after a restored recovery, when it did not exist beforehand', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixtureNoPrevious($scratch);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);

        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $failingStub = rollbackOpsHealthCheckStub($scratch, $healthCheckLog, fail: true);

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --release {$fixture['target']}\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $failingStub,
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('rollback health check failed');

        expect(is_link($fixture['root'].'/previous'))->toBeFalse('previous must remain absent — it never existed before this attempt');
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['current']));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// Override gating: RATEGURU_COMMON_FILE / RATEGURU_HEALTH_CHECK_BIN
// =============================================================================

it('ignores RATEGURU_COMMON_FILE without the opt-in flag, and honors it with the opt-in flag', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$deniedExit, $deniedOutput] = rollbackOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => '/tmp/poisoned-common-'.uniqid('', true),
        ]);
        expect($deniedExit)->not->toBe(0);
        expect($deniedOutput)->toContain('/home/www/rateguru/bin/common');

        [$exit, $output] = rollbackOpsRunHarness($scratch, 'printf "COMMON_FILE=%s\n" "${COMMON_FILE}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
        ]);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('COMMON_FILE='.rollbackOpsCommonFile());
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('ignores RATEGURU_HEALTH_CHECK_BIN without the opt-in flag, and honors it with it', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        [$exit, $output] = rollbackOpsRunHarness($scratch, 'printf "HEALTH_CHECK_BIN=%s\n" "${HEALTH_CHECK_BIN}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
            // Deliberately no RATEGURU_HEALTH_CHECK_BIN — the default must
            // resolve to the hardcoded production path even though the opt-in
            // flag is on, because no override value was given.
        ]);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('HEALTH_CHECK_BIN=/home/www/rateguru/bin/health-check');

        [$grantedExit, $grantedOutput] = rollbackOpsRunHarness($scratch, 'printf "HEALTH_CHECK_BIN=%s\n" "${HEALTH_CHECK_BIN}"', [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
        ]);
        expect($grantedExit)->toBe(0, $grantedOutput);
        expect($grantedOutput)->toContain('HEALTH_CHECK_BIN=/tmp/poisoned-health-check');

        // The genuinely gate-off case: no RATEGURU_ALLOW_TEST_OVERRIDES at
        // all, real subprocess — rollback must fail at its hardcoded default
        // COMMON_FILE path, proving no override of any kind took effect.
        [$fullyDeniedExit, $fullyDeniedOutput] = rollbackOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
        ]);
        expect($fullyDeniedExit)->not->toBe(0);
        expect($fullyDeniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// The queue transition: the workers follow the restored release
//
// Laravel workers keep the code they booted with, so a rollback that only
// moved `current` would leave them running the release it rolled away from.
// rollback hands the queue over exactly as deploy does, and is not successful
// until it has.
// =============================================================================

it('reads Supervisor exactly as deploy does, and activates the worker with deploy\'s own commands', function () {
    $deploy = File::get(deployOpsScript());
    $rollback = rollbackOpsSource();

    // One reading of Supervisor state, one wait and one signal — copies that
    // drift would let a rollback and a deployment disagree about the same
    // worker.
    foreach (['queue_program_running', 'wait_for_queue_program_running', 'issue_queue_restart'] as $function) {
        expect(shellFunctionBody($rollback, $function))->toBe(shellFunctionBody($deploy, $function), "rollback's {$function} is not deploy's");
    }

    $commands = static fn (string $source): array => array_values(array_filter(
        array_map('trim', explode("\n", shellFunctionBody($source, 'activate_supervisor_worker'))),
        static fn (string $line): bool => preg_match('/supervisorctl|FAILURE_STATUS=|SUPERVISOR_ACTIVATED_NOW=|wait_for_queue_program_running|queue_program_running/', $line) === 1,
    ));
    expect($commands($rollback))->toBe($commands($deploy));

    // The program and the account come from the registry, and nowhere else.
    $resolve = shellFunctionBody($rollback, 'resolve_target');
    expect($resolve)
        ->toContain('RUNTIME_USER="$(target_runtime_user "${TARGET_ID}")"')
        ->toContain('SUPERVISOR_PROGRAM="$(target_supervisor_program "${TARGET_ID}")"');

    $code = executableSourceLines($rollback);
    expect($code)
        ->not->toContain('tits-guru')
        ->not->toContain('staging-main')
        ->not->toContain('migrate')
        ->not->toContain('supervisorctl restart')
        ->not->toContain(' all');
    expect(preg_match_all('/supervisorctl update(?! "?\$\{SUPERVISOR_PROGRAM\})/', $code))->toBe(0, 'supervisorctl update may only ever name the target program');
    expect(preg_match_all('/supervisorctl (?:status|start|stop)(?! "\$\{SUPERVISOR_PROGRAM\}:\*")/', $code))->toBe(0, 'supervisorctl may only ever address the target program group');
});

it('snapshots the worker under the deployment lock before any change, and hands the queue over only after the health check', function () {
    // Code only: the pipeline's comments name these steps too.
    $pipeline = executableSourceLines(shellFunctionBody(rollbackOpsSource(), 'perform_rollback'));
    $position = static function (string $needle) use ($pipeline): int {
        $at = mb_strpos($pipeline, $needle);
        expect($at)->not->toBeFalse("perform_rollback no longer contains: {$needle}");

        return $at;
    };

    expect($position('acquire_deployment_lock'))->toBeLessThan($position('assert_no_operation_hold'));
    expect($position('assert_no_operation_hold'))->toBeLessThan($position('queue_program_running && ORIGINAL_QUEUE_RUNNING=true'));
    expect($position('queue_program_running && ORIGINAL_QUEUE_RUNNING=true'))->toBeLessThan($position('trap handle_rollback_exit EXIT'));
    expect($position('trap handle_rollback_exit EXIT'))->toBeLessThan($position('"rollback-started"'));
    expect($position('"rollback-started"'))->toBeLessThan($position('mv -Tf'));
    expect($position('systemctl reload "${PHP_FPM_SERVICE}"'))->toBeLessThan($position('"${HEALTH_CHECK_BIN}" "${HEALTH_CHECK_ARGS[@]}"'));
    expect($position('"${HEALTH_CHECK_BIN}" "${HEALTH_CHECK_ARGS[@]}"'))->toBeLessThan($position('perform_queue_transition'));
    expect($position('perform_queue_transition'))->toBeLessThan($position('"success"'));

    // Recovery: the links (and PHP-FPM, and the restored health check) first,
    // then the queue, then the history record.
    $handler = executableSourceLines(shellFunctionBody(rollbackOpsSource(), 'handle_rollback_exit'));
    expect(mb_strpos($handler, 'restore_original_links'))->toBeLessThan(mb_strpos($handler, 'recover_queue_worker'));
    expect(mb_strpos($handler, 'recover_queue_worker'))->toBeLessThan(mb_strpos($handler, 'append_history'));
});

it('takes no look at the queue worker while another deployment operation holds the target\'s lock', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);

        $lock = fopen($fixture['root'].'/locks/deployment.lock', 'c');
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue('could not take the deployment lock');

        try {
            [$exit, $output] = rollbackOpsRunHarness(
                $scratch,
                "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
                rollbackOpsBaseEnv($scratch, [
                    'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                    'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                    'RATEGURU_TARGETS_CLI' => $targetsPath,
                    'RATEGURU_HEALTH_CHECK_BIN' => rollbackOpsHealthCheckStub($scratch, $scratch.'/hc-locked.log'),
                ]),
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        expect($exit)->not->toBe(0);
        expect($output)->toContain('another deployment operation is already running');
        expect(deployOpsSupervisorctlLog($scratch))->toBe([]);
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['current']));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('succeeds when the replacement worker is transiently STARTING after the queue restart signal', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // The snapshot reads RUNNING; then the signalled worker has exited and
        // Supervisor's replacement sits in STARTING until it survives
        // startsecs. A single immediate check would fail a healthy rollback.
        $result = rollbackOpsRunFullRollback($scratch, null, queue: [
            'sequence' => ['RUNNING', 'STARTING', 'STARTING', 'RUNNING'],
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(last(deployOpsHistory($result['fixture']['root'])))->toMatchArray(['event' => 'rollback-finished', 'status' => 'success']);
        expect(rollbackOpsArtisanCalls($scratch))->toHaveCount(1);
        expect(deployOpsSupervisorctlLog($scratch))->toBe(array_fill(0, 4, 'supervisorctl status parity-queue:*'));
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('does not wait for the old process to go: a worker still finishing its job stays RUNNING and the rollback succeeds', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // Laravel's restart is graceful. The worker stays RUNNING (the same
        // process, no turnover) while it finishes its current job.
        $result = rollbackOpsRunFullRollback($scratch, null, queue: ['sequence' => ['RUNNING']]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(rollbackOpsArtisanCalls($scratch))->toHaveCount(1);
        expect(deployOpsSupervisorctlLog($scratch))->toBe(['supervisorctl status parity-queue:*', 'supervisorctl status parity-queue:*']);
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('fails the rollback and restores the original release when the queue restart signal cannot be written', function (?string $explicit) {
    $scratch = rollbackOpsScratchDir();

    try {
        $restored = $explicit === null ? 'old' : 'explicit';
        $result = rollbackOpsRunFullRollback($scratch, $explicit, queue: ['php_fails_in' => $restored]);
        $root = $result['fixture']['root'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('artisan queue:restart failed — the running worker would keep serving '.$result['fixture']['current'])
            ->toContain('rollback recovery complete: parity-target serves '.$result['fixture']['current'].' again');

        // Both pointers back, PHP-FPM reloaded again, the restored release
        // health-checked.
        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$result['fixture']['current']));
        expect(realpath($root.'/previous'))->toBe(realpath($root.'/releases/'.$result['fixture']['old']));
        expect(substr_count(File::get($result['systemctlLog']), 'reload'))->toBe(2);
        expect(array_values(array_filter(explode("\n", trim(File::get($result['healthCheckLog']))))))->toBe(['--target parity-target', '--target parity-target']);

        // The signal never went out, so the workers still run the original
        // release: nothing to re-signal, and the worker is proved RUNNING.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([
            ['dir' => realpath($root.'/releases/'.$result['fixture'][$restored]), 'command' => 'artisan queue:restart'],
        ]);
        expect($result['output'])->not->toContain('re-issuing the queue restart signal');

        $history = deployOpsHistory($root);
        expect($history)->toHaveCount(2);
        expect($history[1])->toMatchArray(['event' => 'rollback-finished', 'status' => 'failed-queue-restart', 'release' => $result['fixture'][$restored]]);
    } finally {
        rollbackOpsCleanup($scratch);
    }
})->with([
    '--previous' => [null],
    '--release' => ['v1.0.2-20260103-000000-ccc3333'],
]);

it('re-signals the workers from the restored original release when Supervisor fails after a successful signal', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // RUNNING for the snapshot; FATAL for the whole transition wait (three
        // attempts and a final look); RUNNING again once recovery re-signals.
        $result = rollbackOpsRunFullRollback($scratch, null, queue: [
            'sequence' => ['RUNNING', 'FATAL', 'FATAL', 'FATAL', 'FATAL', 'RUNNING'],
        ]);
        $root = $result['fixture']['root'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('supervisor worker parity-queue did not reach RUNNING within the wait budget after the queue restart signal')
            ->toContain('re-issuing the queue restart signal from the restored release')
            ->toContain('rollback recovery complete: parity-target serves '.$result['fixture']['current'].' again');

        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$result['fixture']['current']));

        // Forward from the release rolled back to, then back from the
        // original once `current` points at it again.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([
            ['dir' => realpath($root.'/releases/'.$result['fixture']['old']), 'command' => 'artisan queue:restart'],
            ['dir' => realpath($root.'/releases/'.$result['fixture']['current']), 'command' => 'artisan queue:restart'],
        ]);
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect($call)->toBe('supervisorctl status parity-queue:*', 'recovery never force-starts or restarts the worker');
        }

        expect(last(deployOpsHistory($root)))->toMatchArray(['event' => 'rollback-finished', 'status' => 'failed-queue-restart']);
        expect(json_encode(deployOpsHistory($root)))->not->toContain('"success"');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('says so plainly, and still records the failed rollback, when recovery cannot put the queue back', function (array $queue, string $reason) {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null, queue: $queue);
        $root = $result['fixture']['root'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain($reason)
            ->toContain('ERROR: rollback recovery FAILED — parity-target is not provably back on '.$result['fixture']['current'].' with its queue as it was')
            ->not->toContain('rollback recovery complete');

        // The links themselves were restored; the history keeps the
        // rollback's own failure, as it always has.
        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$result['fixture']['current']));
        expect(last(deployOpsHistory($root)))->toMatchArray(['event' => 'rollback-finished', 'status' => 'failed-queue-restart']);
    } finally {
        rollbackOpsCleanup($scratch);
    }
})->with([
    'the worker never comes back' => [
        ['sequence' => ['RUNNING', 'FATAL']],
        'WARNING: supervisor worker parity-queue was RUNNING before this rollback but is not RUNNING after recovery',
    ],
    'the recovery signal cannot be written' => [
        ['sequence' => ['RUNNING', 'FATAL', 'FATAL', 'FATAL', 'FATAL', 'RUNNING'], 'php_fails_in' => 'current'],
        'WARNING: unable to re-issue the queue restart signal from the restored release',
    ],
]);

it('activates a worker that was not running with deploy\'s activation, and sends it no restart signal', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null, queue: ['running' => false]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('activating supervisor worker parity-queue (it was not RUNNING before this rollback)')
            ->toContain('worker parity-queue was freshly started against the restored release — no queue:restart signal needed');

        // Started against the restored `current`: a restart would only ask a
        // brand-new worker to exit for nothing.
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);

        $calls = deployOpsSupervisorctlLog($scratch);
        expect($calls)->toContain('supervisorctl reread')->toContain('supervisorctl update parity-queue');
        expect(array_search('supervisorctl reread', $calls, true))->toBeLessThan(array_search('supervisorctl update parity-queue', $calls, true));
        foreach ($calls as $call) {
            expect(in_array($call, ['supervisorctl reread', 'supervisorctl update parity-queue', 'supervisorctl start parity-queue:*', 'supervisorctl status parity-queue:*'], true))
                ->toBeTrue("unexpected supervisorctl invocation: {$call}");
        }

        expect(last(deployOpsHistory($result['fixture']['root'])))->toMatchArray(['event' => 'rollback-finished', 'status' => 'success']);
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('waits out STARTING after it activates a worker that was not running', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // STOPPED for the snapshot and for the look after update, so the
        // group is started; then STARTING until it survives startsecs.
        $result = rollbackOpsRunFullRollback($scratch, null, queue: [
            'running' => false,
            'sequence' => ['STOPPED', 'STOPPED', 'STARTING', 'RUNNING'],
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(deployOpsSupervisorctlLog($scratch))->toContain('supervisorctl start parity-queue:*');
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('stops the worker it activated again when the rollback fails, and says so when it cannot', function (array $toggles, bool $recovered) {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null, queue: ['running' => false, 'toggles' => $toggles]);
        $root = $result['fixture']['root'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('supervisor worker parity-queue did not reach RUNNING')
            ->toContain('rollback failed after supervisor activation; stopping parity-queue (it was not RUNNING before this rollback)');
        expect(deployOpsSupervisorctlLog($scratch))->toContain('supervisorctl stop parity-queue:*');

        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$result['fixture']['current']));
        expect(last(deployOpsHistory($root)))->toMatchArray(['event' => 'rollback-finished', 'status' => 'failed-supervisor-activation']);
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);

        if ($recovered) {
            expect($result['output'])->toContain('rollback recovery complete');
        } else {
            expect($result['output'])
                ->toContain('WARNING: unable to stop supervisor worker parity-queue during recovery')
                ->toContain('ERROR: rollback recovery FAILED');
        }
    } finally {
        rollbackOpsCleanup($scratch);
    }
})->with([
    'stopped again' => [['activation-fail'], true],
    'cannot be stopped' => [['activation-fail', 'stop-fail'], false],
]);

it('leaves a stopped worker stopped when the rollback fails before its queue transition', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $result = rollbackOpsRunFullRollback($scratch, null, failHealthCheck: true, queue: ['running' => false]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('rollback health check failed');
        expect(deployOpsSupervisorctlLog($scratch))->toBe(['supervisorctl status parity-queue:*']);
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);
        expect(is_file($scratch.'/supervisor-state/queue-running'))->toBeFalse();
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('touches no other target: not its release pointers, its history, its Supervisor program or its account', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $other = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);

        // A second active target on the same machine, with its own worker.
        file_put_contents($targetsPath, str_replace('ACTIVE_ALLOWLIST=(parity-target)', 'ACTIVE_ALLOWLIST=(parity-target other-target)', File::get($targetsPath)));
        $registry = json_decode(File::get($registryPath), true, 512, JSON_THROW_ON_ERROR);
        $registry['targets']['other-target'] = [
            'id' => 'other-target',
            'lifecycle' => 'active',
            'environment_class' => 'staging',
            'application_root' => $other['root'],
            'runtime_user' => 'other-runtime',
            'runtime_group' => 'other-runtime',
            'deploy_user' => 'other-deploy',
            'code_group' => 'other-code',
            'incoming_artifacts' => $scratch.'/incoming-other',
            'release_retention' => 5,
            'database' => ['name' => 'other_db', 'application_role' => 'other_app'],
            'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'other.internal'],
            'public_hostnames' => ['other.example'],
            'backup' => ['namespace' => 'other', 'local_retention_days' => 1, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
            'php_fpm' => ['pool' => 'other', 'socket' => '/run/php/other.sock'],
            'supervisor' => ['program' => 'other-queue', 'queue' => 'other'],
            'scheduler' => ['name' => 'other-scheduler'],
            'nginx' => ['site_name' => 'other', 'internal_hostname' => 'other.internal'],
            'environment_template' => 'infrastructure/templates/environment/production.env.example',
        ];
        file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));
        exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
        expect($validateExit)->toBe(0, implode("\n", $validateOutput));

        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);

        $otherCurrent = readlink($other['root'].'/current');
        $otherPrevious = readlink($other['root'].'/previous');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => rollbackOpsHealthCheckStub($scratch, $scratch.'/hc-two-targets.log'),
                'RATEGURU_ROLLBACK_QUEUE_WAIT_ATTEMPTS' => '3',
                'RATEGURU_ROLLBACK_QUEUE_RETRY_DELAY' => '0',
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($fixture['root'].'/releases/'.$fixture['old']));

        expect(readlink($other['root'].'/current'))->toBe($otherCurrent);
        expect(readlink($other['root'].'/previous'))->toBe($otherPrevious);
        expect(deployOpsHistory($other['root']))->toBe([]);

        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect($call)->toBe('supervisorctl status parity-queue:*');
        }
        expect(File::get($scratch.'/runuser.log'))->not->toContain('other-runtime');
        foreach (rollbackOpsArtisanCalls($scratch) as $call) {
            expect($call['dir'])->toStartWith(realpath($fixture['root']));
        }
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('ignores the queue wait overrides without the opt-in flag, and honors them with it', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $body = 'printf "WAIT=%s DELAY=%s\n" "${QUEUE_WAIT_ATTEMPTS}" "${QUEUE_RETRY_DELAY_SECONDS}"';
        $base = [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => rollbackOpsCommonFile(),
            'RATEGURU_DEPLOYMENT_CONF_FILE' => rollbackOpsDeploymentConfPath(),
            'RATEGURU_ROLLBACK_QUEUE_WAIT_ATTEMPTS' => '1',
            'RATEGURU_ROLLBACK_QUEUE_RETRY_DELAY' => '0',
        ];

        // With the opt-in flag but no values: deploy's production budget.
        [$exit, $output] = rollbackOpsRunHarness($scratch, $body, array_diff_key(
            [...$base, 'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true'],
            ['RATEGURU_ROLLBACK_QUEUE_WAIT_ATTEMPTS' => true, 'RATEGURU_ROLLBACK_QUEUE_RETRY_DELAY' => true],
        ));
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WAIT=10 DELAY=1');

        [$exit, $output] = rollbackOpsRunHarness($scratch, $body, [...$base, 'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true']);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WAIT=1 DELAY=0');

        // Without the flag, a real subprocess cannot even reach a test common
        // file, let alone shrink the wait.
        [$deniedExit, $deniedOutput] = rollbackOpsRunScript(['--help'], $base);
        expect($deniedExit)->not->toBe(0);
        expect($deniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

// =============================================================================
// the controlled code alignment: the restore interlock
// =============================================================================
//
// A target held after a restore has live data that may belong to a different
// commit than `current` serves. A rollback there would move `current` to a
// THIRD commit that matches neither — and, worse, would health-check the
// target and bring it back up, which is exactly what the hold exists to
// prevent. Only the controlled alignment deploy and `restore-target --resume`
// may end a hold.

function rollbackOpsRunRoot(string $scratch): string
{
    return $scratch.'/run';
}

function rollbackOpsGuardPath(string $scratch): string
{
    return rollbackOpsRunRoot($scratch).'/restores/parity-target/restore-guard';
}

function rollbackOpsWriteRestoreGuard(string $scratch, array $overrides = []): void
{
    $path = rollbackOpsGuardPath($scratch);

    if (! is_dir(dirname($path))) {
        expect(@mkdir(dirname($path), 0o700, true))->toBeTrue('could not create the restore run root');
    }

    file_put_contents($path, json_encode(array_merge([
        'operation' => '20260901-101112-a1b2c3',
        'target' => 'parity-target',
        'required_source_sha' => 'a81d7f2c3b4a5968778899aabbccddeeff001122',
        'status' => 'held',
        'created_at' => '2026-09-01T10:11:12Z',
    ], $overrides), JSON_PRETTY_PRINT));
    chmod($path, 0o600);
}

it('rolls back normally when no target is held', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        // An existing but empty restore run root — the ordinary state of a host
        // that has never had a restore held. Nothing about rollback changes.
        expect(@mkdir(rollbackOpsRunRoot($scratch), 0o700, true))->toBeTrue();

        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);

        $healthCheckLog = $scratch.'/health-check-clear.log';
        touch($healthCheckLog);

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => rollbackOpsHealthCheckStub($scratch, $healthCheckLog),
                'RATEGURU_RUN_ROOT' => rollbackOpsRunRoot($scratch),
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect(File::get($healthCheckLog))->toContain('--target parity-target');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('refuses a rollback while the target is held, before any mutation', function () {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);
        rollbackOpsWriteRestoreGuard($scratch);

        $originalCurrent = realpath($fixture['root'].'/current');
        $originalPrevious = realpath($fixture['root'].'/previous');

        $healthCheckLog = $scratch.'/health-check-held.log';
        touch($healthCheckLog);

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => rollbackOpsHealthCheckStub($scratch, $healthCheckLog),
                'RATEGURU_RUN_ROOT' => rollbackOpsRunRoot($scratch),
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('is held after restore operation 20260901-101112-a1b2c3')
            ->toContain('a rollback is refused')
            ->toContain('Controlled code alignment is required');

        // Before any mutation: both pointers untouched, nothing health
        // checked, nothing recorded, and the guard left for the operator.
        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);
        expect(realpath($fixture['root'].'/previous'))->toBe($originalPrevious);
        expect(File::get($healthCheckLog))->toBe('');
        expect(trim((string) @file_get_contents($scratch.'/systemctl.log')))->toBe('');
        expect(deployOpsSupervisorctlLog($scratch))->toBe([], 'a held target is refused before its queue worker is even looked at');
        expect(rollbackOpsArtisanCalls($scratch))->toBe([]);
        expect(is_file(rollbackOpsGuardPath($scratch)))->toBeTrue();

        $historyPath = $fixture['root'].'/deployments/history.jsonl';
        expect(trim((string) @file_get_contents($historyPath)))->not->toContain('rollback');
    } finally {
        rollbackOpsCleanup($scratch);
    }
});

it('refuses a rollback under every hold status, not only a completed one', function (string $status) {
    $scratch = rollbackOpsScratchDir();

    try {
        $fixture = rollbackOpsBuildFixture($scratch);
        $confPath = rollbackOpsDeploymentConfForFixture($scratch, $fixture);
        [$registryPath, $targetsPath] = rollbackOpsParityRegistry($scratch, $fixture);
        rollbackOpsInstallQueue($scratch, $confPath);
        rollbackOpsInstallSystemctlStub($scratch);
        rollbackOpsWriteRestoreGuard($scratch, ['status' => $status]);

        $originalCurrent = realpath($fixture['root'].'/current');

        [$exit, $output] = rollbackOpsRunHarness(
            $scratch,
            "parse_rollback_args --target parity-target --previous\nresolve_target\nperform_rollback",
            rollbackOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => rollbackOpsHealthCheckStub($scratch, $scratch.'/hc-'.$status.'.log'),
                'RATEGURU_RUN_ROOT' => rollbackOpsRunRoot($scratch),
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain("(status {$status})");
        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);
        expect(deployOpsSupervisorctlLog($scratch))->toBe([]);
    } finally {
        rollbackOpsCleanup($scratch);
    }
})->with(['in-progress', 'held', 'failed-held']);
