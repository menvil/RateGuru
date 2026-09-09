<?php

use Illuminate\Support\Facades\File;

/**
 * recovery-host-preflight: the read-only proof that a machine is a supported,
 * genuinely clean replacement host a clean-host recovery may be prepared on.
 *
 * Executed for real against a scratch filesystem root, a scratch os-release,
 * an account database emulated by a getent stub and — where a test installs
 * one — a psql stub answering the script's own read-only questions. Every
 * verdict asserted below is the script's; nothing of its logic is mocked.
 */
function preflightScript(): string
{
    return base_path('infrastructure/scripts/recovery-host-preflight');
}

/**
 * A clean Ubuntu 22.04 x86_64 machine: an empty /home, the configuration
 * directories every Ubuntu image has, and none of the services a clean image
 * lacks — no /etc/nginx, no /etc/php, no /etc/supervisor, no psql.
 */
function preflightCleanHost(string $scratch, array $options = []): string
{
    $fs = $scratch.'/fs';

    foreach (['/home', '/etc/cron.d', '/etc/systemd/system', '/usr/local/sbin', '/etc/sudoers.d', '/etc/ssh'] as $dir) {
        mkdir($fs.$dir, 0o755, true);
    }

    file_put_contents($scratch.'/os-release', implode("\n", [
        'PRETTY_NAME="Ubuntu 22.04.4 LTS"',
        'NAME="Ubuntu"',
        'VERSION_ID="22.04"',
        'VERSION="22.04.4 LTS (Jammy Jellyfish)"',
        'ID=ubuntu',
        'ID_LIKE=debian',
        '',
    ]));

    // The bootstrap access a recovery reaches a replacement host through is
    // installed out of band BEFORE any of this can run, so a genuinely clean
    // machine already carries the account, its own group, its authorized_keys
    // and its sudoers grant. This is what the real one looks like.
    $bootstrap = $options['bootstrap_account'] ?? 'rateguru-bootstrap';

    mkdir($scratch.'/accounts', 0o755, true);
    file_put_contents($scratch.'/accounts/passwd', "root:x:0:0:root:/root:/bin/bash\nubuntu:x:1000:1000::/home/ubuntu:/bin/bash\n");
    file_put_contents($scratch.'/accounts/group', "root:x:0:\nsudo:x:27:ubuntu\nubuntu:x:1000:\n");

    if ($bootstrap !== '') {
        file_put_contents($scratch.'/accounts/passwd', "{$bootstrap}:x:1001:1001::/home/{$bootstrap}:/bin/bash\n", FILE_APPEND);
        file_put_contents($scratch.'/accounts/group', "{$bootstrap}:x:1001:\n", FILE_APPEND);

        mkdir($fs.'/home/'.$bootstrap.'/.ssh', 0o700, true);
        file_put_contents($fs.'/home/'.$bootstrap.'/.ssh/authorized_keys', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIRecoveryBootstrapKey recovery\n");
        file_put_contents($fs.'/etc/sudoers.d/'.$bootstrap, "{$bootstrap} ALL=(ALL) NOPASSWD:ALL\n");
    }

    @mkdir($scratch.'/bin', 0o755, true);

    // getent as the script uses it: the whole passwd or group database, or
    // one named entry — exit 2 when the entry does not exist, like the real one.
    writeExecutable($scratch.'/bin/getent', <<<'STUB'
#!/usr/bin/env bash
database="${1:-}"
shift || true
file="${RGTEST_ACCOUNTS_DIR}/${database}"
[[ -f "${file}" ]] || exit 2
if [[ $# -eq 0 ]]; then
    cat "${file}"
    exit 0
fi
status=2
for name in "$@"; do
    if grep -q "^${name}:" "${file}"; then
        grep "^${name}:" "${file}"
        status=0
    fi
done
exit "${status}"
STUB);

    return $fs;
}

/**
 * A PostgreSQL client answering the preflight's read-only questions from two
 * lists, or — when the server is "down" — refusing to connect at all.
 *
 * @param  list<string>  $databases
 * @param  list<string>  $roles
 */
function preflightInstallPsql(string $scratch, array $databases = [], array $roles = [], bool $down = false): void
{
    file_put_contents($scratch.'/psql-databases', implode("\n", $databases)."\n");
    file_put_contents($scratch.'/psql-roles', implode("\n", $roles)."\n");
    file_put_contents($scratch.'/psql-down', $down ? "true\n" : "false\n");
    file_put_contents($scratch.'/psql.log', '');

    writeExecutable($scratch.'/bin/psql', <<<'STUB'
#!/usr/bin/env bash
set -u
if [[ "$(cat "${RGTEST_PSQL_DIR}/psql-down")" == true ]]; then
    echo 'psql: error: connection to server on socket "/var/run/postgresql/.s.PGSQL.5432" failed: No such file or directory' >&2
    exit 2
fi
query=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        -c) query="$2"; shift 2 ;;
        *) shift ;;
    esac
done
printf '%s\n' "${query}" >> "${RGTEST_PSQL_DIR}/psql.log"
quote="'"
case "${query}" in
    *"FROM pg_database WHERE datname LIKE 'rateguru%'"*)
        grep '^rateguru' "${RGTEST_PSQL_DIR}/psql-databases" || true ;;
    *"FROM pg_roles WHERE rolname LIKE 'rateguru%'"*)
        grep '^rateguru' "${RGTEST_PSQL_DIR}/psql-roles" || true ;;
    *"FROM pg_database WHERE datname = '"*)
        name="${query#*datname = ${quote}}"
        name="${name%%${quote}*}"
        grep -qx -- "${name}" "${RGTEST_PSQL_DIR}/psql-databases" && echo 1 || true ;;
    *"FROM pg_roles WHERE rolname = '"*)
        name="${query#*rolname = ${quote}}"
        name="${name%%${quote}*}"
        grep -qx -- "${name}" "${RGTEST_PSQL_DIR}/psql-roles" && echo 1 || true ;;
    *)
        echo "unexpected query: ${query}" >&2
        exit 1 ;;
esac
STUB);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function preflightEnv(string $scratch, array $overrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_RECOVERYPREFLIGHT_EUID' => '0',
        'RATEGURU_RECOVERYPREFLIGHT_FS_ROOT' => $scratch.'/fs',
        'RATEGURU_RECOVERYPREFLIGHT_OS_RELEASE_FILE' => $scratch.'/os-release',
        'RATEGURU_RECOVERYPREFLIGHT_ARCH' => 'x86_64',
        'RATEGURU_RECOVERYPREFLIGHT_GETENT_BIN' => $scratch.'/bin/getent',
        'RATEGURU_RECOVERYPREFLIGHT_PSQL_BIN' => $scratch.'/bin/psql',
        'RATEGURU_RECOVERYPREFLIGHT_PSQL_DIRECT' => 'true',
        'RATEGURU_RECOVERYPREFLIGHT_SOURCE_REGISTRY' => $registryPath,
        'RATEGURU_RECOVERYPREFLIGHT_TARGETS_CLI_BIN' => $targetsPath,
        'RGTEST_ACCOUNTS_DIR' => $scratch.'/accounts',
        'RGTEST_PSQL_DIR' => $scratch,
    ], $overrides);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $overrides
 * @return array{exit: int, output: string}
 */
function preflightRun(string $scratch, array $arguments = ['--check', '--target', 'parity-target', '--bootstrap-user', 'rateguru-bootstrap', '--environment', 'staging'], array $overrides = []): array
{
    [$exit, $output] = runInfraScript(preflightScript(), $arguments, preflightEnv($scratch, $overrides));

    return ['exit' => $exit, 'output' => $output];
}

/**
 * Every path under the scratch host with its kind and content: the proof that
 * a run — passing or refusing — changed nothing on the machine.
 *
 * @return array<string, string>
 */
function preflightHostSnapshot(string $scratch): array
{
    $root = $scratch.'/fs';
    $snapshot = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $path => $info) {
        $snapshot[substr($path, strlen($root))] = match (true) {
            $info->isLink() => 'link:'.readlink($path),
            $info->isDir() => 'dir',
            default => 'file:'.md5_file($path),
        };
    }

    ksort($snapshot);

    return $snapshot;
}

/** The plain-text form of `--help` and `--operator-guide` from a real checkout. */
function preflightFromCheckout(array $arguments): string
{
    [$exit, $output] = runInfraScript(preflightScript(), $arguments, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);

    expect($exit)->toBe(0, $output);

    return $output;
}

// =============================================================================
// The verdicts
// =============================================================================

it('passes a clean Ubuntu 22.04 x86_64 machine, counting every absent service as what a clean host looks like', function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);
        $before = preflightHostSnapshot($scratch);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RateGuru recovery host preflight (read-only)')
            ->toContain('Target: parity-target')
            ->toContain('target parity-target is lifecycle=active')
            ->toContain('PASS     os-release — ubuntu 22.04')
            ->toContain('PASS     architecture — x86_64')
            ->toContain('PASS     rateguru-root — /home/www/rateguru absent')
            ->toContain('PASS     releases — none')
            ->toContain('PASS     guards — none')
            ->toContain('PASS     application-root — '.$scratch.'/target absent')
            ->toContain('PASS     deploy-homes — none')
            ->toContain('PASS     users — none')
            ->toContain('PASS     groups — none')
            ->toContain('PASS     nginx — not installed')
            ->toContain('PASS     php-fpm — not installed')
            ->toContain('PASS     supervisor — not installed')
            ->toContain('PASS     cron — none')
            ->toContain('PASS     systemd — none')
            ->toContain('PASS     sudo-wrappers — none')
            ->toContain('PASS     sudoers — none')
            ->toContain('PASS     postgresql — not installed')
            ->toContain("SUMMARY\nPASS: 17\nREFUSED: 0")
            ->toContain('RECOVERY PREFLIGHT: PASS — x86_64 is a supported, clean replacement host for parity-target; nothing was changed')
            ->toContain('RATEGURU_RECOVERY_PREFLIGHT={"result":"pass","cause":"","state":"pristine","target":"parity-target","refusals":0}');

        expect(str_contains($result['output'], 'REFUSED '))->toBeFalse('a clean host refuses nothing');
        expect(str_contains($result['output'], 'ACTION REQUIRED'))->toBeFalse('a passing preflight asks nothing of the operator');

        expect(preflightHostSnapshot($scratch))->toBe($before, 'the preflight reads the host and changes nothing');
    } finally {
        removeScratchDir($scratch);
    }
});

it('accepts both spellings of the supported architecture', function (string $arch) {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        $result = preflightRun($scratch, overrides: ['RATEGURU_RECOVERYPREFLIGHT_ARCH' => $arch]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain("PASS     architecture — {$arch}");
    } finally {
        removeScratchDir($scratch);
    }
})->with(['x86_64', 'amd64']);

it('refuses a machine that already carries the RateGuru tree, naming the release and the guards it found, and removes nothing', function () {
    $scratch = restoreScratchDir();

    try {
        $fs = preflightCleanHost($scratch);

        mkdir($fs.'/home/www/rateguru/staging/releases/20260101-000000-abcdef', 0o755, true);
        symlink('releases/20260101-000000-abcdef', $fs.'/home/www/rateguru/staging/current');
        mkdir($fs.'/home/www/rateguru/run/recoveries/staging-main', 0o755, true);
        file_put_contents($fs.'/home/www/rateguru/run/recoveries/staging-main/recovery-guard', "{\"operation\":\"20260115-041233-9be21c\"}\n");
        file_put_contents($fs.'/home/www/rateguru/run/offsite-write-hold', "{\"hold\":\"offsite-writes\"}\n");
        $before = preflightHostSnapshot($scratch);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('REFUSED  rateguru-root — /home/www/rateguru already exists (holds: run staging)')
            ->toContain('REFUSED  releases — a deployed or partially deployed target is present: staging/current staging/releases')
            ->toContain('REFUSED  guards — a data operation owns this target on this machine: run/recoveries/staging-main/recovery-guard')
            ->toContain('never started again')
            ->toContain('REFUSED: 3')
            ->toContain('RECOVERY PREFLIGHT: REFUSED — this machine is neither a genuinely clean replacement host for parity-target nor a recovery preparation of it to converge; nothing was changed')
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: a data operation already owns parity-target on this machine (see the REFUSED lines above)')
            ->toContain('a recovery that reached its own --apply holds the target with a guard and an operation ID')
            ->toContain('never remove a guard or the offsite-write hold by hand to make a start pass')
            ->toContain('for a held recovery, re-run "Recover staging host" with mode=continue-held and the operation ID from that run\'s summary')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            ->toContain('"result":"refused"')
            // The guard is what an operator must act on first: a held recovery
            // is continued, never started again.
            ->toContain('"cause":"operation-in-progress"');

        // Nothing was removed or "cleaned up": the tree, the guard and the
        // hold are exactly as they were. That is the whole point of a refusal.
        expect(preflightHostSnapshot($scratch))->toBe($before);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses RateGuru-managed service and configuration state by name, wherever a clean image would have none', function () {
    $scratch = restoreScratchDir();

    try {
        $fs = preflightCleanHost($scratch);

        foreach ([
            '/etc/nginx/sites-available/rateguru-staging',
            '/etc/nginx/sites-enabled/rateguru-staging',
            '/etc/nginx/rateguru-staging.htpasswd',
            '/etc/nginx/sites-enabled/default',
            '/etc/php/8.3/fpm/pool.d/rateguru-staging.conf',
            '/etc/php/8.3/fpm/pool.d/www.conf',
            '/etc/supervisor/conf.d/rateguru-staging-queue.conf',
            '/etc/cron.d/rateguru-staging-scheduler',
            '/etc/cron.d/e2scrub_all',
            '/etc/systemd/system/rateguru-staging-mailpit.service',
            '/usr/local/sbin/rateguru-deploy',
            '/etc/sudoers.d/rateguru-deploy',
            '/etc/sudoers.d/90-cloud-init-users',
            '/home/deploy-rateguru-staging/.ssh/authorized_keys',
        ] as $path) {
            @mkdir(dirname($fs.$path), 0o755, true);
            file_put_contents($fs.$path, "managed\n");
        }

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('PASS     rateguru-root — /home/www/rateguru absent')
            ->toContain('REFUSED  deploy-homes — deploy account homes exist: deploy-rateguru-staging')
            ->toContain('REFUSED  nginx — RateGuru-managed Nginx state exists: rateguru-staging rateguru-staging.htpasswd')
            ->toContain('REFUSED  php-fpm — RateGuru PHP-FPM pools exist: rateguru-staging.conf')
            ->toContain('REFUSED  supervisor — RateGuru Supervisor programs exist: rateguru-staging-queue.conf')
            ->toContain('REFUSED  cron — RateGuru cron entries exist: rateguru-staging-scheduler')
            ->toContain('REFUSED  systemd — RateGuru-managed units exist: rateguru-staging-mailpit.service')
            ->toContain('REFUSED  sudo-wrappers — RateGuru sudo wrappers exist: rateguru-deploy')
            ->toContain('REFUSED  sudoers — RateGuru sudoers grants exist: rateguru-deploy')
            ->toContain('"cause":"host-not-clean"');

        // The distribution's own files are not RateGuru state.
        foreach (['default', 'www.conf', 'e2scrub_all', '90-cloud-init-users'] as $foreign) {
            expect(str_contains($result['output'], $foreign))->toBeFalse("{$foreign} is not RateGuru state and must not be named");
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it("refuses RateGuru accounts and groups, including the target's own identities read from the registry", function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        // A prefixed account, and the registry's own deploy user under a name
        // the prefix would never catch.
        file_put_contents($scratch.'/accounts/passwd', "rateguru-staging:x:1001:1001::/nonexistent:/usr/sbin/nologin\nparity-deploy:x:1002:1002::/home/parity-deploy:/bin/bash\n", FILE_APPEND);
        file_put_contents($scratch.'/accounts/group', "rateguru-staging-code:x:1003:\n", FILE_APPEND);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('REFUSED  users — RateGuru accounts exist: parity-deploy rateguru-staging')
            ->toContain('REFUSED  groups — RateGuru groups exist: rateguru-staging-code')
            ->toContain('"cause":"host-not-clean"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses an unsupported distribution or release, and names the one supported', function (string $osRelease, string $expected) {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);
        file_put_contents($scratch.'/os-release', $osRelease);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain($expected)
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: the operating system is not ubuntu 22.04')
            ->toContain('  1. provision a new VPS from a ubuntu 22.04 image (x86_64/amd64)')
            ->toContain('Then: re-run "Recover staging host" with mode=start against the new machine')
            ->toContain('"cause":"unsupported-os"');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'Debian 12' => ["ID=debian\nVERSION_ID=\"12\"\n", 'REFUSED  os-release — debian 12 is not supported; a clean-host recovery needs ubuntu 22.04 exactly'],
    'Ubuntu 24.04' => ["NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nID=ubuntu\n", 'REFUSED  os-release — ubuntu 24.04 is not supported; a clean-host recovery needs ubuntu 22.04 exactly'],
    'Ubuntu 20.04' => ["ID=ubuntu\nVERSION_ID=\"20.04\"\n", 'REFUSED  os-release — ubuntu 20.04 is not supported; a clean-host recovery needs ubuntu 22.04 exactly'],
    'an unreadable os-release' => ['', 'the supported baseline is ubuntu 22.04'],
]);

it('refuses an unsupported architecture', function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        $result = preflightRun($scratch, overrides: ['RATEGURU_RECOVERYPREFLIGHT_ARCH' => 'aarch64']);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('PASS     os-release — ubuntu 22.04')
            ->toContain('REFUSED  architecture — aarch64 is not supported; a clean-host recovery needs one of: x86_64 amd64')
            ->toContain('Cause: the architecture is aarch64, not one of: x86_64 amd64')
            ->toContain('  1. provision a new x86_64 VPS from a ubuntu 22.04 image')
            ->toContain('"cause":"unsupported-architecture"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('asks PostgreSQL only when a client exists, asks read-only questions, and refuses when the server cannot answer', function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        // A client and a server with nothing RateGuru-shaped: a pass.
        preflightInstallPsql($scratch, ['postgres', 'template1', 'other_app'], ['postgres', 'other_app']);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('PASS     databases — none')
            ->toContain('PASS     roles — none');

        $queries = array_filter(explode("\n", File::get($scratch.'/psql.log')));
        expect($queries)->not->toBeEmpty();
        foreach ($queries as $query) {
            expect($query)->toStartWith('SELECT ');
        }

        // The target's own database and role — under the registry's names —
        // and a prefixed role.
        preflightInstallPsql($scratch, ['postgres', 'parity_db', 'rateguru_staging'], ['postgres', 'parity_app', 'rateguru_staging_app']);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('REFUSED  databases — RateGuru databases exist: parity_db rateguru_staging')
            ->toContain('REFUSED  roles — RateGuru database roles exist: parity_app rateguru_staging_app')
            ->toContain('"cause":"host-not-clean"');

        // A client whose server does not answer: the question cannot be
        // proven either way, and an unprovable "clean" is a refusal.
        preflightInstallPsql($scratch, down: true);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('REFUSED  postgresql — a PostgreSQL client is installed but the server did not answer — this preflight cannot prove that no RateGuru database exists on this machine')
            ->toContain('"cause":"host-not-clean"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a planned target before it examines the machine at all', function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);
        $before = preflightHostSnapshot($scratch);

        $result = preflightRun($scratch, ['--check', '--target', 'planned-target']);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: target planned-target has lifecycle=planned, not active')
            ->toContain('What this means: a recovery rebuilds a target that is already live somewhere; a planned target has nothing to recover and must not be provisioned as a side effect')
            ->toContain('  1. do not prepare, recover or provision anything for planned-target on this machine')
            ->toContain('Then: activate the target deliberately, in its own registry change, before any recovery of it')
            ->toContain('ERROR: target planned-target has lifecycle=planned, not active — a clean-host recovery is refused before any host mutation');

        // No check ran and no verdict was reached: the refusal came first.
        expect(str_contains($result['output'], 'SUPPORTED HOST'))->toBeFalse();
        expect(str_contains($result['output'], 'RECOVERY PREFLIGHT:'))->toBeFalse();
        expect(preflightHostSnapshot($scratch))->toBe($before);
    } finally {
        removeScratchDir($scratch);
    }
});

it('proves the lifecycle itself only where the registry is readable, refuses a registry it cannot judge, and otherwise says who proved it', function () {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        // On a clean host only the script was uploaded: no registry beside
        // it. The lifecycle was proven by the workflow, and the script says so.
        $result = preflightRun($scratch, overrides: ['RATEGURU_RECOVERYPREFLIGHT_SOURCE_REGISTRY' => $scratch.'/no-such-registry.json']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('Registry: not readable beside this script — the target lifecycle was proven by the caller from the committed registry')
            ->toContain('RECOVERY PREFLIGHT: PASS');
        expect(str_contains($result['output'], 'application-root'))->toBeFalse('without a registry there is no target-specific root to check');

        // A registry beside the script that cannot be read (no jq) is a
        // refusal, never a shrug: the script will not guess a lifecycle.
        $tools = $scratch.'/no-jq-bin';
        mkdir($tools, 0o755, true);
        foreach (['bash', 'dirname', 'uname', 'basename', 'sed', 'head', 'tr', 'grep', 'sort', 'cut', 'cat'] as $tool) {
            $real = trim((string) shell_exec('command -v '.$tool));
            if ($real !== '') {
                symlink($real, $tools.'/'.$tool);
            }
        }

        $result = preflightRun($scratch, overrides: ['PATH' => $tools]);

        expect($result['exit'])->toBe(1);
        expect($result['output'])->toContain('is beside this script but jq is not available to read it — run the preflight through the workflow, which proves the target lifecycle on the runner');
        expect(str_contains($result['output'], 'RECOVERY PREFLIGHT:'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to run unprivileged and refuses a malformed request', function (array $arguments, array $overrides, string $expected) {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);

        $result = preflightRun($scratch, $arguments, $overrides);

        expect($result['exit'])->toBe(1);
        expect($result['output'])->toContain($expected);
        expect(str_contains($result['output'], 'RATEGURU_RECOVERY_PREFLIGHT='))->toBeFalse('a refused request reaches no verdict');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'not root' => [['--check', '--target', 'parity-target'], ['RATEGURU_RECOVERYPREFLIGHT_EUID' => '1000'], 'recovery-host-preflight must run as root'],
    'a malformed bootstrap user' => [['--check', '--target', 'parity-target', '--bootstrap-user', 'Root User'], [], 'invalid bootstrap user: Root User'],
    'a malformed environment' => [['--check', '--target', 'parity-target', '--environment', 'prod'], [], 'invalid environment: prod'],
    'a malformed recovery backup' => [['--check', '--target', 'parity-target', '--recovery-backup', 'latest'], [], '--recovery-backup requires an exact offsite backup timestamp'],
    'a repeated bootstrap user' => [['--check', '--target', 'parity-target', '--bootstrap-user', 'a', '--bootstrap-user', 'b'], [], '--bootstrap-user given more than once'],
    'no mode' => [['--target', 'parity-target'], [], 'one of --check or --operator-guide is required'],
    'both modes' => [['--check', '--operator-guide', '--target', 'parity-target'], [], 'exactly one of --check or --operator-guide is required'],
    'no target' => [['--check'], [], '--target is required'],
    'a malformed target' => [['--check', '--target', 'Staging Main'], [], 'invalid target ID: Staging Main'],
    'an unknown target' => [['--check', '--target', 'no-such-target'], [], 'unknown target: no-such-target'],
    'a flag where the target should be' => [['--check', '--target', '--operator-guide'], [], '--target requires a value, not another option'],
    'an unknown argument' => [['--check', '--target', 'parity-target', '--force'], [], 'unknown argument: --force'],
]);

// =============================================================================
// What it is made of
// =============================================================================

it('pins its supported host contract to the bootstrap installers, so it accepts exactly the machines they accept', function () {
    $preflight = File::get(preflightScript());

    foreach ([
        'bootstrap-host-preflight' => ['SUPPORTED_OS_ID="ubuntu"', 'SUPPORTED_OS_VERSION_ID="22.04"'],
        'install-bootstrap-runtime' => ['SUPPORTED_OS_ID="ubuntu"', 'SUPPORTED_OS_VERSION_ID="22.04"', 'SUPPORTED_ARCHITECTURES="x86_64 amd64"'],
    ] as $installer => $lines) {
        $source = File::get(base_path('infrastructure/scripts/'.$installer));

        foreach ($lines as $line) {
            expect($source)->toContain($line);
            expect($preflight)->toContain($line);
        }
    }
});

it('is read-only by construction: no mutating command, no redirection into the host, only SELECT questions to PostgreSQL', function () {
    $source = executableSourceLines(File::get(preflightScript()));

    // No command that installs, writes, removes, starts or repoints anything
    // appears at a command position — not even behind sudo.
    expect($source)->not->toMatch('/(^|[|;&(]|\$\(|\bthen\b|\bdo\b|\belse\b)\s*(sudo\s+(-n\s+)?)?(rm|rmdir|mkdir|install|chmod|chown|chgrp|mv|cp|ln|touch|tee|truncate|apt|apt-get|dpkg|snap|systemctl|service|supervisorctl|nginx|useradd|userdel|usermod|groupadd|groupdel|createdb|dropdb|createuser|dropuser|rclone|git|curl|wget)(\s|$)/m');

    // Nothing is redirected anywhere but stderr and /dev/null. (A `>` that
    // closes an <IP>-style placeholder in operator text is not a redirection.)
    expect(preg_replace('/<[a-zA-Z][a-zA-Z -]*>/', '', $source))->not->toMatch('/(?<![&\d<-])>>?(?!&|\/dev\/null)/');

    // PostgreSQL is only ever asked; nothing is created, dropped or altered.
    // Every question goes through psql_query, and every question is a SELECT.
    expect($source)->not->toMatch('/psql_query "(?!SELECT )/');
    expect(substr_count($source, 'psql_query "SELECT '))->toBe(4);
    expect(substr_count($source, '-c "$1"'))->toBe(2);
    expect($source)->not->toMatch('/-c "(?!\$1")/');

    // No file content is ever printed: the only cat is a heredoc.
    expect($source)->not->toMatch('/\bcat\s+(?!<<)/');
});

it('never reads the registry to decide what a target is on the host: the caller does, from the committed registry', function () {
    $action = File::get(base_path('.github/actions/recovery-host-preflight/action.yml'));

    // The action uploads exactly one file, and it is this script.
    expect($action)
        ->toContain('script="${GITHUB_WORKSPACE}/infrastructure/scripts/recovery-host-preflight"')
        ->toContain('"${BOOTSTRAP_USER}@${RECOVERY_HOST}:${remote_dir}/recovery-host-preflight"');
    expect(str_contains(executableSourceLines($action), 'deployment-targets.json"'))->toBeTrue('the lifecycle is read on the runner');
    expect(substr_count($action, 'scp \\'))->toBe(1);
});

it('explains itself: --help names the runbook, and --operator-guide states the exact GitHub value set, the real hostname and the dispatch', function () {
    $help = preflightFromCheckout(['--help']);

    expect($help)
        ->toContain('recovery-host-preflight --check --target TARGET_ID')
        ->toContain('recovery-host-preflight --operator-guide [--target TARGET_ID]')
        ->toContain('Read-only.')
        ->toContain('Ubuntu 22.04 exactly')
        ->toContain('Operator runbook: infrastructure/runbooks/clean-host-recovery.md');

    // From a checkout the real registry and jq are readable, so the guide
    // names the real hostname of the real target.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    $hostname = $registry['targets']['staging-main']['public_hostnames'][0];

    expect($hostname)->toBe('rateguru.staging.myprojects.pp.ua');

    $guide = preflightFromCheckout(['--operator-guide', '--target', 'staging-main']);

    expect($guide)
        ->toContain('CLEAN-HOST RECOVERY — OPERATOR GUIDE (compact; the full runbook is infrastructure/runbooks/clean-host-recovery.md)')
        ->toContain('A NEW VPS from a ubuntu 22.04 image, x86_64 or amd64')
        ->toContain('Install ONLY the public half of RECOVERY_BOOTSTRAP_SSH_KEY')
        ->toContain("ssh -i <recovery key> -o IdentitiesOnly=yes RECOVERY_BOOTSTRAP_USER@<IP> 'id -u'")
        ->toContain('ssh-keyscan -t ed25519 -p 22 <IP>')
        ->toContain('vars.RECOVERY_BOOTSTRAP_USER')
        ->toContain('secrets.RECOVERY_BOOTSTRAP_SSH_KEY')
        ->toContain('secrets.RECOVERY_KNOWN_HOSTS')
        ->toContain('secrets.RECOVERY_RCLONE_CONFIG')
        ->toContain('NO PREPARE_* value is read by a recovery. Do NOT change DEPLOY_HOST. Do NOT change DNS.')
        ->toContain('schema 3')
        ->toContain('exact timestamp YYYYMMDD-HHMMSS')
        ->toContain('recovery-material.tar.gz and a release.json with a full 40-character source_sha')
        ->toContain('passed the offsite restore test')
        ->toContain('mode=start   backup=YYYYMMDD-HHMMSS   replacement-host=<IP>   replacement-port=22')
        ->toContain("curl --resolve {$hostname}:443:<IP> https://{$hostname}/up")
        ->toContain("(public hostnames of this target: {$hostname})")
        ->toContain('OFFSITE WRITES: HELD')
        ->toContain('mode=continue-held')
        ->toContain('Full runbook: infrastructure/runbooks/clean-host-recovery.md');

    // Exactly the values the recovery workflow reads: no value it does not
    // read, and none of the PREPARE_* values a recovery never reads.
    $read = recoveryValuesRead('recover-staging.yml')['all'];

    preg_match_all('/\b((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', $guide, $named);
    $named = array_values(array_unique($named[1]));
    sort($named);

    expect($named)->toBe($read);
    expect($guide)->not->toMatch('/PREPARE_[A-Z]/');

    // Without a target, the guide still names every active target's hostname.
    expect(preflightFromCheckout(['--operator-guide']))->toContain("(public hostnames of this target: {$hostname})");
});

// =============================================================================
// The bootstrap access a clean replacement host necessarily already has
// =============================================================================

it('accepts the recovery bootstrap account, its group and its sudoers grant on an otherwise untouched machine', function () {
    $scratch = restoreScratchDir();

    try {
        // Exactly the machine the first real recovery met: a fresh VPS with
        // the out-of-band access installed and nothing else.
        preflightCleanHost($scratch);

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('Bootstrap user: rateguru-bootstrap (the out-of-band access this machine is reached through)')
            ->toContain('PASS     users — none')
            ->toContain('PASS     groups — none')
            ->toContain('PASS     sudoers — none')
            ->toContain('RECOVERY PREFLIGHT: PASS')
            ->toContain('"state":"pristine"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('exempts only that exact account, and refuses every other RateGuru identity or grant', function (array $accounts, array $files, string $expected) {
    $scratch = restoreScratchDir();

    try {
        $fs = preflightCleanHost($scratch);

        foreach ($accounts as $database => $line) {
            file_put_contents($scratch.'/accounts/'.$database, $line, FILE_APPEND);
        }

        foreach ($files as $path => $contents) {
            @mkdir(dirname($fs.$path), 0o755, true);
            file_put_contents($fs.$path, $contents);
        }

        $result = preflightRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain($expected)
            ->toContain('"cause":"host-not-clean"');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'the target runtime account' => [
        ['passwd' => "rateguru-staging:x:1101:1101::/nonexistent:/usr/sbin/nologin\n"],
        [],
        'REFUSED  users — RateGuru accounts exist: rateguru-staging',
    ],
    'the target deploy account' => [
        ['passwd' => "deploy-rateguru-staging:x:1102:1102::/home/deploy-rateguru-staging:/bin/bash\n"],
        [],
        'REFUSED  users — RateGuru accounts exist: deploy-rateguru-staging',
    ],
    'another bootstrap-shaped account' => [
        ['passwd' => "rateguru-bootstrap-old:x:1103:1103::/home/rateguru-bootstrap-old:/bin/bash\n"],
        [],
        'REFUSED  users — RateGuru accounts exist: rateguru-bootstrap-old',
    ],
    'another RateGuru group' => [
        ['group' => "rateguru-staging-code:x:1104:\n"],
        [],
        'REFUSED  groups — RateGuru groups exist: rateguru-staging-code',
    ],
    'another RateGuru sudoers grant' => [
        [],
        ['/etc/sudoers.d/rateguru-deploy' => "deploy-rateguru-staging ALL=(ALL) NOPASSWD:/usr/local/sbin/rateguru-deploy\n"],
        'REFUSED  sudoers — RateGuru sudoers grants exist: rateguru-deploy',
    ],
]);

it("refuses outright when the bootstrap user is the target's own runtime or deploy identity", function (string $user) {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch, ['bootstrap_account' => $user]);

        $result = preflightRun($scratch, ['--check', '--target', 'parity-target', '--bootstrap-user', $user, '--environment', 'staging']);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain("Cause: the recovery bootstrap user is {$user}, which is parity-target's own runtime or deploy identity")
            ->toContain('set RECOVERY_BOOTSTRAP_USER to root, or to a dedicated bootstrap account')
            ->toContain("ERROR: the recovery bootstrap user {$user} is parity-target's own runtime or deploy identity");

        // Refused before a single check ran: no verdict, no state, nothing read.
        expect(str_contains($result['output'], 'SUPPORTED HOST'))->toBeFalse();
        expect(str_contains($result['output'], 'RATEGURU_RECOVERY_PREFLIGHT='))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'the deploy user' => ['parity-deploy'],
    'the runtime user' => [trim((string) shell_exec('id -un'))],
]);

// =============================================================================
// A start that re-enters a preparation it already made
// =============================================================================

/**
 * The machine an earlier `mode=start` left behind: prepared (or part way
 * there) for one exact target and backup, with the offsite-write hold
 * prepare-host places and nothing deployed.
 */
function preflightPreparedHost(string $scratch, array $options = []): string
{
    $fs = preflightCleanHost($scratch, $options);

    // Everything Prepare Host installs, which a pristine check would refuse.
    mkdir($fs.'/home/www/rateguru/staging/releases', 0o755, true);
    mkdir($fs.'/home/www/rateguru/staging/shared', 0o755, true);
    file_put_contents($fs.'/home/www/rateguru/staging/shared/.env', "APP_ENV=staging\n");
    mkdir($fs.'/home/www/rateguru/run', 0o755, true);
    mkdir($fs.'/etc/nginx/sites-available', 0o755, true);
    file_put_contents($fs.'/etc/nginx/sites-available/rateguru-staging', "server {}\n");
    file_put_contents($fs.'/etc/cron.d/rateguru-staging-scheduler', "* * * * * root true\n");
    file_put_contents($scratch.'/accounts/passwd', "rateguru-staging:x:1201:1201::/nonexistent:/usr/sbin/nologin\n", FILE_APPEND);

    file_put_contents(
        $fs.'/home/www/rateguru/run/offsite-write-hold',
        json_encode($options['hold'] ?? [
            'hold' => 'offsite-writes',
            'reason' => 'host-recovery',
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'created_by' => 'prepare-host --recovery-backup',
            'created_at' => '2026-01-15T04:00:00Z',
        ]),
    );

    return $fs;
}

/** @param list<string> $extra */
function preflightStartRun(string $scratch, array $extra = [], string $backup = '20260115-023000'): array
{
    return preflightRun($scratch, array_merge(
        ['--check', '--target', 'parity-target', '--bootstrap-user', 'rateguru-bootstrap', '--environment', 'staging', '--recovery-backup', $backup],
        $extra,
    ));
}

it('recognises the machine an earlier start of this exact recovery already prepared, and converges it rather than refusing it', function () {
    $scratch = restoreScratchDir();

    try {
        // RUN 1 left this behind: Prepare Host succeeded and recover-host
        // refused before it created an operation, so no operation ID exists
        // and continue-held is not available. RUN 2 is a mode=start.
        preflightPreparedHost($scratch);
        $before = preflightHostSnapshot($scratch);

        $result = preflightStartRun($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('Backup: 20260115-023000')
            ->toContain('PASS     releases — none')
            ->toContain('PASS     guards — none')
            ->toContain('an earlier start of this recovery prepared this machine for parity-target from backup 20260115-023000')
            ->toContain('Prepare Host is convergent and this start converges it again')
            ->toContain('RECOVERY PREFLIGHT: PASS — this machine is the one an earlier start of this recovery already prepared')
            ->toContain('"state":"recovery-preparation-retry"');

        // Read-only here too: the hold that proves it is not touched.
        expect(preflightHostSnapshot($scratch))->toBe($before);
    } finally {
        removeScratchDir($scratch);
    }
});

it('accepts a preparation only for the exact recovery it was made for', function (array $options, array $extra, string $backup, string $expected) {
    $scratch = restoreScratchDir();

    try {
        preflightPreparedHost($scratch, $options);

        foreach ($extra as $path => $contents) {
            @mkdir(dirname($scratch.'/fs'.$path), 0o755, true);
            file_put_contents($scratch.'/fs'.$path, $contents);
        }

        $result = preflightStartRun($scratch, backup: $backup);

        expect($result['exit'])->toBe(1);
        expect($result['output'])->toContain($expected);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // The hold authorises one recovery, not the machine in general.
    'a different backup' => [
        [],
        [],
        '20260220-030000',
        'REFUSED  rateguru-root — /home/www/rateguru already exists',
    ],
    'a hold for another target' => [
        ['hold' => ['hold' => 'offsite-writes', 'reason' => 'host-recovery', 'target' => 'other-target', 'backup' => '20260115-023000']],
        [],
        '20260115-023000',
        'REFUSED  rateguru-root — /home/www/rateguru already exists',
    ],
    'a hold placed for something other than a recovery' => [
        ['hold' => ['hold' => 'offsite-writes', 'reason' => 'operator', 'target' => 'parity-target', 'backup' => '20260115-023000']],
        [],
        '20260115-023000',
        'this one carries no recovery preparation for parity-target from backup 20260115-023000',
    ],
]);

it('refuses a prepared machine that is serving code, or that a data operation already owns', function (array $paths, string $expected, string $cause) {
    $scratch = restoreScratchDir();

    try {
        $fs = preflightPreparedHost($scratch);

        foreach ($paths as $path => $contents) {
            @mkdir(dirname($fs.$path), 0o755, true);

            if ($contents === null) {
                mkdir($fs.$path, 0o755, true);

                continue;
            }

            file_put_contents($fs.$path, $contents);
        }

        $result = preflightStartRun($scratch);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain($expected)
            ->toContain("\"cause\":\"{$cause}\"");
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a deployed release' => [
        ['/home/www/rateguru/staging/current' => "not-a-symlink\n"],
        'REFUSED  releases — a deployed or partially deployed target is present: staging/current',
        'host-not-clean',
    ],
    'a previous release' => [
        ['/home/www/rateguru/staging/previous' => "not-a-symlink\n"],
        'REFUSED  releases — a deployed or partially deployed target is present: staging/previous',
        'host-not-clean',
    ],
    'a release inside releases/' => [
        ['/home/www/rateguru/staging/releases/20260115-010000-abcdef' => null],
        'REFUSED  releases — a deployed or partially deployed target is present: staging/releases',
        'host-not-clean',
    ],
    'a recovery already holding the target' => [
        ['/home/www/rateguru/run/recoveries/parity-target/recovery-guard' => "{\"operation\":\"20260115-041233-9be21c\"}\n"],
        'a recovery that is already held is continued with mode=continue-held and its operation ID, never started again',
        'operation-in-progress',
    ],
    'a live restore holding the target' => [
        ['/home/www/rateguru/run/restores/parity-target/restore-guard' => "{\"operation\":\"20260115-041233-9be21c\"}\n"],
        'REFUSED  guards — a data operation owns this target on this machine',
        'operation-in-progress',
    ],
]);

it('proves nothing from a preparation it cannot tie to this exact start', function () {
    $scratch = restoreScratchDir();

    try {
        preflightPreparedHost($scratch);

        // No --recovery-backup: the start named no backup to compare, so the
        // hold authorises nothing and the machine is judged as it stands.
        $result = preflightRun($scratch, ['--check', '--target', 'parity-target', '--bootstrap-user', 'rateguru-bootstrap', '--environment', 'staging']);

        expect($result['exit'])->toBe(1);
        expect($result['output'])
            ->toContain('REFUSED  rateguru-root — /home/www/rateguru already exists')
            ->toContain('"state":"pristine"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('names the workflow of the environment it was asked about', function (string $environment, string $expected) {
    $scratch = restoreScratchDir();

    try {
        preflightCleanHost($scratch);
        file_put_contents($scratch.'/os-release', "ID=debian\nVERSION_ID=\"12\"\n");

        $result = preflightRun($scratch, ['--check', '--target', 'parity-target', '--bootstrap-user', 'rateguru-bootstrap', '--environment', $environment]);

        expect($result['exit'])->toBe(1);
        expect($result['output'])->toContain("Then: re-run \"{$expected}\" with mode=start against the new machine");
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['staging', 'Recover staging host'],
    ['production', 'Recover production host'],
]);
