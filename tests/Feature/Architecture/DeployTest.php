<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 4 slice 5: target-aware deployment.
 *
 * These tests execute the real shipped `infrastructure/scripts/deploy` —
 * never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator,
 * health-check, verify-required-clis, systemctl, runuser, PHP/artisan)
 * supplied through the RATEGURU_* test-override contract already
 * established for health-check/status/cleanup, plus two new ones specific
 * to deploy (RATEGURU_HEALTH_CHECK_BIN, RATEGURU_VERIFY_REQUIRED_CLIS_BIN).
 *
 * deploy's own source guard (`if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
 * main "$@"; fi`) means sourcing the file never auto-runs main() — so, unlike
 * cleanup/install-target-operations, no separate "functions section"
 * extraction is needed here: tests simply `source` the real file directly,
 * then call parse_deploy_args/resolve_target/perform_deploy (bypassing
 * require_root, for parsing/resolution/pipeline coverage that doesn't need
 * real root) or invoke the script as a genuine subprocess (which does run
 * main(), requiring root first, exactly as production).
 *
 * Ownership/mode commands (chown, install -o/-g) run for real using this
 * test process's own uid/gid (getmyuid()/getmygid(), as raw numeric IDs —
 * chown/install both accept numeric owner:group directly) as
 * RUNTIME_USER/DEPLOY_ACCOUNT/CODE_GROUP in every fixture — the same
 * precedent installOpsBaseVars() already established in
 * InstallTargetOperationsTest.php — so these genuinely succeed without root
 * and without needing fake system accounts. systemctl, runuser, health-check
 * and verify-required-clis are PATH-shadowed or RATEGURU_*-overridden stubs;
 * `www-data`-dependent assertions (Laravel prep's shared/app ownership) are
 * skipped unless this test process is itself a member of a real www-data
 * group on the host — group *existence* alone is not sufficient, since
 * `install -g www-data` as a non-root process requires membership, not just
 * presence (e.g. absent entirely on macOS dev machines; present but this
 * account not a member of it on GitHub Actions' ubuntu-latest runner).
 */
function deployOpsScript(): string
{
    return base_path('infrastructure/scripts/deploy');
}

function deployOpsSource(): string
{
    return File::get(deployOpsScript());
}

function deployOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function deployOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function deployOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function deployOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function deployOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/deploy-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function deployOpsCleanup(string $dir): void
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
function deployOpsExec(string $scriptPath, array $env): array
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
 * Run the real deploy script as a genuine subprocess (never sourced) — this
 * is what makes BASH_SOURCE[0] == $0 true inside it, so its own source guard
 * calls main(), which calls require_root first, exactly like production.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', deployOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start deploy subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources the real deploy script (BASH_SOURCE[0] != $0 here, so main() never
 * auto-runs — see the file-level docblock above) then runs $body, which can
 * call parse_deploy_args/resolve_target/perform_deploy/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsRunHarness(string $scratch, string $body, array $env = []): array
{
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg(deployOpsScript())."\n".$body."\n";
    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return deployOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function deployOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => deployOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => deployOpsTargetsCli(),
    ], $overrides);
}

/**
 * A stub matching health-check's real --target CLI shape closely enough for
 * deploy's own purposes: logs its argv, exits 0 (or 1 to simulate a failed
 * post-switch health check).
 */
function deployOpsHealthCheckStub(string $scratch, string $logFile, bool $fail = false): string
{
    $path = $scratch.'/bin/health-check-stub-'.uniqid('', true);
    $exitCode = $fail ? 1 : 0;
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

function deployOpsVerifyRequiredClisStub(string $scratch, string $logFile): string
{
    $path = $scratch.'/bin/verify-required-clis-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed stubs for systemctl and runuser — real fixtures use numeric
 * uid/gid strings as RUNTIME_USER, which real runuser cannot resolve to a
 * real account and does not need to: this stub drops "-u VALUE --" and execs
 * the remaining command directly as the current (test) user.
 */
function deployOpsInstallCoreStubs(string $scratch): void
{
    file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
        .'echo "systemctl $*" >> '.escapeshellarg($scratch.'/systemctl.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/systemctl', 0o755);

    file_put_contents($scratch.'/bin/runuser', "#!/usr/bin/env bash\n"
        .'echo "runuser $*" >> '.escapeshellarg($scratch.'/runuser.log')."\n"
        .'shift 2; shift'."\n"
        .'exec "$@"'."\n");
    chmod($scratch.'/bin/runuser', 0o755);
}

function deployOpsFakePhpBin(string $scratch): string
{
    $path = $scratch.'/bin/fake-php';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "php $*" >> '.escapeshellarg($scratch.'/artisan.log')."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A scratch target root + a separate incoming-artifacts directory + a real
 * .tar.gz built with real tar/sha256sum (portable, no stubbing needed). Pass
 * $laravel=true to additionally include artisan and the required-CLI
 * manifest verify-required-clis (stubbed elsewhere) would otherwise expect.
 *
 * @return array{root: string, incoming: string, artifact: string, checksum: string}
 */
function deployOpsBuildFixture(string $scratch, bool $laravel = false): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;
    $incoming = $scratch.'/incoming-'.$id;

    foreach ([
        $root.'/releases',
        $root.'/deployments',
        $root.'/locks',
        $root.'/shared/storage',
        $incoming,
    ] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }
    touch($root.'/shared/.env');

    $artifactSrc = $scratch.'/artifact-src-'.$id;
    mkdir($artifactSrc.'/public', 0o755, true);
    file_put_contents($artifactSrc.'/public/index.php', "<?php // fixture\n");

    $tarEntries = 'public';

    if ($laravel) {
        file_put_contents($artifactSrc.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");
        mkdir($artifactSrc.'/infrastructure/config', 0o755, true);
        mkdir($artifactSrc.'/infrastructure/scripts', 0o755, true);
        file_put_contents($artifactSrc.'/infrastructure/config/required-clis.txt', "targets\n");
        file_put_contents($artifactSrc.'/infrastructure/scripts/targets', "#!/usr/bin/env bash\nexit 0\n");
        chmod($artifactSrc.'/infrastructure/scripts/targets', 0o755);
        file_put_contents($artifactSrc.'/infrastructure/scripts/common', "#!/usr/bin/env bash\n");
        chmod($artifactSrc.'/infrastructure/scripts/common', 0o644);
        $tarEntries = 'public artisan infrastructure';
    }

    $artifact = $incoming.'/release.tar.gz';
    exec('tar -C '.escapeshellarg($artifactSrc)." -czf {$artifact} {$tarEntries} 2>&1", $tarOutput, $tarExit);
    expect($tarExit)->toBe(0, "failed to build fixture artifact:\n".implode("\n", $tarOutput));

    exec('cd '.escapeshellarg($incoming).' && sha256sum '.escapeshellarg(basename($artifact)).' > '.escapeshellarg(basename($artifact).'.sha256').' 2>&1', $shaOutput, $shaExit);
    expect($shaExit)->toBe(0, "failed to build fixture checksum:\n".implode("\n", $shaOutput));

    return ['root' => $root, 'incoming' => $incoming, 'artifact' => $artifact, 'checksum' => $artifact.'.sha256'];
}

/**
 * A scratch, writable copy of the real committed deployment.conf.example,
 * verbatim. Formerly rewrote STAGING_ROOT/STAGING_RUNTIME_USER/
 * STAGING_CODE_GROUP/STAGING_DEPLOY_USER/STAGING_INCOMING_ARTIFACTS to point
 * at each fixture's own paths — but the template no longer carries any
 * target-specific field at all (see
 * infrastructure/templates/deployment.conf.example's own header comment):
 * every one of those values now comes exclusively from the target registry,
 * via deployOpsParityRegistry(), which already derives them from the
 * fixture's own root/incoming plus the current account/group. Still returns
 * a fresh scratch copy (rather than the template path itself) so callers
 * that mutate it afterward — e.g. the Laravel-prep test's own PHP_BIN
 * override — never touch the repository's own source file.
 */
function deployOpsDeploymentConfForFixture(string $scratch): string
{
    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, File::get(deployOpsDeploymentConfPath()));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique CleanupTest.php established,
 * re-derived here rather than shared, per this codebase's convention of each
 * test file owning its own helper namespace. runtime_user/deploy_user/
 * runtime_group/code_group are the current test process's own account/
 * primary group name (deployOpsCurrentAccount()/deployOpsCurrentGroup()) —
 * the registry is the only source of these values in target mode now;
 * deployment.conf no longer carries any target-specific field at all (see
 * deployOpsDeploymentConfForFixture()).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function deployOpsParityRegistry(string $scratch, array $fixture): array
{
    $account = deployOpsCurrentAccount();
    $group = deployOpsCurrentGroup();

    // Four constraints the committed `targets` validator enforces as
    // production safety rails, relaxed only in this throwaway, test-only
    // copy — the same technique CleanupTest.php already established for
    // application_root/ACTIVE_ALLOWLIST: (1) application_root and
    // (2) incoming_artifacts must otherwise live under /home/www/rateguru
    // and /home respectively, which a scratch fixture can't satisfy;
    // (3) code_group must otherwise differ from runtime_group, and
    // (4) code_group must otherwise differ from the runtime user's own
    // name — both real registry-modeling rules this fixture doesn't need to
    // honor. It just needs one group name the test process can chown to
    // without root, and reusing the test's own account/primary-group for
    // both runtime_user and code_group is fine here (that modeling rule is
    // already covered elsewhere, e.g. DeploymentTargetRegistryTest.php).
    // Constraint (4) only surfaces where a host's own account/primary-group
    // pair happen to share a name — e.g. GitHub Actions' `runner` user,
    // whose primary group is also named `runner` — so this was missed
    // locally (this machine's account and primary group differ) until CI
    // caught it.
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(deployOpsTargetsCli()),
    );
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
                'incoming_artifacts' => $fixture['incoming'],
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example', 'parity-secondary.example'],
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

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

/**
 * Group *existence* alone is not enough: deploy's Laravel-prep step runs
 * `install -g www-data` as this test process, and a non-root process can
 * only chgrp to a group it is itself a member of. The www-data system group
 * exists on GitHub Actions' ubuntu-latest runner, but the `runner` account
 * is not a member of it, which `getent group www-data` alone can't see —
 * confirmed via a real CI failure ("Operation not permitted") that only a
 * membership check catches.
 */
function deployOpsWwwDataAvailable(): bool
{
    exec('getent group www-data >/dev/null 2>&1', $output, $exit);
    if ($exit !== 0) {
        return false;
    }

    if (getmyuid() === 0) {
        return true;
    }

    exec('id -nG', $groups);
    $memberships = preg_split('/\s+/', trim($groups[0] ?? ''));

    return in_array('www-data', $memberships, true);
}

/**
 * The current test process's real username/primary group name — via `id`,
 * not PHP's posix_* extension (not guaranteed enabled everywhere). Used as
 * RUNTIME_USER/DEPLOY_ACCOUNT/CODE_GROUP so chown/install succeed without
 * root, and because the target registry validator requires account-name-
 * shaped strings (a raw numeric uid fails is_safe_account_name).
 */
function deployOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function deployOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/whatever.tar.gz
            resolve_target
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --release v1 --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target a --target b --release v1 --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --environment staging', deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --target given without a value', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --target', deployOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --release, --artifact or --checksum given without a value, instead of failing on an uncontrolled shift', function () {
    // Regression test: --release/--artifact/--checksum used to read "${2:-}"
    // (always safe under set -u) and then unconditionally `shift 2`, even
    // when no second argument existed. `shift 2` with only one positional
    // parameter left fails under `set -e`, aborting with no message at all
    // instead of a clear "requires a value" error — exactly the failure mode
    // --target was already guarded against.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --artifact',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a value');

        // --checksum stays optional when omitted entirely: deploy falls back
        // to "${ARTIFACT}.sha256" — see the end-to-end parity tests below.
        // This only proves that *when given*, --checksum still requires a
        // value, exactly like --release/--artifact.
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release RELEASE --artifact ARTIFACT --checksum',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty value for --release, --artifact or --checksum', function () {
    // Regression test: --release/--artifact/--checksum used to accept an
    // explicit empty value silently — --release/--artifact only surfaced it
    // later, as the generic "--release is required"/"--artifact is
    // required", and --checksum '' silently fell back to the default
    // "${ARTIFACT}.sha256" as if the flag had never been given at all.
    // require_flag_value now rejects all three immediately, by name.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release '' --artifact /tmp/x.tar.gz",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a non-empty value');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release v1 --artifact ''",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a non-empty value');

        // --checksum stays optional only when omitted entirely — an
        // explicit empty value is a caller mistake (e.g. an unset shell
        // variable interpolated into the command line) and must fail
        // loudly, not silently substitute a default the caller never asked
        // for.
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release v1 --artifact /tmp/x.tar.gz --checksum ''",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a non-empty value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --release, --artifact, --checksum or --target swallowing the next flag as their value', function () {
    // Regression test: every value-taking flag used to blindly take
    // whatever token followed it, including another flag — e.g.
    // `--release --migrate` set RELEASE_ID to the literal string
    // "--migrate" and silently shifted past --migrate itself, leaving
    // RUN_MIGRATIONS false with no error at all. require_flag_value now
    // rejects any next-token starting with "-" by name, for every
    // value-taking flag deploy parses.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release --migrate --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1 --artifact --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1 --artifact /tmp/x.tar.gz --checksum --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --migrate');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('still parses valid values that happen to follow --migrate or precede it', function () {
    // Preserves the ordinary case: a real value immediately followed by the
    // standalone --migrate flag still parses both correctly — this is what
    // the stricter require_flag_value checks above must not break.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/x.tar.gz --migrate'
            .'; printf "RELEASE_ID=[%s]\nARTIFACT=[%s]\nRUN_MIGRATIONS=[%s]\n" "${RELEASE_ID}" "${ARTIFACT}" "${RUN_MIGRATIONS}"',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[v1.0.0-20260101-000000-abc1234]')
            ->toContain('ARTIFACT=[/tmp/x.tar.gz]')
            ->toContain('RUN_MIGRATIONS=[true]');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty selector value', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target '' --release v1 --artifact /tmp/x.tar.gz",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    // A real subprocess (`bash deploy --help`) still requires root first,
    // unchanged from before this slice — see "requires root before anything
    // else" below. This proves --help's own content via parse_deploy_args
    // directly (sourced, bypassing require_root), i.e. what a real
    // invocation reaches only once root authorization has already passed.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --help', deployOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --bogus', deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('preserves the existing --release/--artifact required-value behavior unchanged', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release is required');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact is required');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Gated test overrides: RATEGURU_HEALTH_CHECK_BIN / RATEGURU_VERIFY_REQUIRED_CLIS_BIN
// =============================================================================

it('ignores RATEGURU_HEALTH_CHECK_BIN/RATEGURU_VERIFY_REQUIRED_CLIS_BIN without the opt-in flag, and honors them with it', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            printf 'DENIED HEALTH_CHECK_BIN=%s\n' "${HEALTH_CHECK_BIN}"
            printf 'DENIED VERIFY_REQUIRED_CLIS_BIN=%s\n' "${VERIFY_REQUIRED_CLIS_BIN}"
            BASH, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
            // Deliberately no RATEGURU_ALLOW_TEST_OVERRIDES set for the two new
            // overrides themselves is impossible to isolate from the flag
            // above (one flag gates both) — so this proves the "denied" half
            // differently: the values default to the hardcoded production
            // paths even though poisoned override vars are present, because
            // this harness passes them under names the script never reads
            // unless it explicitly opts in per-value below.
        ]);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('DENIED HEALTH_CHECK_BIN=/home/www/rateguru/bin/health-check')
            ->toContain('DENIED VERIFY_REQUIRED_CLIS_BIN=/home/www/rateguru/bin/verify-required-clis');

        [$deniedExit, $deniedOutput] = deployOpsRunHarness($scratch, <<<'BASH'
            printf 'HEALTH_CHECK_BIN=%s\n' "${HEALTH_CHECK_BIN}"
            printf 'VERIFY_REQUIRED_CLIS_BIN=%s\n' "${VERIFY_REQUIRED_CLIS_BIN}"
            BASH, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => '/tmp/poisoned-verify',
        ]);
        // With RATEGURU_ALLOW_TEST_OVERRIDES already true (required just to
        // reach a sourceable common/deployment.conf at all in this harness),
        // the two new overrides ARE honored — this is the "granted" half.
        expect($deniedExit)->toBe(0, $deniedOutput);
        expect($deniedOutput)
            ->toContain('HEALTH_CHECK_BIN=/tmp/poisoned-health-check')
            ->toContain('VERIFY_REQUIRED_CLIS_BIN=/tmp/poisoned-verify');

        // The genuinely gate-off case: no RATEGURU_ALLOW_TEST_OVERRIDES at
        // all, real subprocess, deploy must fail at its hardcoded default
        // COMMON_FILE path (proving no override of any kind took effect) —
        // the same technique TargetAwareOperationsTest.php already
        // established for this exact class of assertion.
        [$fullyDeniedExit, $fullyDeniedOutput] = deployOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => '/tmp/poisoned-verify',
        ]);
        expect($fullyDeniedExit)->not->toBe(0);
        expect($fullyDeniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first contract + lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target with an obviously invalid deployment', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunScript(
            ['--target', 'tits-guru', '--release', 'v0.0.0-20260101-000000-abc0000', '--artifact', '/definitely/missing.tar.gz'],
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
        expect($output)->not->toContain('artifact does not exist');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a planned target before any artifact, checksum, incoming-directory or filesystem validation', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target tits-guru --release v0.0.0-20260101-000000-abc0000 --artifact /definitely/missing.tar.gz
            resolve_target
            echo "SHOULD NOT REACH HERE"
            BASH, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
        expect($output)->not->toContain('artifact does not exist');
        expect($output)->not->toContain('incoming');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target ghost-target --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/x.tar.gz
            resolve_target
            BASH, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Artifact isolation
// =============================================================================

it('rejects an artifact located outside the target incoming-artifacts directory', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);
        $confPath = deployOpsDeploymentConfForFixture($scratch);

        $outsideArtifact = $scratch.'/outside.tar.gz';
        copy($fixture['artifact'], $outsideArtifact);
        copy($fixture['checksum'], $outsideArtifact.'.sha256');

        [$exit, $output] = deployOpsRunHarness($scratch, <<<BASH
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact {$outsideArtifact}
            resolve_target
            perform_deploy
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('artifact must be located inside');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a checksum located outside the target incoming-artifacts directory', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);
        $confPath = deployOpsDeploymentConfForFixture($scratch);

        $outsideChecksum = $scratch.'/outside.tar.gz.sha256';
        copy($fixture['checksum'], $outsideChecksum);

        [$exit, $output] = deployOpsRunHarness($scratch, <<<BASH
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact {$fixture['artifact']} --checksum {$outsideChecksum}
            resolve_target
            perform_deploy
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('checksum must be located inside');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// End-to-end scratch: a full deployment through --target
// =============================================================================

/**
 * Runs one full, real deployment (extraction, symlinks, ownership/mode
 * normalization, verify-required-clis, atomic switch, health-check, history)
 * against a fresh fixture, via --target parity-target.
 *
 * @return array{exit: int, output: string, fixture: array, healthCheckLog: string, verifyCliLog: string, releaseId: string}
 */
function deployOpsRunFullDeployment(string $scratch, ?bool $failHealthCheck = false): array
{
    $fixture = deployOpsBuildFixture($scratch);
    $confPath = deployOpsDeploymentConfForFixture($scratch);
    deployOpsInstallCoreStubs($scratch);
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog, $failHealthCheck ?? false);

    $verifyCliLog = $scratch.'/verify-cli-'.uniqid('', true).'.log';
    touch($verifyCliLog);
    $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

    $releaseId = 'v1.0.0-2026010'.random_int(1, 9).'-000000-abc'.random_int(1000, 9999);

    $env = deployOpsBaseEnv($scratch, [
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
        'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
    ]);

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'healthCheckLog' => $healthCheckLog,
        'verifyCliLog' => $verifyCliLog,
        'releaseId' => $releaseId,
    ];
}

it('deploys via --target: content, ownership, symlinks, links, history, health selector', function () {
    $scratch = deployOpsScratchDir();

    try {
        $result = deployOpsRunFullDeployment($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $root = $result['fixture']['root'];
        $releaseRoot = $root.'/releases/'.$result['releaseId'];

        expect(is_dir($releaseRoot))->toBeTrue('release directory must exist');
        expect(file_get_contents($releaseRoot.'/public/index.php'))->toBe("<?php // fixture\n", 'release content');

        clearstatcache(true, $releaseRoot);
        $stat = stat($releaseRoot);
        expect($stat['uid'])->toBe(getmyuid(), 'release owned by DEPLOY_ACCOUNT');
        expect($stat['gid'])->toBe(getmygid(), 'release group is CODE_GROUP');

        expect(readlink($releaseRoot.'/.env'))->toBe($root.'/shared/.env', '.env symlink');
        expect(readlink($releaseRoot.'/storage'))->toBe($root.'/shared/storage', 'storage symlink');
        expect(readlink($releaseRoot.'/public/storage'))->toBe($root.'/shared/storage/app/public', 'public/storage symlink');

        expect(readlink($root.'/current'))->toBe($releaseRoot, 'current symlink');
        expect(is_link($root.'/previous'))->toBeFalse('previous must be absent (first deployment)');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2, 'deployment-started + deployment-finished');
        $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
        $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
        expect($started['event'])->toBe('deployment-started');
        expect($started['status'])->toBe('running');
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('success');
        expect($finished['release'])->toBe($result['releaseId']);

        expect(trim(file_get_contents($scratch.'/systemctl.log')))->toContain('reload');

        expect(trim(File::get($result['healthCheckLog'])))->toBe('--target parity-target');
        expect($result['output'])->toContain('parity-target deployed successfully');

        // No artisan in this fixture, so queue:restart must never run.
        expect(File::exists($scratch.'/runuser.log'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Laravel-prep command sequence (guarded: this process needs to itself be a
// member of a real www-data group, not merely have one exist on the host)
// =============================================================================

it('runs the Laravel artisan command sequence with the correct --expected-host', function () {
    if (! deployOpsWwwDataAvailable()) {
        test()->markTestSkipped('no www-data group on this host, or this account is not a member of it — Laravel prep\'s shared/app ownership (install -g www-data, as this non-root test process) needs both');
    }

    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch, laravel: true);
        $confPath = deployOpsDeploymentConfForFixture($scratch);
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        $conf = preg_replace('/^PHP_BIN=.*$/m', 'PHP_BIN='.$scratch.'/bin/fake-php', File::get($confPath));
        file_put_contents($confPath, $conf);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog);
        $verifyCliLog = $scratch.'/vc.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);
        $artisanLog = $scratch.'/artisan.log';
        @unlink($artisanLog);

        $env = deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
        ]);

        $releaseId = 'v1.0.0-2026010'.random_int(1, 9).'-000000-abc'.random_int(1000, 9999);
        $expectedHost = 'parity.example';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --migrate --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
            $env,
        );

        expect($exit)->toBe(0, $output);

        $artisanCalls = array_values(array_filter(explode("\n", trim(File::get($artisanLog)))));
        expect($artisanCalls)->toHaveCount(5, 'config:cache, sharing:verify, view:cache, migrate, queue:restart');
        expect($artisanCalls[0])->toContain('artisan config:cache');
        expect($artisanCalls[1])->toContain('artisan rateguru:sharing:verify')->toContain("--expected-host={$expectedHost}");
        expect($artisanCalls[2])->toContain('artisan view:cache');
        expect($artisanCalls[3])->toContain('artisan migrate --force');
        expect($artisanCalls[4])->toContain('artisan queue:restart');

        $verifyLog = trim(File::get($verifyCliLog));

        expect($verifyLog)
            ->toContain('--release-root')
            ->toContain($fixture['root'].'/releases/.'.$releaseId.'.tmp-');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Failure recovery
// =============================================================================

it('recovers correctly from a failed post-switch health check', function () {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        // realpath(), not readlink(): deploy itself canonicalizes via
        // readlink -f when capturing/restoring ORIGINAL_CURRENT_PATH (resolving
        // e.g. /var -> /private/var on macOS), so comparing against a raw,
        // uncanonicalized readlink() here would be a false mismatch, not a real one.
        $originalCurrent = realpath($root.'/current');

        $confPath = deployOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $first['fixture']);
        deployOpsInstallCoreStubs($scratch);
        $failingHealthCheckLog = $scratch.'/failing-hc-target.log';
        touch($failingHealthCheckLog);
        $failingHealthCheckStub = deployOpsHealthCheckStub($scratch, $failingHealthCheckLog, fail: true);
        $verifyCliLog = $scratch.'/verify-cli-3.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

        $secondReleaseId = 'v1.0.1-20260102-000000-def5679';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --release {$secondReleaseId} --artifact {$first['fixture']['artifact']}\nresolve_target\nperform_deploy",
            deployOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $failingHealthCheckStub,
                'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deployment health check failed');

        expect(realpath($root.'/current'))->toBe($originalCurrent, 'current must be restored to the prior release');
        expect(is_link($root.'/previous'))->toBeFalse('previous must not have been created by the failed deployment');
        expect(is_dir($root.'/releases/'.$secondReleaseId))->toBeFalse('the failed release directory must be removed');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $last = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($last['event'])->toBe('deployment-finished');
        expect($last['status'])->toBe('failed-health-check');
        expect($last['release'])->toBe($secondReleaseId);

        // Recovery uses the same target deploy was invoked with — proven by
        // the fact the failing stub itself was only ever reached via
        // HEALTH_CHECK_ARGS=(--target parity-target); the recovery path here
        // does not invoke health-check a second time (deploy's own recovery,
        // unlike rollback's, never re-checks health — see
        // restore_deployment_links).
        expect(trim(File::get($failingHealthCheckLog)))->toBe('--target parity-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Out of scope: nothing else changed
//
// A prior version of this file had its own "leaves sudoers and workflows
// untouched" test here, checking infrastructure/config/sudoers/rateguru-deploy
// and .github/workflows/deploy-staging.yml against origin/develop. Both
// legitimately graduated to the generic wrappers/--target staging-main in
// Phase 4 slice 8 (see TargetPerimeterTest.php), leaving that test with an
// empty $unchanged list — it was deleted outright rather than left as a
// vacuous loop, matching the precedent already set elsewhere in this file's
// own history for scripts that graduate out of "still legacy-only".
// =============================================================================
