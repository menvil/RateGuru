<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/configure-target — the one operation that gives an
 * already-provisioned, still-PLANNED production target the three things it
 * needs to be able to run: its environment file, its deploy key and its
 * database.
 *
 * What makes it worth its own operation is what it refuses to do. It does not
 * activate the target, grant it deploy authorization, deploy anything, or
 * create the directories it installs into. Lifecycle is permission to OPERATE
 * a target, and a target that has just been given secrets and data has not
 * earned that — so it stays planned, and every test here is ultimately about
 * that boundary holding.
 *
 * The children are stubbed because they are not what is under test: each owns
 * its own contract and has its own suite. What is under test is the
 * orchestration — which child is asked what, in which order, with which
 * authorization, and what happens when one of them says no.
 */
function configureScratchDir(): string
{
    $dir = sys_get_temp_dir().'/configure-target-'.uniqid('', true).'-'.getmypid();

    @mkdir($dir.'/bin', 0o755, true);
    @mkdir($dir.'/log', 0o755, true);
    @mkdir($dir.'/toggles', 0o755, true);
    // The machine's lock directory. A real host has it because the bootstrap
    // created it; this operation never creates one, which is asserted below.
    @mkdir($dir.'/run', 0o755, true);

    return $dir;
}

function configureCleanup(string $scratch): void
{
    exec('rm -rf '.escapeshellarg($scratch));
}

function configureScript(): string
{
    return base_path('infrastructure/scripts/configure-target');
}

function configureWriteStub(string $path, string $body): void
{
    file_put_contents($path, $body."\n");
    chmod($path, 0o755);
}

/**
 * One stub per child. Each records every invocation and answers from a
 * toggle, so a test can prove both what was asked and what was never asked.
 *
 * @return array<string, string>
 */
function configureFixture(string $scratch, array $options = []): array
{
    foreach (['provision-target', 'install-target-prerequisites', 'install-target-database'] as $child) {
        configureWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"

            if [[ -e "${STUB_TOGGLES}/${me}-abort" ]]; then
                echo "ERROR: ${me} aborted"
                exit 1
            fi

            # Faithful to the two real installers on the one point this
            # orchestrator's ordering depends on: the database installer reads
            # the target's environment file for its credentials and ABORTS when
            # it is absent, in every mode including --check; and the material
            # installer is what puts that file there.
            if [[ "${me}" == install-target-database ]] && [[ ! -f "${STUB_SHARED_ENV}" ]]; then
                echo "ERROR: target environment file is missing: ${STUB_SHARED_ENV} — external secret material, never generated here"
                exit 1
            fi

            if [[ "${me}" == install-target-prerequisites ]] && [[ "$*" == *--apply* ]]; then
                mkdir -p "$(dirname "${STUB_SHARED_ENV}")"
                touch "${STUB_SHARED_ENV}"
            fi

            if [[ -e "${STUB_TOGGLES}/${me}-hostreq" ]]; then
                echo "  HOST-REQ something:${me} — a host prerequisite is not satisfied"
                exit 1
            fi

            case "$*" in
                *--check*)
                    [[ -e "${STUB_TOGGLES}/${me}-satisfied" ]] && exit 0
                    echo "  MISSING material:${me} — not there yet"
                    exit 1
                    ;;
                *--apply*)
                    [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]] && { echo "ERROR: ${me} apply failed"; exit 1; }
                    touch "${STUB_TOGGLES}/${me}-satisfied"
                    exit 0
                    ;;
                *--verify*)
                    [[ -e "${STUB_TOGGLES}/${me}-satisfied" ]] && exit 0
                    exit 1
                    ;;
            esac
            exit 0
            STUB);
    }

    foreach ($options['satisfied'] ?? ['provision-target'] as $child) {
        touch($scratch.'/toggles/'.$child.'-satisfied');
    }

    // The canonical environment file, where the installers compose its path:
    // FS_ROOT + the registry's application_root + /shared/.env. The operator
    // creates it on the host before configuring, so it is present by default
    // here; 'noEnv' is the not-yet-created case. Its CONTENT is nothing —
    // configure-target never opens it, and the installer that does is stubbed.
    if (! ($options['noEnv'] ?? false)) {
        $shared = $scratch.'/home/www/rateguru/production/tits-guru/shared';

        @mkdir($shared, 0o755, true);
        touch($shared.'/.env');
    }

    foreach ($options['toggles'] ?? [] as $toggle) {
        touch($scratch.'/toggles/'.$toggle);
    }

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_CONFIGURE_EUID' => $options['euid'] ?? '0',
        'RATEGURU_CONFIGURE_FS_ROOT' => $scratch,
        'RATEGURU_HOST_LOCK_ROOT' => $options['lockRoot'] ?? $scratch.'/run',
        'RATEGURU_CONFIGURE_PROVISION_BIN' => $scratch.'/bin/provision-target',
        'RATEGURU_CONFIGURE_PREREQUISITES_BIN' => $scratch.'/bin/install-target-prerequisites',
        'RATEGURU_CONFIGURE_DATABASE_BIN' => $scratch.'/bin/install-target-database',
        'STUB_LOG' => $scratch.'/log',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_SHARED_ENV' => $scratch.'/home/www/rateguru/production/tits-guru/shared/.env',
    ];
}

/** @return array{0: int, 1: string} */
function configureRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', configureScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function configureLog(string $scratch, string $name = 'children.log'): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

// =============================================================================
// The lifecycle gate
// =============================================================================

it('configures a planned production target and refuses every other shape', function (
    string $target,
    string $expected,
) {
    $scratch = configureScratchDir();

    try {
        [$exit, $output] = configureRun(['--check', '--target', $target], configureFixture($scratch));

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);

        // A refused target is never asked about: no child ran at all.
        expect(configureLog($scratch))->toBe('');
    } finally {
        configureCleanup($scratch);
    }
})->with([
    'an active target' => ['staging-main', 'lifecycle=active'],
    'an unknown target' => ['no-such-target', 'unknown target'],
]);

it('never writes the registry, so a configured target is still planned', function () {
    // The whole reason this is not "activate": configuring gives a target
    // secrets and data, and permission to operate it is a separate, reviewed
    // registry change that nothing here performs.
    $before = File::get(base_path('infrastructure/config/deployment-targets.json'));

    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['satisfied' => ['provision-target']]);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('lifecycle unchanged: tits-guru is still lifecycle=planned')
            ->toContain('LIFECYCLE: planned');

        expect(File::get(base_path('infrastructure/config/deployment-targets.json')))->toBe($before);
    } finally {
        configureCleanup($scratch);
    }
});

// =============================================================================
// The structural contract it builds on
// =============================================================================

it('refuses before any mutation when the target is not provisioned', function () {
    // Material is installed INTO directories and accounts provisioning
    // created, and a database is created FOR a runtime user it created. A
    // target that is not provisioned would be half-configured instead.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['satisfied' => []]);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the target is not provisioned')
            ->toContain('No material was installed and no database was created')
            ->toContain('installs material into directories and accounts provisioning creates');

        // It asked provision-target, and asked nothing else.
        $children = configureLog($scratch);

        expect($children)->toContain('provision-target --verify --target tits-guru');
        expect($children)->not->toContain('install-target-prerequisites');
        expect($children)->not->toContain('install-target-database');
    } finally {
        configureCleanup($scratch);
    }
});

it('proves the structural contract through its owner rather than re-checking it', function () {
    // One implementation of "is this target provisioned", and it is not here.
    $scratch = configureScratchDir();

    try {
        [, $output] = configureRun(['--check', '--target', 'tits-guru'], configureFixture($scratch));

        expect($output)->toContain('provision-target --verify passes');
        expect(configureLog($scratch))->toContain('provision-target --verify --target tits-guru');
    } finally {
        configureCleanup($scratch);
    }
});

// =============================================================================
// Delegation, and the authorization it delegates under
// =============================================================================

it('asks each installer for exactly its own contract, under the provisioning authorization', function () {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(0, $output);

        $children = configureLog($scratch);

        // Material: target scope only. A target that is not operating yet has
        // no business deciding what the host's TLS or mail material should be,
        // and a production target has no committed vhost to resolve at all.
        expect($children)->toContain('install-target-prerequisites --apply --scope target --provisioning --target tits-guru');
        expect($children)->not->toContain('--scope host');
        expect($children)->not->toContain('--scope all');

        // Database: the same authorization, and nothing else.
        expect($children)->toContain('install-target-database --apply --provisioning --target tits-guru');

        // Material before database: the database reads its credentials out of
        // the environment file, so the order is a dependency, not a preference.
        expect(mb_strpos($children, 'install-target-prerequisites --apply'))
            ->toBeLessThan(mb_strpos($children, 'install-target-database --apply'));

        // And both were verified again afterwards, independently of the apply.
        expect($children)
            ->toContain('install-target-prerequisites --verify --scope target --provisioning --target tits-guru')
            ->toContain('install-target-database --verify --provisioning --target tits-guru');
    } finally {
        configureCleanup($scratch);
    }
});

it('stops at the first failing child and never runs the next one', function () {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['toggles' => ['install-target-prerequisites-apply-fail']]);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('no database step ran');
        expect(configureLog($scratch))->not->toContain('install-target-database --apply');
    } finally {
        configureCleanup($scratch);
    }
});

it('refuses as a whole before mutating when a child reports a host prerequisite', function () {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['toggles' => ['install-target-database-hostreq']]);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('No mutation was performed');
        expect(configureLog($scratch))->not->toContain('--apply');
    } finally {
        configureCleanup($scratch);
    }
});

// =============================================================================
// The machine-readable result, and what a success is allowed to claim
// =============================================================================

it('prints exactly one result line that says what is still not true', function () {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(0, $output);

        // A target that has just been given secrets and a database is exactly
        // when somebody assumes it is ready. The success says otherwise.
        expect($output)
            ->toContain('TARGET CONFIGURATION: CONFIGURED')
            ->toContain('DEPLOY AUTHORIZATION: NOT GRANTED')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED');

        expect(substr_count($output, 'RATEGURU_CONFIGURE_RESULT='))->toBe(1);

        preg_match('/^RATEGURU_CONFIGURE_RESULT=(.*)$/m', $output, $matches);
        $result = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        expect($result)->toBe([
            'status' => 'target-configured',
            'target' => 'tits-guru',
            'lifecycle' => 'planned',
            'environment_class' => 'production',
            'environment_state' => 'installed',
            'database_state' => 'ready',
            'deploy_authorization' => 'not-granted',
            'application_state' => 'not-deployed',
            'public_state' => 'not-activated',
        ]);
    } finally {
        configureCleanup($scratch);
    }
});

it('prints no result line when it did not finish', function () {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['satisfied' => []]);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)->not->toContain('RATEGURU_CONFIGURE_RESULT=');
        expect($output)->not->toContain('TARGET CONFIGURATION: CONFIGURED');
    } finally {
        configureCleanup($scratch);
    }
});

it('requires root in every working mode', function (string $mode) {
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['euid' => '1000']);

        [$exit, $output] = configureRun([$mode, '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('must be executed as root');
        expect(configureLog($scratch))->toBe('');
    } finally {
        configureCleanup($scratch);
    }
})->with(['--check', '--apply', '--verify']);

// =============================================================================
// Source guards: an orchestrator, never a second owner
// =============================================================================

it('implements no material, permission or database mechanism of its own', function (string $forbidden) {
    // It orchestrates owners. A second implementation of any of these is how
    // the two drift until one of them is quietly wrong.
    expect(str_contains(executableSourceLines(File::get(configureScript())), $forbidden))
        ->toBeFalse("configure-target must not implement: {$forbidden}");
})->with([
    // identities and permissions
    'useradd', 'usermod', 'groupadd', 'chown', 'chmod', 'setfacl', 'visudo', 'sudoers',
    // database
    'psql', 'createdb', 'createuser', 'dropdb', 'CREATE ROLE', 'CREATE DATABASE',
    // application
    'artisan', 'migrate', 'composer', 'supervisorctl', 'systemctl',
    // public surface
    'certbot', 'letsencrypt', 'server_name', 'nginx',
    // data
    'rclone', 'pg_dump', 'rm -rf',
]);

it('never reads, prints or measures the credentials it causes to be installed', function () {
    // The environment file is the target's most sensitive file. This operation
    // causes it to be validated and used, and never opens it.
    $source = executableSourceLines(File::get(configureScript()));

    foreach (['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'wc -c', 'sha256sum', 'md5sum', 'cat "${SHARED_ENV'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('claims the machine before it judges anything, and never creates the lock directory', function () {
    // Targets share a host. Configuring creates a database and installs into
    // root-owned trees, so it holds the machine for the whole run — and a host
    // that has never been bootstrapped has no lock directory, which is a
    // refusal rather than something to create.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['lockRoot' => $scratch.'/nonexistent']);

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the operational lock directory does not exist')
            ->toContain('never creates it');

        expect(is_dir($scratch.'/nonexistent'))->toBeFalse();
        expect(configureLog($scratch))->toBe('');
    } finally {
        configureCleanup($scratch);
    }
});

it('refuses while another operation holds the machine, and changes nothing', function () {
    // Never waits: a run that blocked silently would look like a hang and get
    // cancelled halfway through somebody else's mutation.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch);
        $lock = $scratch.'/run/host-infrastructure.lock';

        touch($lock);

        // Hold it from another process, the way a concurrent operation would,
        // and wait for the holder to say it HAS the lock. A fixed sleep here
        // would be a race on a loaded CI runner: too short and the lock is not
        // held yet, so the run under test succeeds and the test passes for the
        // wrong reason — proving nothing while looking green.
        $holder = proc_open(
            ['bash', '-c', 'exec 9>>"$1"; flock -n 9 || exit 1; echo held; sleep 30', '_', $lock],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        expect($holder)->not->toBeFalse();
        expect(rtrim((string) fgets($pipes[1])))->toBe('held', 'the holder process never acquired the lock');

        [$exit, $output] = configureRun(['--apply', '--target', 'tits-guru'], $env);

        proc_terminate($holder);
        proc_close($holder);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain("another operation is already mutating this host's shared infrastructure")
            ->toContain('changed nothing');

        expect(configureLog($scratch))->toBe('');
    } finally {
        configureCleanup($scratch);
    }
});

it('lets a read-only mode run while another operation holds the machine', function (string $mode) {
    // The other half of one-owner-per-lock, and the reason it is proved by
    // running rather than by reading: --apply asks provision-target --verify a
    // question WHILE holding the machine. If a read-only mode took the lock,
    // that child would wedge against its own orchestrator — a deadlock no
    // source-level assertion about where the call sits would catch.
    $scratch = configureScratchDir();

    try {
        // A fully configured target, so the read-only verdict is 0 and the
        // only thing that could fail the run is the lock itself.
        $env = configureFixture($scratch, [
            'satisfied' => ['provision-target', 'install-target-prerequisites', 'install-target-database'],
        ]);

        $lock = $scratch.'/run/host-infrastructure.lock';

        touch($lock);

        $holder = proc_open(
            ['bash', '-c', 'exec 9>>"$1"; flock -n 9 || exit 1; echo held; sleep 30', '_', $lock],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        expect($holder)->not->toBeFalse();
        expect(rtrim((string) fgets($pipes[1])))->toBe('held', 'the holder process never acquired the lock');

        [$exit, $output] = configureRun([$mode, '--target', 'tits-guru'], $env);

        proc_terminate($holder);
        proc_close($holder);

        // It ran to a verdict instead of refusing, and never claimed the
        // machine it had no business claiming.
        expect($exit)->toBe(0, $output);
        expect($output)->not->toContain('already mutating this host');
        expect(configureLog($scratch))->toContain('provision-target --verify --target tits-guru');
    } finally {
        configureCleanup($scratch);
    }
})->with(['--check', '--verify']);

it('refuses before any mutation when the canonical environment file is absent', function () {
    // The decision this encodes: shared/.env is a PRECONDITION of configuring,
    // never an outcome of it. An operator creates it on the host once, backups
    // carry it and a recovery restores it — so a configure run that could
    // bring one into existence would be a second way for the most sensitive
    // file on the host to be written.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['noEnv' => true]);

        [$exit, $output] = configureRun(
            ['--apply', '--target', 'tits-guru', '--material-dir', $scratch.'/material'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('its canonical environment file')
            ->toContain('never creates, seeds or overwrites it')
            ->toContain('No material was installed and no database was created');

        // Refused as a whole, before the installers ran at all — not after the
        // deploy key was already in place.
        expect(configureLog($scratch))->not->toContain('--apply');
    } finally {
        configureCleanup($scratch);
    }
});

it('refuses a material directory that carries an environment file', function () {
    // The directory is built by the action and holds one public key. An
    // environment file in it would be installed by the authoritative
    // installer, which is exactly the path this operation does not have — so
    // it is refused by name rather than left to documentation.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch);

        @mkdir($scratch.'/material', 0o700, true);
        file_put_contents($scratch.'/material/laravel-env', "APP_KEY=irrelevant\n");

        [$exit, $output] = configureRun(
            ['--apply', '--target', 'tits-guru', '--material-dir', $scratch.'/material'],
            $env,
        );

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the material directory carries laravel-env')
            ->toContain('nothing was changed');

        // Refused before the registry was even consulted, so no child ran.
        expect(configureLog($scratch))->toBe('');
    } finally {
        configureCleanup($scratch);
    }
});

it('installs the deploy public key from the supplied material', function () {
    // The one thing configuring does install. Provisioning deliberately left
    // the deploy user without an authorized_keys, and nothing else in the
    // pipeline creates one.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch);

        @mkdir($scratch.'/material', 0o700, true);
        file_put_contents($scratch.'/material/deploy-authorized-keys', "ssh-ed25519 AAAA deploy\n");

        [$exit, $output] = configureRun(
            ['--apply', '--target', 'tits-guru', '--material-dir', $scratch.'/material'],
            $env,
        );

        expect($exit)->toBe(0, $output);

        // Handed to the installer that owns reading it, untouched.
        expect(configureLog($scratch))
            ->toContain('--material-dir '.$scratch.'/material');

        expect($output)->toContain('DEPLOY KEY: INSTALLED');
    } finally {
        configureCleanup($scratch);
    }
});

it('reports the canonical environment file as its own precondition', function (bool $present, string $status) {
    // Reported as a separate line rather than folded into the material step:
    // an operator reading "MISSING material" cannot tell from that alone which
    // half they must act on, and the two halves have opposite answers — the
    // deploy key is supplied to this operation, the environment file never is.
    $scratch = configureScratchDir();

    try {
        $env = configureFixture($scratch, ['noEnv' => ! $present]);

        [, $output] = configureRun(['--check', '--target', 'tits-guru'], $env);

        expect($output)->toMatch('/^\s+'.$status.'\s+environment:canonical /m');
    } finally {
        configureCleanup($scratch);
    }
})->with([
    'present' => [true, 'PASS'],
    'absent' => [false, 'MISSING'],
]);

it('is registered as a required CLI and ships executable', function () {
    expect(File::get(base_path('infrastructure/config/required-clis.txt')))
        ->toContain("configure-target\n");

    expect(is_executable(configureScript()))->toBeTrue();
});
