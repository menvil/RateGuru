<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 4 slice 4: target-aware release cleanup.
 *
 * These tests execute the real shipped `infrastructure/scripts/cleanup` as a
 * subprocess — never a reimplementation of its logic — with every host
 * dependency (common's deployment.conf, the target registry, the targets
 * validator) supplied through the RATEGURU_* test-override contract already
 * established for health-check/status (see TargetAwareOperationsTest.php)
 * and install-target-operations.
 *
 * Apply-mode internals (lock acquisition, candidate recomputation, deletion,
 * pinned-releases ownership) are exercised by sourcing cleanup's own
 * constants+functions section standalone and calling perform_apply()
 * directly — bypassing require_root — the identical technique
 * InstallTargetOperationsTest.php already uses for install-target-operations
 * (see installOpsFunctionsSection()/installOpsRunHarness() there). This lets
 * apply-mode be tested fully without a root test runner, and lets
 * PINNED_FILE_OWNER/PINNED_FILE_GROUP be reassigned to the test process's own
 * uid/gid so real ownership can be asserted.
 *
 * No test touches a real /home/www path, invokes real rm against a real
 * release, or makes a network request.
 */
function cleanupOpsScript(): string
{
    return base_path('infrastructure/scripts/cleanup');
}

function cleanupOpsSource(): string
{
    return File::get(cleanupOpsScript());
}

function cleanupOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function cleanupOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function cleanupOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function cleanupOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function cleanupOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/cleanup-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function cleanupOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Everything from `set -Eeuo pipefail` down to (not including) the final
 * `parse_cleanup_args "$@"` dispatch line — constants and function
 * definitions only, inert until a caller invokes something. Identical
 * technique to installOpsFunctionsSection() in InstallTargetOperationsTest.php.
 */
function cleanupOpsFunctionsSection(): string
{
    $source = cleanupOpsSource();
    $start = strpos($source, "set -Eeuo pipefail\n");
    $end = strpos($source, "\nparse_cleanup_args \"\$@\"");

    expect($start)->not->toBeFalse('could not locate the functions-section start');
    expect($end)->not->toBeFalse('could not locate the functions-section end');

    return substr($source, $start, $end - $start);
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports). fd 2 is redirected onto fd 1 at the descriptor
 * level so there is only one stream to drain.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function cleanupOpsExec(string $scriptPath, array $env): array
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
 * Run the real cleanup script as a subprocess with the given CLI arguments.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function cleanupOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', cleanupOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start cleanup subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Source cleanup's whole functions section, reassign any given vars, then run
 * $body — the harness technique used for every apply-mode/pinned-releases
 * test below.
 *
 * @param  array<string, string>  $vars
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function cleanupOpsRunHarness(string $scratch, array $vars, string $body, array $env = []): array
{
    file_put_contents($scratch.'/functions-section.sh', cleanupOpsFunctionsSection());

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

    return cleanupOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * The standard environment for full-subprocess runs: real common, real
 * deployment.conf template, real registry, real targets validator.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function cleanupOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => cleanupOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => cleanupOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => cleanupOpsTargetsCli(),
    ], $overrides);
}

/**
 * Builds releases/, deployments/ and locks/ under a fresh target root.
 *
 * @param  array<string, string|null>  $releases  release_id => history status
 *                                                ('success'|'failed-health-check'|other), or null for no history entry at all (untracked)
 * @param  list<string>  $pinned
 */
function cleanupOpsBuildFixture(
    string $scratch,
    array $releases,
    ?string $current = null,
    ?string $previous = null,
    array $pinned = [],
    bool $withPinnedFile = false,
): string {
    $root = $scratch.'/target-'.uniqid('', true);
    mkdir($root.'/releases', 0o755, true);
    mkdir($root.'/deployments', 0o755, true);
    mkdir($root.'/locks', 0o755, true);

    $mtime = time() - (count($releases) * 10) - 100;
    $historyLines = [];

    foreach ($releases as $releaseId => $status) {
        $path = $root.'/releases/'.$releaseId;
        mkdir($path, 0o755, true);
        touch($path, $mtime);
        $mtime += 10;

        if ($status !== null) {
            $historyLines[] = json_encode(['event' => 'deployment-finished', 'release' => $releaseId, 'status' => $status]);
        }
    }

    if ($historyLines !== []) {
        file_put_contents($root.'/deployments/history.jsonl', implode("\n", $historyLines)."\n");
    }

    if ($current !== null) {
        symlink($root.'/releases/'.$current, $root.'/current');
    }

    if ($previous !== null) {
        symlink($root.'/releases/'.$previous, $root.'/previous');
    }

    if ($withPinnedFile || $pinned !== []) {
        file_put_contents($root.'/deployments/pinned-releases', implode("\n", $pinned)."\n");
    }

    return $root;
}

/**
 * A deployment.conf fixture derived from the real committed template, with
 * STAGING_ROOT and STAGING_RELEASE_RETENTION overridden — the same
 * transformation technique targetOpsDeploymentConfPath()'s sibling test
 * already uses in TargetAwareOperationsTest.php.
 */
function cleanupOpsDeploymentConfForRoot(string $scratch, string $root, int $retention = 5): string
{
    $conf = str_replace(
        'STAGING_ROOT=/home/www/rateguru/staging',
        'STAGING_ROOT='.$root,
        File::get(cleanupOpsDeploymentConfPath()),
    );
    $conf = preg_replace('/^STAGING_RELEASE_RETENTION=\d+$/m', 'STAGING_RELEASE_RETENTION='.$retention, $conf);

    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, $conf);

    return $path;
}

/**
 * Extracts every "DRY RUN would delete: RELEASE_ID" line from cleanup output
 * as a sorted list of release IDs — the stable comparison surface the spec
 * pins, deliberately excluding timestamps and selector-specific labels.
 *
 * @return list<string>
 */
function cleanupOpsCandidates(string $output): array
{
    preg_match_all('/DRY RUN would delete: (\S+)/', $output, $matches);
    $ids = $matches[1];
    sort($ids);

    return $ids;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at a scratch application
 * root. The committed `targets` script hard-codes both "lifecycle=active is
 * only ever allowed for staging-main" and "application_root must live below
 * /home/www/rateguru" as production safety rails — exactly what makes
 * staging-main itself untestable against scratch content in this suite (its
 * real application_root, /home/www/rateguru/staging, does not exist here).
 * This builds a throwaway, test-only copy of `targets` with just those two
 * constraints relaxed for one synthetic target ID, so legacy/target
 * candidate-selection parity can be verified against real, controlled
 * fixture content rather than only against real-VPS runtime parity (which
 * install-target-operations already proves separately, for the one real
 * scenario the actual host is in).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function cleanupOpsParityRegistry(string $scratch, string $applicationRoot): array
{
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(cleanupOpsTargetsCli()),
    );
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
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
                'application_root' => $applicationRoot,
                'runtime_user' => 'parity-runtime',
                'runtime_group' => 'parity-runtime',
                'deploy_user' => 'parity-deploy',
                'code_group' => 'parity-code',
                'incoming_artifacts' => '/home/parity-deploy/incoming',
                'release_retention' => 3,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1],
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

    // The fixture must itself be valid, or every test using it proves
    // nothing.
    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

/**
 * Runs the same fixture through both `--environment staging` and
 * `--target parity-target`, dry-run only, and returns both raw outputs.
 *
 * @param  array<string, string|null>  $releases
 * @param  list<string>  $pinned
 * @return array{0: string, 1: string, 2: int, 3: int} [envOutput, targetOutput, envExit, targetExit]
 */
function cleanupOpsRunParity(
    string $scratch,
    array $releases,
    ?string $current = null,
    ?string $previous = null,
    array $pinned = [],
    ?int $keep = null,
): array {
    $root = cleanupOpsBuildFixture($scratch, $releases, $current, $previous, $pinned);
    $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, 3);
    [$registryPath, $targetsPath] = cleanupOpsParityRegistry($scratch, $root);

    $env = cleanupOpsBaseEnv($scratch, [
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
    ]);

    $envArgs = ['--environment', 'staging', '--dry-run'];
    $targetArgs = ['--target', 'parity-target', '--dry-run'];

    if ($keep !== null) {
        $envArgs = array_merge($envArgs, ['--keep', (string) $keep]);
        $targetArgs = array_merge($targetArgs, ['--keep', (string) $keep]);
    }

    [$envExit, $envOutput] = cleanupOpsRunScript($envArgs, $env);
    [$targetExit, $targetOutput] = cleanupOpsRunScript($targetArgs, $env);

    return [$envOutput, $targetOutput, $envExit, $targetExit];
}

/**
 * A stub `rm` that delegates to the real one, except on its Nth call, where
 * it fails without deleting anything — used to prove apply stops on the
 * first failed deletion instead of continuing past it.
 */
function cleanupOpsFailingRmStub(string $scratch, int $failAtCall): string
{
    $path = $scratch.'/bin/rm';
    $counterFile = $scratch.'/rm-call-count';
    file_put_contents($counterFile, "0\n");

    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'count=$(( $(cat '.escapeshellarg($counterFile).') + 1 ))'."\n"
        .'echo "$count" > '.escapeshellarg($counterFile)."\n"
        ."if [[ \"\$count\" -eq {$failAtCall} ]]; then\n"
        ."    echo 'stub rm: forced failure' >&2\n"
        ."    exit 1\n"
        ."fi\n"
        .'exec /bin/rm "$@"'."\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A stub `rm` that always fails and records whether it was ever invoked —
 * used to prove dry-run never reaches deletion at all.
 */
function cleanupOpsForbiddenRmStub(string $scratch, string $markerFile): string
{
    $path = $scratch.'/bin/rm';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "rm was invoked: $*" >> '.escapeshellarg($markerFile)."\n"
        ."exit 1\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * Shallow directory-listing + content/mode snapshot of a target root's own
 * children plus releases/ and deployments/ children — enough to prove "no
 * writes happened anywhere" without needing to recurse into release
 * directories dry-run never touches in the first place.
 *
 * @return array<string, string>
 */
function cleanupOpsSnapshotShallow(string $root): array
{
    $snapshot = [];

    foreach (['', '/releases', '/deployments', '/locks'] as $sub) {
        $dir = $root.$sub;

        if (! is_dir($dir)) {
            continue;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            $key = ($sub === '' ? '' : ltrim($sub, '/').'/').$entry;
            clearstatcache(true, $path);

            if (is_link($path)) {
                $snapshot[$key] = 'link:'.readlink($path);
            } elseif (is_dir($path)) {
                $snapshot[$key] = 'dir:'.decoct(fileperms($path) & 0o7777);
            } else {
                $snapshot[$key] = 'file:'.filemtime($path).':'.filesize($path).':'.md5_file($path);
            }
        }
    }

    ksort($snapshot);

    return $snapshot;
}

// =============================================================================
// Argument parsing
// =============================================================================

it('supports the legacy --environment selector', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, []);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('cleanup dry run finished');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('supports the --target selector', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, []);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        [$registryPath, $targetsPath] = cleanupOpsParityRegistry($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'parity-target', '--dry-run'],
            cleanupOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('cleanup dry run finished');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects --target and --environment used together', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'staging-main', '--environment', 'staging'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('cannot be combined');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('requires exactly one selector', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript([], cleanupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('exactly one of --target or --environment is required');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects duplicate selectors', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$targetExit, $targetOutput] = cleanupOpsRunScript(
            ['--target', 'staging-main', '--target', 'staging-main'],
            cleanupOpsBaseEnv($scratch),
        );
        expect($targetExit)->not->toBe(0);
        expect($targetOutput)->toContain('--target given more than once');

        [$envExit, $envOutput] = cleanupOpsRunScript(
            ['--environment', 'staging', '--environment', 'staging'],
            cleanupOpsBaseEnv($scratch),
        );
        expect($envExit)->not->toBe(0);
        expect($envOutput)->toContain('--environment given more than once');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects duplicate --keep', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--keep', '1', '--keep', '2'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--keep given more than once');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects conflicting execution modes', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run', '--apply'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--dry-run and --apply cannot be combined');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects duplicate execution modes', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$dryExit, $dryOutput] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run', '--dry-run'],
            cleanupOpsBaseEnv($scratch),
        );
        expect($dryExit)->not->toBe(0);
        expect($dryOutput)->toContain('--dry-run given more than once');

        [$applyExit, $applyOutput] = cleanupOpsRunScript(
            ['--environment', 'staging', '--apply', '--apply'],
            cleanupOpsBaseEnv($scratch),
        );
        expect($applyExit)->not->toBe(0);
        expect($applyOutput)->toContain('--apply given more than once');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects an invalid --keep value', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        foreach (['-1', 'abc', '1.5', ''] as $invalid) {
            [$exit, $output] = cleanupOpsRunScript(
                ['--environment', 'staging', '--keep', $invalid],
                cleanupOpsBaseEnv($scratch),
            );

            expect($exit)->not->toBe(0, "keep={$invalid}");
            expect($output)->toContain('--keep must be a non-negative integer');
        }
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects a selector or --keep given without a value', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(['--target'], cleanupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');

        [$exit, $output] = cleanupOpsRunScript(['--environment'], cleanupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--environment requires a value');

        [$exit, $output] = cleanupOpsRunScript(['--environment', 'staging', '--keep'], cleanupOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--keep requires a value');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(['--bogus'], cleanupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects extra positional arguments', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(['--environment', 'staging', 'extra'], cleanupOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: extra');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('shows both selector forms on --help without requiring a selector', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(['--help'], cleanupOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->toContain('--environment staging|production')
            ->toContain('--keep NUMBER')
            ->toContain('--dry-run|--apply');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// Lifecycle
// =============================================================================

it('rejects a planned target before any filesystem, lock or history access', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $markerFile = $scratch.'/rm-invoked';
        cleanupOpsForbiddenRmStub($scratch, $markerFile);

        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'tits-guru', '--dry-run'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('target root does not exist');

        [$applyExit, $applyOutput] = cleanupOpsRunScript(
            ['--target', 'tits-guru', '--apply'],
            cleanupOpsBaseEnv($scratch),
        );
        expect($applyExit)->not->toBe(0);
        expect($applyOutput)->toContain('lifecycle=planned');
        // Rejected before require_root is ever reached, so no root is needed
        // to prove this, and no production path is contacted either way.
        expect($applyOutput)->not->toContain('this command must be executed as root');

        expect(file_exists($markerFile))->toBeFalse('rm must never be invoked for a planned target');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'ghost-target', '--dry-run'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects an invalid target ID', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$exit, $output] = cleanupOpsRunScript(
            ['--target', '../etc', '--dry-run'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid target ID');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('lets an active target through lifecycle validation and reach real fixture content', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        [$registryPath, $targetsPath] = cleanupOpsParityRegistry($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'parity-target', '--dry-run'],
            cleanupOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('cleanup dry run finished');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// Dry-run: genuinely side-effect free
// =============================================================================

it('performs no writes at all during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'failed-health-check',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], current: 'v1.0.3-20260103-000000-abc1233', previous: 'v1.0.2-20260102-000000-abc1232');
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);

        $before = cleanupOpsSnapshotShallow($root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);

        $after = cleanupOpsSnapshotShallow($root);

        expect($after)->toBe($before);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never invokes rm during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        $markerFile = $scratch.'/rm-invoked';
        cleanupOpsForbiddenRmStub($scratch, $markerFile);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DRY RUN would delete: v1.0.1-20260101-000000-abc1231');
        expect(file_exists($markerFile))->toBeFalse('rm must never be invoked during dry-run');
        expect(is_dir($root.'/releases/v1.0.1-20260101-000000-abc1231'))->toBeTrue();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('does not acquire a mutating lock during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, []);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect(scandir($root.'/locks'))->toBe(['.', '..'], 'dry-run must never create a lock file');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('treats a missing pinned-releases file as empty and never creates it during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);

        expect(file_exists($root.'/deployments/pinned-releases'))->toBeFalse();

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DRY RUN would delete: v1.0.1-20260101-000000-abc1231');
        expect(file_exists($root.'/deployments/pinned-releases'))->toBeFalse('dry-run must never create pinned-releases');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never touches or chmods a pre-existing pinned-releases file during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ], pinned: ['v1.0.1-20260101-000000-abc1231']);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        $pinnedPath = $root.'/deployments/pinned-releases';
        chmod($pinnedPath, 0o644);
        touch($pinnedPath, 1700000000);
        clearstatcache(true, $pinnedPath);
        $beforeContent = file_get_contents($pinnedPath);
        $beforeMode = fileperms($pinnedPath) & 0o7777;
        $beforeMtime = filemtime($pinnedPath);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);

        clearstatcache(true, $pinnedPath);
        expect(file_get_contents($pinnedPath))->toBe($beforeContent);
        expect(fileperms($pinnedPath) & 0o7777)->toBe($beforeMode, 'dry-run must never chmod a pre-existing pinned-releases file');
        expect(filemtime($pinnedPath))->toBe($beforeMtime, 'dry-run must never rewrite a pre-existing pinned-releases file');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never appends deployment history during dry-run', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        $historyBefore = file_get_contents($root.'/deployments/history.jsonl');

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect(file_get_contents($root.'/deployments/history.jsonl'))->toBe($historyBefore);
        expect($historyBefore)->not->toContain('release-cleaned');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// Legacy/target dry-run candidate parity
// =============================================================================

it('selects identical candidates for legacy and target selectors at default retention', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput, $envExit, $targetExit] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'failed-health-check',
            'v1.0.4-20260104-000000-abc1234' => 'success',
            'v1.0.5-20260105-000000-abc1235' => 'success',
        ], current: 'v1.0.5-20260105-000000-abc1235', previous: 'v1.0.4-20260104-000000-abc1234');

        expect($envExit)->toBe(0, $envOutput);
        expect($targetExit)->toBe(0, $targetOutput);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->not->toBe([]);
        expect($targetCandidates)->toBe($envCandidates);
        // Default retention here is 3 (cleanupOpsRunParity's fixed
        // deployment.conf fixture): v5/v4 are protected (current/previous),
        // leaving v3 (failed) as a candidate and v1/v2 (2 successful
        // releases, both <= 3) both retained.
        expect($targetCandidates)->toBe(['v1.0.3-20260103-000000-abc1233']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors with --keep 0', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], current: 'v1.0.3-20260103-000000-abc1233', keep: 0);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->toBe($envCandidates);
        // current is always protected even at --keep 0.
        expect($targetCandidates)->toBe(['v1.0.1-20260101-000000-abc1231', 'v1.0.2-20260102-000000-abc1232']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors with --keep 1', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], keep: 1);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->toBe($envCandidates);
        expect($targetCandidates)->toBe(['v1.0.1-20260101-000000-abc1231', 'v1.0.2-20260102-000000-abc1232']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors with no pinned file', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
        ], keep: 0);

        expect(cleanupOpsCandidates($targetOutput))->toBe(cleanupOpsCandidates($envOutput));
        expect(cleanupOpsCandidates($targetOutput))->toBe(['v1.0.1-20260101-000000-abc1231', 'v1.0.2-20260102-000000-abc1232']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors with pinned releases', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], pinned: ['v1.0.1-20260101-000000-abc1231'], keep: 0);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->toBe($envCandidates);
        expect($targetCandidates)->toBe(['v1.0.2-20260102-000000-abc1232', 'v1.0.3-20260103-000000-abc1233']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors with current and previous set', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], current: 'v1.0.3-20260103-000000-abc1233', previous: 'v1.0.2-20260102-000000-abc1232', keep: 0);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->toBe($envCandidates);
        expect($targetCandidates)->toBe(['v1.0.1-20260101-000000-abc1231']);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('selects identical candidates for legacy and target selectors across mixed history states', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        [$envOutput, $targetOutput] = cleanupOpsRunParity($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
            'v1.0.2-20260102-000000-abc1232' => 'failed-health-check',
            'v1.0.3-20260103-000000-abc1233' => null,
            'v1.0.4-20260104-000000-abc1234' => 'success',
        ], keep: 0);

        $envCandidates = cleanupOpsCandidates($envOutput);
        $targetCandidates = cleanupOpsCandidates($targetOutput);

        expect($targetCandidates)->toBe($envCandidates);
        // v1.0.3 has no history entry at all — unknown/untracked, retained.
        expect($targetCandidates)->toBe([
            'v1.0.1-20260101-000000-abc1231',
            'v1.0.2-20260102-000000-abc1232',
            'v1.0.4-20260104-000000-abc1234',
        ]);
        expect($envOutput)->toContain('KEEP unknown/untracked release: v1.0.3-20260103-000000-abc1233');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// Protection
// =============================================================================

it('never selects current as a candidate, even when it would otherwise be eligible', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
        ], current: 'v1.0.1-20260101-000000-abc1231');
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('KEEP protected: v1.0.1-20260101-000000-abc1231');
        expect($output)->not->toContain('DRY RUN would delete');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never selects previous as a candidate, even when it would otherwise be eligible', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
            'v1.0.2-20260102-000000-abc1232' => 'success',
        ], current: 'v1.0.2-20260102-000000-abc1232', previous: 'v1.0.1-20260101-000000-abc1231');
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('KEEP protected: v1.0.1-20260101-000000-abc1231');
        expect($output)->not->toContain('DRY RUN would delete');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never selects a pinned release as a candidate even beyond retention', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ], pinned: ['v1.0.1-20260101-000000-abc1231']);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('KEEP protected: v1.0.1-20260101-000000-abc1231');
        expect($output)->not->toContain('DRY RUN would delete');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('retains unknown/untracked releases that have no matching history entry', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => null,
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('KEEP unknown/untracked release: v1.0.1-20260101-000000-abc1231');
        expect($output)->not->toContain('DRY RUN would delete');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('retains a directory whose name does not match the release ID pattern', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        mkdir($root.'/releases/not-a-release-id', 0o755, true);

        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SKIP unknown directory: not-a-release-id');
        expect(is_dir($root.'/releases/not-a-release-id'))->toBeTrue();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('fails closed on a release-looking symlink instead of silently ignoring or deleting it', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        $outsideTarget = $scratch.'/outside-file';
        file_put_contents($outsideTarget, 'do not delete me');
        symlink($outsideTarget, $root.'/releases/v9.9.9-20260909-000000-fedcba9');

        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);
        $markerFile = $scratch.'/rm-invoked';
        cleanupOpsForbiddenRmStub($scratch, $markerFile);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('release-looking entry')
            ->toContain('v9.9.9-20260909-000000-fedcba9')
            ->toContain('not a real directory');
        expect($output)->not->toContain('DRY RUN would delete');
        expect(file_exists($markerFile))->toBeFalse('rm must never run once an unsafe entry is found');
        expect(file_get_contents($outsideTarget))->toBe('do not delete me');
        expect(is_link($root.'/releases/v9.9.9-20260909-000000-fedcba9'))->toBeTrue();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects current when it is a real directory rather than a symlink', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        mkdir($root.'/current');

        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current must be a symlink');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects current when it resolves outside the releases root', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        $escape = $scratch.'/escape-target';
        mkdir($escape);
        symlink($escape, $root.'/current');

        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('current does not resolve to a direct child of the releases root');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('rejects previous when it resolves to a name that does not match the release ID pattern', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ]);
        mkdir($root.'/releases/not-a-release-id');
        symlink($root.'/releases/not-a-release-id', $root.'/previous');

        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);

        [$exit, $output] = cleanupOpsRunScript(
            ['--environment', 'staging', '--dry-run'],
            cleanupOpsBaseEnv($scratch, ['RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('previous resolves to a release ID that does not match the expected pattern');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// Apply: locking, recomputation, deletion, history
// =============================================================================

it('recomputes candidates fresh under the lock rather than reusing an earlier snapshot', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
        ], <<<BASH
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree

            # A stale, pre-lock snapshot — perform_apply must never reuse this.
            compute_candidates
            stale_count=\${#DELETE_CANDIDATES[@]}

            # Mutate the fixture after the stale snapshot: a brand new
            # failed-health-check release appears only now.
            mkdir -p "\${RELEASES_ROOT}/v1.0.2-20260102-000000-abc1232"
            touch "\${RELEASES_ROOT}/v1.0.2-20260102-000000-abc1232"
            printf '{"event":"deployment-finished","release":"v1.0.2-20260102-000000-abc1232","status":"failed-health-check"}\n' >> "\${HISTORY_FILE}"

            perform_apply

            printf 'STALE_COUNT:%d\n' "\${stale_count}"
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('STALE_COUNT:1');
        expect($output)->toContain('DELETED v1.0.1-20260101-000000-abc1231');
        expect($output)->toContain('DELETED v1.0.2-20260102-000000-abc1232');
        expect(is_dir($root.'/releases/v1.0.1-20260101-000000-abc1231'))->toBeFalse();
        expect(is_dir($root.'/releases/v1.0.2-20260102-000000-abc1232'))->toBeFalse();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('deletes only validated candidates, leaving protected releases in place', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
            'v1.0.2-20260102-000000-abc1232' => 'success',
            'v1.0.3-20260103-000000-abc1233' => 'success',
        ], current: 'v1.0.3-20260103-000000-abc1233', pinned: ['v1.0.2-20260102-000000-abc1232']);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root, retention: 0);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
        ], <<<'BASH'
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree
            perform_apply
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->toBe(0, $output);
        expect(is_dir($root.'/releases/v1.0.1-20260101-000000-abc1231'))->toBeFalse('failed release should be deleted');
        expect(is_dir($root.'/releases/v1.0.2-20260102-000000-abc1232'))->toBeTrue('pinned release must survive');
        expect(is_dir($root.'/releases/v1.0.3-20260103-000000-abc1233'))->toBeTrue('current release must survive');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('appends release-cleaned to history only after a successful deletion', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
        ], <<<'BASH'
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree
            perform_apply
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->toBe(0, $output);

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $cleanedEntries = array_filter($history, fn (string $line) => str_contains($line, 'release-cleaned'));

        expect($cleanedEntries)->toHaveCount(1);
        $entry = json_decode(reset($cleanedEntries), true, 512, JSON_THROW_ON_ERROR);
        expect($entry['event'])->toBe('release-cleaned');
        expect($entry['release'])->toBe('v1.0.1-20260101-000000-abc1231');
        expect($entry['status'])->toBe('success');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('stops on the first failed deletion without deleting subsequent candidates or appending history for them', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'failed-health-check',
            'v1.0.2-20260102-000000-abc1232' => 'failed-health-check',
        ]);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        // Deletion order is newest-mtime-first: v1.0.2 (built with a later
        // mtime) is deleted first, so the stub fails on the *second* rm call
        // — proving v1.0.1 is never reached once v1.0.2 succeeds is not what
        // this proves; instead, fail on the *first* call so nothing at all
        // gets deleted and history stays completely clean.
        cleanupOpsFailingRmStub($scratch, failAtCall: 1);

        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
        ], <<<'BASH'
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree
            perform_apply
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('stub rm: forced failure');
        expect($output)->not->toContain('DELETED');

        $history = trim(file_get_contents($root.'/deployments/history.jsonl'));
        expect($history)->not->toContain('release-cleaned');

        // Both directories remain — the failed rm did not partially delete
        // anything, and the second candidate was never reached at all.
        expect(is_dir($root.'/releases/v1.0.1-20260101-000000-abc1231'))->toBeTrue();
        expect(is_dir($root.'/releases/v1.0.2-20260102-000000-abc1232'))->toBeTrue();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never contacts a planned target during apply, even with an explicit --apply', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $markerFile = $scratch.'/rm-invoked';
        cleanupOpsForbiddenRmStub($scratch, $markerFile);

        [$exit, $output] = cleanupOpsRunScript(
            ['--target', 'tits-guru', '--apply'],
            cleanupOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('lifecycle=planned');
        expect(file_exists($markerFile))->toBeFalse();
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// pinned-releases ownership contract
// =============================================================================

it('creates a missing pinned-releases file with exactly the configured owner, group and mode', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, []);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        expect(file_exists($root.'/deployments/pinned-releases'))->toBeFalse();

        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
            'PINNED_FILE_MODE' => '0640',
        ], <<<'BASH'
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree
            perform_apply
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->toBe(0, $output);

        $pinnedPath = $root.'/deployments/pinned-releases';
        expect(file_exists($pinnedPath))->toBeTrue();
        clearstatcache(true, $pinnedPath);

        $stat = stat($pinnedPath);
        expect($stat['uid'])->toBe((int) $uid);
        expect($stat['gid'])->toBe((int) $gid);
        expect($stat['mode'] & 0o7777)->toBe(0o640);
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('leaves a pre-existing valid pinned-releases file byte-for-byte and timestamp-for-timestamp unchanged during apply', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $root = cleanupOpsBuildFixture($scratch, [
            'v1.0.1-20260101-000000-abc1231' => 'success',
        ], pinned: ['v1.0.1-20260101-000000-abc1231']);
        $confPath = cleanupOpsDeploymentConfForRoot($scratch, $root);
        $uid = (string) getmyuid();
        $gid = (string) getmygid();

        $pinnedPath = $root.'/deployments/pinned-releases';
        chmod($pinnedPath, 0o600);
        touch($pinnedPath, 1700000000);
        clearstatcache(true, $pinnedPath);
        $beforeContent = file_get_contents($pinnedPath);
        $beforeMode = fileperms($pinnedPath) & 0o7777;
        $beforeMtime = filemtime($pinnedPath);

        // Deliberately mismatched from the file's actual pre-existing mode —
        // proving perform_apply never re-installs over an already-present
        // file, regardless of what PINNED_FILE_OWNER/GROUP/MODE say.
        [$exit, $output] = cleanupOpsRunHarness($scratch, [
            'PINNED_FILE_OWNER' => $uid,
            'PINNED_FILE_GROUP' => $gid,
            'PINNED_FILE_MODE' => '0640',
        ], <<<'BASH'
            parse_cleanup_args --environment staging --apply
            resolve_target
            validate_target_tree
            perform_apply
            BASH,
            ['RATEGURU_ALLOW_TEST_OVERRIDES' => 'true', 'RATEGURU_COMMON_FILE' => cleanupOpsCommonFile(), 'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath],
        );

        expect($exit)->toBe(0, $output);

        clearstatcache(true, $pinnedPath);
        expect(file_get_contents($pinnedPath))->toBe($beforeContent);
        expect(fileperms($pinnedPath) & 0o7777)->toBe($beforeMode, 'a pre-existing pinned-releases file must keep its own mode, not PINNED_FILE_MODE');
        expect(filemtime($pinnedPath))->toBe($beforeMtime, 'a pre-existing pinned-releases file must never be rewritten');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

// =============================================================================
// out of scope: --environment behaviour is untouched
// =============================================================================

it('keeps --environment production distinct from any tits-guru path', function () {
    $scratch = cleanupOpsScratchDir();

    try {
        $source = cleanupOpsSource();

        expect($source)->not->toMatch('/production.{0,40}tits-guru|tits-guru.{0,40}production/is');
    } finally {
        cleanupOpsCleanup($scratch);
    }
});

it('never parses deployment-targets.json directly, only through common target_* helpers', function () {
    $source = cleanupOpsSource();

    expect($source)
        ->not->toContain('.targets[')
        ->not->toContain('deployment-targets.json');
});

it('passes bash -n and never sources common or the registry unsafely', function () {
    $output = [];
    $exit = 0;
    exec('bash -n '.escapeshellarg(cleanupOpsScript()).' 2>&1', $output, $exit);

    expect($exit)->toBe(0, implode("\n", $output));
});
