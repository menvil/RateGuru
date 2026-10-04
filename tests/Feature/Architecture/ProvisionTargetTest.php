<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/provision-target — create the non-secret
 * infrastructure of ONE planned production target on an already-bootstrapped
 * RateGuru host.
 *
 * Every behavioural test here executes the real, shipped orchestrator AND the
 * real, shipped installers it delegates to, as subprocesses, against a fully
 * simulated host: fixture passwd/group files, a fixture filesystem root every
 * canonical path is mapped onto, a layered stat stub that reads real types and
 * modes but fixture ownership, and logging install/chown/chmod/groupadd/
 * useradd/usermod/systemctl/supervisorctl stubs that perform the real work
 * inside the scratch directory while recording every invocation. Nothing here
 * is a reimplementation, and nothing here needs root.
 *
 * The target under test is `demo-shop`: a synthetic production brand that
 * exists nowhere in this repository except in the fixture registry. That is
 * the point — provisioning has to be generic, and a mechanism that only works
 * for tits-guru would pass a tits-guru test and fail the first real second
 * brand. The fixture also carries an ACTIVE staging target and a SECOND
 * planned production target, so isolation is proved against real neighbours
 * rather than against an empty host.
 *
 * This file holds the operation: the whole provisioning end to end on the
 * synthetic brand, the queue program that is safe to load before the first
 * deployment, the CLI surface, the deploy perimeter it leaves untouched and
 * the source guards that keep it an orchestrator. What provision-target
 * refuses before it mutates anything — the lifecycle, the host, the shared
 * namespace, the bundle the host must agree with — is in
 * ProvisionTargetPreconditionsTest; the simulated host they share (the
 * fixture registry, the stubs, the run) lives in tests/Pest.php.
 */

// =============================================================================
// Harness: the helpers only this file uses — the simulated host, the run and
// the stubs it shares with ProvisionTargetPreconditionsTest are in tests/Pest.php
// =============================================================================

function provisionSource(): string
{
    return File::get(provisionScript());
}

/**
 * Everything about `demo-shop` a converged host would have, for the
 * idempotency and drift scenarios that start from an already-provisioned
 * target rather than an empty one.
 */
function provisionSnapshotDemoState(string $scratch): array
{
    $fs = $scratch.'/fs';
    $snapshot = [];

    foreach ([
        '/home/www/rateguru/production/demo-shop',
        '/home/deploy-rateguru-demo-shop',
        '/etc/nginx/sites-available/rateguru-demo-shop',
        '/etc/nginx/sites-enabled/rateguru-demo-shop',
        '/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf',
        '/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf',
        '/etc/cron.d/rateguru-demo-shop-scheduler',
    ] as $logical) {
        $snapshot += provisionTreeSnapshot($fs.$logical);
    }

    return $snapshot;
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function provisionTreeSnapshot(string $path): array
{
    if (! file_exists($path) && ! is_link($path)) {
        return [];
    }

    if (is_link($path)) {
        return [$path => 'link:'.readlink($path)];
    }

    if (is_file($path)) {
        return [$path => md5_file($path).':'.substr(sprintf('%o', fileperms($path)), -4)];
    }

    $snapshot = [$path => 'dir:'.substr(sprintf('%o', fileperms($path)), -4)];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if (is_link($entryPath)) {
            $snapshot[$entryPath] = 'link:'.readlink($entryPath);
        } elseif ($entry->isFile()) {
            $snapshot[$entryPath] = md5_file($entryPath).':'.substr(sprintf('%o', fileperms($entryPath)), -4);
        } else {
            $snapshot[$entryPath] = 'dir:'.substr(sprintf('%o', fileperms($entryPath)), -4);
        }
    }

    ksort($snapshot);

    return $snapshot;
}

/**
 * The one machine-readable line, decoded.
 *
 * @return array<string, mixed>
 */
function provisionResultLine(string $output): array
{
    $matches = [];
    preg_match_all('/^RATEGURU_PROVISION_RESULT=(.*)$/m', $output, $matches);

    expect($matches[1])->toHaveCount(1, "expected exactly one RATEGURU_PROVISION_RESULT line:\n{$output}");

    return json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
}

// =============================================================================
// Genericity: the whole operation, end to end, on a synthetic brand
// =============================================================================

it('reports a planned production target as unprovisioned, and names every mutation an apply would make', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, "an unprovisioned target must not report as provisioned:\n{$output}");

        expect($output)
            ->toContain('RateGuru provision target (check mode)')
            ->toContain('target:demo-shop — lifecycle=planned, environment_class=production')
            ->toContain('TARGET INFRASTRUCTURE: NOT PROVISIONED')
            ->toContain('MISSING  layout:install-bootstrap-host-layout')
            ->toContain('MISSING  services:install-bootstrap-services')
            ->toContain('install-bootstrap-host-layout --apply --target demo-shop --provisioning')
            ->toContain('install-bootstrap-services --apply --target demo-shop --provisioning');

        // The host it runs on is already a RateGuru host, and says so — the
        // operational bundle it has installed is the one this bundle expects,
        // down to the runtime registry every decision here was read from.
        expect($output)
            ->toContain('PASS     host:install-bootstrap-runtime')
            ->toContain('PASS     host:install-target-operations')
            ->toContain('PASS     host:runtime-registry')
            ->toContain('PASS     state:demo-shop — no deployment-owned state');

        // Everything a later phase owns is named rather than silently absent.
        expect($output)
            ->toContain('DEFERRED deferred:database')
            ->toContain('DEFERRED deferred:environment')
            ->toContain('DEFERRED deferred:deploy-authorization')
            ->toContain('DEFERRED deferred:public-routing')
            ->toContain('DEFERRED deferred:queue-runtime');

        // Strictly read-only: not one mutation of any kind.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
        expect(provisionLog($scratch, 'systemctl.log'))
            ->not->toContain('systemctl enable')
            ->not->toContain('systemctl start')
            ->not->toContain('systemctl reload');
    } finally {
        provisionCleanup($scratch);
    }
});

it('provisions the whole target from the registry alone, and proves every generic value reached its config', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';
        $root = $fs.'/home/www/rateguru/production/demo-shop';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('PROVISION TARGET: demo-shop')
            ->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->toContain('LIFECYCLE: planned')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED')
            ->toContain('SECRETS: DEFERRED')
            ->toContain('DATABASE: DEFERRED')
            ->toContain('QUEUE: DEFERRED')
            // The one thing a success line must never claim.
            ->not->toContain('PRODUCTION READY');

        $result = provisionResultLine($output);
        expect($result)->toBe([
            'status' => 'infrastructure-provisioned',
            'target' => 'demo-shop',
            'lifecycle' => 'planned',
            'environment_class' => 'production',
            'application_state' => 'not-deployed',
            'public_state' => 'not-activated',
        ]);

        // --- identities -----------------------------------------------------
        $group = (string) file_get_contents($fs.'/etc-group');
        $passwd = (string) file_get_contents($fs.'/etc-passwd');

        expect($group)
            ->toMatch('/^rateguru-demo-shop:x:\d+:/m')
            ->toMatch('/^rateguru-demo-shop-code:x:\d+:.*rateguru-demo-shop/m')
            ->toMatch('/^rateguru-demo-shop-code:x:\d+:.*www-data/m')
            ->toMatch('/^deploy-rateguru-demo-shop:x:\d+:/m');

        // www-data joins the CODE group and never a runtime group.
        expect($group)->not->toMatch('/^rateguru-demo-shop:x:\d+:.*www-data/m');

        expect($passwd)
            ->toMatch('#^rateguru-demo-shop:x:\d+:\d+::[^:]*:/usr/sbin/nologin$#m')
            ->toMatch('#^deploy-rateguru-demo-shop:x:\d+:\d+::/home/deploy-rateguru-demo-shop:/bin/bash$#m');

        // --- filesystem, with the proven staging boundaries -----------------
        $rows = provisionOwnerTableRows($scratch);
        $mode = fn (string $path): string => substr(sprintf('%o', fileperms($path)), -4);

        $expected = [
            $root => ['root', 'root', '0755'],
            $root.'/releases' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $root.'/shared' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/shared/storage' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/shared/storage/logs' => ['rateguru-demo-shop', 'rateguru-demo-shop', '2770'],
            $root.'/locks' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $root.'/deployments' => ['deploy-rateguru-demo-shop', 'rateguru-demo-shop-code', '2750'],
            $fs.'/home/deploy-rateguru-demo-shop' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0750'],
            $fs.'/home/deploy-rateguru-demo-shop/.ssh' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0700'],
            $fs.'/home/deploy-rateguru-demo-shop/incoming' => ['deploy-rateguru-demo-shop', 'deploy-rateguru-demo-shop', '0750'],
        ];

        foreach ($expected as $path => [$owner, $ownerGroup, $expectedMode]) {
            expect(is_dir($path))->toBeTrue("missing provisioned directory: {$path}");
            expect($rows[$path] ?? null)->toBe([$owner, $ownerGroup], "wrong ownership on {$path}");
            expect($mode($path))->toBe($expectedMode, "wrong mode on {$path}");
        }

        // Deployment-owned paths are never fabricated.
        expect(file_exists($root.'/current'))->toBeFalse('provisioning must never create current');
        expect(file_exists($root.'/previous'))->toBeFalse('provisioning must never create previous');
        expect(is_link($root.'/current'))->toBeFalse();
        expect(scandir($root.'/releases'))->toBe(['.', '..'], 'provisioning must never create a release');

        // --- the rendered service configuration -----------------------------
        $nginx = (string) file_get_contents($fs.'/etc/nginx/sites-available/rateguru-demo-shop');
        expect($nginx)
            ->toContain('server_name demo-shop.internal;')
            ->toContain('root /home/www/rateguru/production/demo-shop/current/public;')
            ->toContain('fastcgi_pass unix:/run/php/rateguru-demo-shop.sock;')
            ->toContain('allow 127.0.0.1;')
            ->toContain('deny all;');

        // The public half of the registry never reaches the rendered vhost.
        expect($nginx)
            ->not->toContain('demo-shop.example')
            ->not->toContain('listen 443')
            ->not->toContain('ssl_certificate')
            ->not->toContain('letsencrypt')
            ->not->toContain('return 301');

        expect(is_link($fs.'/etc/nginx/sites-enabled/rateguru-demo-shop'))->toBeTrue();

        $pool = (string) file_get_contents($fs.'/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf');
        expect($pool)
            ->toContain('[rateguru-demo-shop]')
            ->toContain('user = rateguru-demo-shop')
            ->toContain('group = rateguru-demo-shop')
            ->toContain('listen = /run/php/rateguru-demo-shop.sock')
            ->toContain('/home/www/rateguru/production/demo-shop/shared/storage/logs/php-fpm-error.log');

        $supervisor = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');
        expect($supervisor)
            ->toContain('[program:rateguru-demo-shop-queue]')
            // The working directory is the application root, which exists the
            // moment provisioning finishes; current/ is entered by the command
            // once it is really there. See the PRE_DEPLOY-safety tests below.
            ->toContain('directory=/home/www/rateguru/production/demo-shop'."\n")
            ->toContain('cd /home/www/rateguru/production/demo-shop/current')
            ->toContain('--queue=rateguru-demo-shop ')
            ->toContain('user=rateguru-demo-shop')
            ->toContain('environment=APP_ENV="production"');

        $cron = (string) file_get_contents($fs.'/etc/cron.d/rateguru-demo-shop-scheduler');
        expect($cron)
            // Guarded on current/ existing, because a provisioned target has
            // no release yet and cron mails root whatever a job writes: an
            // unguarded cd would send a failure a minute from here until the
            // first deployment.
            ->toContain('rateguru-demo-shop [ -d /home/www/rateguru/production/demo-shop/current ] || exit 0;')
            ->toContain('cd /home/www/rateguru/production/demo-shop/current')
            ->toContain('/usr/bin/php8.5 artisan schedule:run');

        // Nothing rendered mentions the target this repository happens to ship
        // a planned entry for: the mechanism is generic, not a brand installer.
        foreach ([$nginx, $pool, $supervisor, $cron] as $rendered) {
            expect($rendered)->not->toContain('tits');
        }

        // --- what provisioning must never have done -------------------------
        // The queue worker is configured and deliberately not started.
        expect(provisionLog($scratch, 'supervisorctl.log'))
            ->toContain('supervisorctl reread')
            ->not->toContain('supervisorctl start')
            ->not->toContain('supervisorctl update');
        expect($output)->toContain('activation DEFERRED until the first release exists');

        // No database, no secret material, no offsite credential. The two
        // installers that would create them are never reachable from here at
        // all: neither the orchestrator nor the service installer names them.
        expect(file_exists($root.'/shared/.env'))->toBeFalse();
        expect(file_exists($fs.'/home/deploy-rateguru-demo-shop/.ssh/authorized_keys'))->toBeFalse();

        foreach ([
            'infrastructure/scripts/provision-target',
            'infrastructure/scripts/install-bootstrap-services',
            'infrastructure/scripts/install-bootstrap-host-layout',
        ] as $script) {
            expect(executableSourceLines(File::get(base_path($script))))
                ->not->toContain('install-target-database')
                ->not->toContain('install-target-prerequisites');
        }

        // Host-wide families are never converged by a target-scoped run.
        $children = provisionLog($scratch, 'children.log');
        expect($children)
            ->not->toContain('operations-installer --apply')
            ->not->toContain('perimeter-installer --apply')
            ->not->toContain('mail-capture-installer --apply');

        // No base service was enabled or started: this host was already
        // prepared, and provisioning never starts one.
        $systemctl = provisionLog($scratch, 'systemctl.log');
        expect($systemctl)
            ->not->toContain('systemctl enable')
            ->not->toContain('systemctl start')
            ->not->toContain('systemctl restart');
        expect($systemctl)->toContain('systemctl reload');
    } finally {
        provisionCleanup($scratch);
    }
});

it('verifies a provisioned target read-only, and a second apply converges nothing', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $afterFirstApply = provisionSnapshotDemoState($scratch);
        $identityLog = provisionLog($scratch, 'identity.log');

        // --- --verify --------------------------------------------------------
        [$verifyExit, $verifyOutput] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($verifyExit)->toBe(0, $verifyOutput);
        expect($verifyOutput)
            ->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->toContain('LIFECYCLE: planned')
            ->toContain('APPLICATION: NOT DEPLOYED')
            ->toContain('PUBLIC TRAFFIC: NOT ACTIVATED')
            ->toContain('PASS     layout:install-bootstrap-host-layout')
            ->toContain('PASS     services:install-bootstrap-services');

        expect(provisionResultLine($verifyOutput)['status'])->toBe('infrastructure-provisioned');
        expect(provisionSnapshotDemoState($scratch))->toBe($afterFirstApply, '--verify must change nothing');

        // --- second --apply --------------------------------------------------
        [$secondExit, $secondOutput] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($secondExit)->toBe(0, $secondOutput);
        expect($secondOutput)
            ->toContain('CHANGED: FALSE')
            ->toContain("nothing to converge — this target's infrastructure is already provisioned");

        expect(provisionSnapshotDemoState($scratch))
            ->toBe($afterFirstApply, 'a second apply on a correct target must perform zero meaningful mutation');

        expect(provisionLog($scratch, 'identity.log'))
            ->toBe($identityLog, 'a second apply must create no account, group or membership');
    } finally {
        provisionCleanup($scratch);
    }
});

it('leaves the live staging target byte-identical while a new production target is built beside it', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';

        $stagingBefore = provisionTreeSnapshot($fs.'/home/www/rateguru/staging')
            + provisionTreeSnapshot($fs.'/home/deploy-rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-available/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-enabled/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf')
            + provisionTreeSnapshot($fs.'/etc/supervisor/conf.d/rateguru-staging-queue.conf')
            + provisionTreeSnapshot($fs.'/etc/cron.d/rateguru-staging-scheduler');

        $ownersBefore = array_filter(
            provisionOwnerTableRows($scratch),
            fn (string $path): bool => str_contains($path, 'staging'),
            ARRAY_FILTER_USE_KEY,
        );

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        $stagingAfter = provisionTreeSnapshot($fs.'/home/www/rateguru/staging')
            + provisionTreeSnapshot($fs.'/home/deploy-rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-available/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/nginx/sites-enabled/rateguru-staging')
            + provisionTreeSnapshot($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf')
            + provisionTreeSnapshot($fs.'/etc/supervisor/conf.d/rateguru-staging-queue.conf')
            + provisionTreeSnapshot($fs.'/etc/cron.d/rateguru-staging-scheduler');

        expect($stagingAfter)->toBe($stagingBefore, 'provisioning a new target must not touch the live one');

        $ownersAfter = array_filter(
            provisionOwnerTableRows($scratch),
            fn (string $path): bool => str_contains($path, 'staging'),
            ARRAY_FILTER_USE_KEY,
        );

        expect($ownersAfter)->toBe($ownersBefore, 'no staging path may be re-owned');

        // Its identities keep every membership they had, and gain none.
        $group = (string) file_get_contents($fs.'/etc-group');
        expect($group)->toContain('rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data');
        expect($group)->toMatch('/^rateguru-staging:x:5001:$/m');

        // The staging queue was never touched, and its scheduler cron is
        // exactly the committed file it always was.
        expect(provisionLog($scratch, 'supervisorctl.log'))->not->toContain('rateguru-staging-queue');
        expect(file_get_contents($fs.'/etc/cron.d/rateguru-staging-scheduler'))
            ->toBe(File::get(base_path('infrastructure/config/cron/rateguru-staging-scheduler')));

        // A host-service reload is the only permitted side effect, and it is
        // not a mutation of the staging target: additive configuration has to
        // be picked up somehow.
        $systemctl = provisionLog($scratch, 'systemctl.log');
        foreach (explode("\n", trim($systemctl)) as $line) {
            if ($line === '' || str_contains($line, 'is-enabled') || str_contains($line, 'is-active')) {
                continue;
            }

            expect($line)->toMatch('/^systemctl reload (nginx|php8\.5-fpm)$/', "unexpected service mutation: {$line}");
        }
    } finally {
        provisionCleanup($scratch);
    }
});

it('produces configuration a later active-mode run accepts without rewriting a byte', function () {
    $scratch = provisionScratchDir();

    try {
        // Provision while planned...
        $env = provisionFixture($scratch, ['widenActiveAllowlist' => true]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        $installed = provisionSnapshotDemoState($scratch);

        // ...then activate the target in the registry, exactly as a later,
        // deliberate registry change would, and ask the ORDINARY target-scoped
        // installers — no --provisioning anywhere — whether it is correct.
        $repo = $scratch.'/repo';
        file_put_contents(
            $repo.'/infrastructure/config/deployment-targets.json',
            provisionRegistryJson([], 'active'),
        );

        [$layoutExit, $layoutOutput] = provisionRun(
            ['--verify', '--target', 'demo-shop'],
            $env,
            $repo.'/infrastructure/scripts/install-bootstrap-host-layout',
        );

        expect($layoutExit)->toBe(0, "an activated provisioned target must satisfy the ordinary layout contract:\n{$layoutOutput}");
        expect($layoutOutput)->toContain('lifecycle=active — provisioned by this slice');

        [$servicesExit, $servicesOutput] = provisionRun(
            ['--verify', '--target', 'demo-shop'],
            $env,
            $repo.'/infrastructure/scripts/install-bootstrap-services',
        );

        expect($servicesExit)->toBe(0, "an activated provisioned target must satisfy the ordinary services contract:\n{$servicesOutput}");
        expect($servicesOutput)
            ->toContain('TARGET SERVICES CONTRACT (demo-shop): SATISFIED')
            ->toContain('byte-identical to its source')
            // The renderer produced these files; the active-mode run renders
            // the same bytes and therefore reports no drifted item at all.
            ->toContain('DRIFT: 0')
            ->not->toContain('DRIFT    ');

        expect(provisionSnapshotDemoState($scratch))
            ->toBe($installed, 'activating the target must not require rewriting anything provisioning installed');
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// A queue program that is safe to load before the first deployment
// =============================================================================
//
// Provisioning installs the queue program and deliberately does not add it to
// the running supervisor. That is not enough on its own: the file sits in
// supervisord's own configuration directory, and a supervisord restart or a
// host reboot loads it whether anybody asked or not. A planned production
// target can wait weeks for its first deployment, so the CONFIGURATION has to
// be the thing that is safe, not the sequence of commands that installed it.
//
// The tests below run the program's real command line and then apply
// supervisord's own documented decision rule to the result, so "no crash loop"
// is a computed outcome rather than a comment.

/**
 * The command supervisord would spawn, ready to run here.
 *
 * Two substitutions, both the same fixture translation every probe in this
 * file performs: the canonical RateGuru root becomes the fixture's, and the
 * template PHP binary — an absolute path no test host has — becomes a stub
 * that records how it was called. The guard's logic, its exit codes and its
 * argv are the shipped ones.
 */
function provisionQueueCommand(string $config, string $fs, string $phpStub): string
{
    $pattern = '/^command=\/bin\/bash -c \'(.*)\'$/m';

    expect($config)->toMatch($pattern);

    preg_match($pattern, $config, $matches);

    return str_replace(
        ['/home/www/rateguru', '/usr/bin/php8.5'],
        [$fs.'/home/www/rateguru', $phpStub],
        $matches[1],
    );
}

/**
 * supervisord's decision after a program exits, from its documented rules:
 *
 *   - an exit before startsecs never made it out of STARTING, so supervisord
 *     backs off and retries regardless of the exit code;
 *   - otherwise autorestart=unexpected restarts only codes outside exitcodes,
 *     autorestart=true restarts everything, autorestart=false restarts nothing.
 *
 * @param  list<int>  $exitcodes
 */
function supervisorOutcome(int $code, float $elapsed, float $startsecs, string $autorestart, array $exitcodes): string
{
    if ($elapsed < $startsecs) {
        return 'BACKOFF';
    }

    return match ($autorestart) {
        'unexpected' => in_array($code, $exitcodes, true) ? 'EXITED' : 'RESTART',
        'true' => 'RESTART',
        default => 'EXITED',
    };
}

it('installs a queue program that a supervisord restart can load before the first deployment', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $config = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');

        // The policy supervisord reads.
        expect($config)
            ->toContain("autostart=true\n")
            ->toContain("autorestart=unexpected\n")
            ->toContain("exitcodes=99\n")
            ->toContain("startsecs=3\n")
            // directory= must be a path that exists on a target with no
            // release: supervisord chdirs there before spawning, and a missing
            // one is a spawn error no guard in the command could catch.
            ->toContain("directory=/home/www/rateguru/production/demo-shop\n");

        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop'))->toBeTrue();
        expect(file_exists($fs.'/home/www/rateguru/production/demo-shop/current'))->toBeFalse();

        // Now actually run what supervisord would spawn, with no current.
        $phpStub = $scratch.'/bin/php-queue-probe';
        provisionWriteStub($phpStub, <<<'STUB'
            #!/bin/bash
            printf 'php %s\\n' "$*" >> "${STUB_LOG}/queue-worker.log"
            exit 0
            STUB);

        $command = provisionQueueCommand($config, $fs, $phpStub);

        $started = microtime(true);
        exec('STUB_LOG='.escapeshellarg($scratch.'/log').' bash -c '.escapeshellarg($command).' 2>&1', $output, $code);
        $elapsed = microtime(true) - $started;

        // Laravel was never invoked, and the program said "nothing to run"
        // rather than failing.
        expect(provisionLog($scratch, 'queue-worker.log'))->toBe('');
        expect($code)->toBe(99, 'the guard must exit with the code declared expected: '.implode('
', $output));

        // It outlived startsecs, so supervisord saw a successful start.
        expect($elapsed)->toBeGreaterThan(3.0);

        // Therefore: EXITED. Not BACKOFF, and not a restart.
        expect(supervisorOutcome($code, $elapsed, 3.0, 'unexpected', [99]))->toBe('EXITED');
    } finally {
        provisionCleanup($scratch);
    }
});

it('runs the real worker as soon as a release exists, and restarts it on its ordinary turnover', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';
        $root = $fs.'/home/www/rateguru/production/demo-shop';

        [$exit] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0);

        $config = (string) file_get_contents($fs.'/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf');

        // The first deployment: a release, and current pointing at it. The
        // configuration is NOT rewritten — the same file now means something
        // different because the host does.
        @mkdir($root.'/releases/20260101120000', 0o755, true);
        symlink($root.'/releases/20260101120000', $root.'/current');

        $phpStub = $scratch.'/bin/php-queue-probe';
        provisionWriteStub($phpStub, <<<'STUB'
            #!/bin/bash
            printf 'php %s\\n' "$*" >> "${STUB_LOG}/queue-worker.log"
            printf 'cwd %s\\n' "$(pwd -P)" >> "${STUB_LOG}/queue-worker.log"
            exit 0
            STUB);

        $command = provisionQueueCommand($config, $fs, $phpStub);

        exec('STUB_LOG='.escapeshellarg($scratch.'/log').' bash -c '.escapeshellarg($command).' 2>&1', $output, $code);

        $worker = provisionLog($scratch, 'queue-worker.log');

        expect($worker)
            ->toContain('artisan queue:work redis --queue=rateguru-demo-shop')
            ->toContain('--max-time=3600')
            ->toContain('cwd '.realpath($root.'/releases/20260101120000'));

        // The worker's own successful exit — what --max-time and --max-jobs
        // produce every hour — must bring it back, or a deployed target
        // silently stops processing its queue after the first turnover.
        expect($code)->toBe(0);
        expect(supervisorOutcome(0, 3600.0, 3.0, 'unexpected', [99]))->toBe('RESTART');

        // And a genuinely crash-looping worker still reaches BACKOFF, so the
        // startsecs/startretries backstop was not traded away for the guard.
        expect(supervisorOutcome(1, 0.2, 3.0, 'unexpected', [99]))->toBe('BACKOFF');

        // A host restart starts it again by itself.
        expect($config)->toContain("autostart=true\n");
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// CLI surface
// =============================================================================

it('documents the operation, its boundary and its machine-readable result', function () {
    // No fixture host: --help is answered from the committed tree, and the
    // only environment it needs is what sourcing `common` requires.
    [$exit, $output] = provisionRun(['--help'], [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => sys_get_temp_dir(),
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
        'RATEGURU_TARGETS_CLI' => base_path('infrastructure/scripts/targets'),
    ]);

    expect($exit)->toBe(0, $output);
    expect($output)
        ->toContain('provision-target --check  --target TARGET_ID')
        ->toContain('provision-target --apply  --target TARGET_ID')
        ->toContain('provision-target --verify --target TARGET_ID')
        ->toContain('All modes require root')
        ->toContain('lifecycle=planned AND environment_class=production')
        ->toContain('RATEGURU_PROVISION_RESULT=')
        ->toContain('never activates the target');
});

it('refuses to run without root, and refuses a request with no target', function (
    array $arguments,
    array $envOverrides,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $envOverrides);

        [$exit, $output] = provisionRun($arguments, $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'no root, apply' => [['--apply', '--target', 'demo-shop'], ['euid' => '1000'], '--apply must run as root'],
    'no root, check' => [['--check', '--target', 'demo-shop'], ['euid' => '1000'], '--check must run as root'],
    'no root, verify' => [['--verify', '--target', 'demo-shop'], ['euid' => '1000'], '--verify must run as root'],
    'no target' => [['--apply'], [], '--target is required'],
    'no mode' => [['--target', 'demo-shop'], [], 'one of --check, --apply or --verify is required'],
    'two modes' => [['--check', '--apply', '--target', 'demo-shop'], [], 'only one of --check, --apply or --verify may be given'],
    'an unknown flag' => [['--apply', '--target', 'demo-shop', '--force'], [], 'unknown argument: --force'],
]);

// =============================================================================
// The deploy perimeter is untouched by provisioning
// =============================================================================

it('leaves a provisioned target undeployable through the existing wrappers', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        // The infrastructure now exists, and the deploy account with it. The
        // wrapper still refuses, because require_active_target is unchanged and
        // provisioning never wrote the registry — knowing the deploy user's
        // name buys nobody anything.
        $harness = $scratch.'/deploy-wrapper-harness.sh';
        file_put_contents($harness, implode("\n", [
            'set -Eeuo pipefail',
            'source '.escapeshellarg(base_path('infrastructure/config/wrappers/rateguru-deploy')),
            'parse_wrapper_args --target demo-shop',
            'authorize_caller',
            'require_active_target "${TARGET_ID}"',
            'printf "REACHED-DEPLOY\n"',
            '',
        ]));

        $stub = $scratch.'/bin/stub-deploy';
        provisionWriteStub($stub, "#!/bin/bash\nprintf 'DEPLOY-RAN\\n' >> \"\${STUB_LOG}/deploy.log\"\n");

        [$wrapperExit, $wrapperOutput] = provisionRun([], array_merge($env, [
            'SUDO_USER' => 'deploy-rateguru-demo-shop',
            'RATEGURU_DEPLOY_BIN' => $stub,
            // The wrapper's own seam for the library it sources. It is an
            // installed-bundle caller, unlike provision-target, which reads
            // the one beside itself.
            'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        ]), $harness);

        expect($wrapperExit)->not->toBe(0);
        expect($wrapperOutput)
            ->toContain('target demo-shop has lifecycle=planned, not active')
            ->not->toContain('REACHED-DEPLOY');
        expect(provisionLog($scratch, 'deploy.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

// =============================================================================
// Source guards: an orchestrator, never a second owner
// =============================================================================

it('re-implements nothing an owning installer already does', function (string $construct) {
    expect(executableSourceLines(provisionSource()))
        ->not->toContain($construct, "provision-target must delegate rather than contain: {$construct}");
})->with([
    // identities and filesystem — install-bootstrap-host-layout
    'useradd', 'groupadd', 'usermod', 'userdel', 'groupdel', 'chown', 'chmod',
    // ACLs — install-public-storage-access
    'setfacl', 'getfacl',
    // service configuration text — install-bootstrap-services renders it
    'server_name', 'fastcgi_pass', 'listen 80', 'php_admin_value', '[program:',
    'autostart', 'supervisorctl', 'crontab', 'schedule:run',
    // databases — install-target-database
    'psql', 'createdb', 'dropdb', 'pg_dump', 'pg_restore',
    // TLS, mail, offsite, DNS — later slices, none of them this one
    'certbot', 'letsencrypt', 'postfix', 'postconf', 'postmap', 'rclone',
    'nsupdate', 'dkim',
    // the application — deploy owns it
    'artisan', 'migrate', 'composer', 'rateguru-deploy',
    // and nothing is ever removed to make room
    'rm -rf',
]);

it('names no brand anywhere, in the orchestrator or in the production renderer', function (string $file) {
    $executable = executableSourceLines(File::get(base_path($file)));

    foreach (['tits-guru', 'tits.guru', 'demo-shop', 'food-guru', 'animals-guru'] as $brand) {
        expect($executable)->not->toContain(
            $brand,
            "{$file} must be generic: a production target is described by the registry, never by a name compiled into a script ({$brand})",
        );
    }
})->with([
    'infrastructure/scripts/provision-target',
    'infrastructure/scripts/install-bootstrap-services',
    'infrastructure/scripts/install-bootstrap-host-layout',
]);

it('renders a production target from the registry rather than from the old shared-production config', function () {
    $services = executableSourceLines(File::get(base_path('infrastructure/scripts/install-bootstrap-services')));

    // The committed config/nginx/rateguru-production describes the OLD shared
    // production-root model — one /home/www/rateguru/production tree for every
    // brand — which the target registry replaced. It must never be reachable as
    // a target's service source. It still exists, because
    // install-target-operations installs and verifies it as part of the
    // operational bundle the recovery prerequisite machinery reads.
    expect($services)->not->toContain('rateguru-production');

    expect(executableSourceLines(provisionSource()))->not->toContain('rateguru-production');

    // The file is still committed, and still installed by its actual owner.
    expect(File::exists(base_path('infrastructure/config/nginx/rateguru-production')))->toBeTrue();
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->toContain('SRC_NGINX_SOURCE_PRODUCTION="${REPO_ROOT}/infrastructure/config/nginx/rateguru-production"');

    // And it describes the model it always did, so nothing can mistake it for
    // a per-target source: no registry-derived target root appears in it.
    expect(File::get(base_path('infrastructure/config/nginx/rateguru-production')))
        ->toContain('/home/www/rateguru/production/current/public')
        ->not->toContain('/home/www/rateguru/production/tits-guru');
});
