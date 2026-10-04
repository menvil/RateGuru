<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/provision-target — what it establishes before it
 * mutates anything.
 *
 * The closed lifecycle authorization (only a planned production target, only
 * a registry that is valid everywhere), a host that is already a RateGuru
 * host, a shared namespace the host owns the new way, and one authority:
 * this bundle's registry, which the host's installed bundle must agree with
 * before any target state is inspected — the lock first, then no
 * deployment-owned state, no incompatible account, no path conflict, no
 * service activated on configuration its own parser rejected, and no
 * success reported after a delegated installer failed. Every refusal is
 * proved by running the real orchestrator against the same simulated host
 * as ProvisionTargetTest, which holds the operation itself; the harness they
 * share lives in tests/Pest.php.
 */

// =============================================================================
// Lifecycle: the closed authorization, refused four different ways
// =============================================================================

it('refuses every target that is not a planned production one', function (
    array $options,
    string $target,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options);

        [$exit, $output] = provisionRun(['--apply', '--target', $target], $env);

        expect($exit)->toBe(1, "provisioning must refuse {$target}:\n{$output}");
        expect($output)->toContain($expected);

        // Refused before anything at all was touched.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'an active target' => [
        [], 'staging-main',
        'target staging-main has lifecycle=active — it is already in the operational lifecycle',
    ],
    'a disabled target' => [
        ['demoLifecycle' => 'disabled'], 'demo-shop',
        'target demo-shop has lifecycle=disabled, not planned',
    ],
    'a planned staging target' => [
        ['demoOverrides' => ['environment_class' => 'staging']], 'demo-shop',
        'target demo-shop has environment_class=staging, not production',
    ],
    'an unknown target' => [
        [], 'no-such-target',
        'unknown target: no-such-target',
    ],
]);

it('refuses a registry that is invalid anywhere, not merely invalid for this target', function (
    array $options,
    string $expectedFragment,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expectedFragment);
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'malformed JSON' => [
        ['registry' => "{ not json at all\n"],
        'target registry is not valid JSON',
    ],
    'a collision with another target' => [
        // Two targets sharing a pool socket would have them fighting over the
        // same file the moment both ran.
        ['demoOverrides' => ['php_fpm' => ['pool' => 'rateguru-demo-shop', 'socket' => '/run/php/rateguru-tits-guru.sock']]],
        'target registry is invalid',
    ],
    'a service name that could never be safely rendered' => [
        ['demoOverrides' => ['supervisor' => ['program' => 'demo shop queue', 'queue' => 'rateguru-demo-shop']]],
        'target registry is invalid',
    ],
]);

// =============================================================================
// Host prerequisites and new-target safety: every refusal before any mutation
// =============================================================================

it('refuses a host that is not already a RateGuru host, and says whose job that is', function (
    array $options,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, $options['fixture'] ?? []);

        foreach ($options['clearToggles'] ?? [] as $toggle) {
            @unlink($scratch.'/toggles/'.$toggle);
        }

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect($output)->toContain('No mutation was performed');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'an unconverged runtime' => [
        ['clearToggles' => ['runtime-installer-compliant']],
        'this is a host prerequisite, not this target',
    ],
    // Deliberately not the run root: that one is the machine's lock directory,
    // and its absence is refused earlier and by name (see the lock test below).
    'a missing host root' => [
        ['fixture' => ['omitHostRoots' => ['/home/www/rateguru/config']]],
        'host-level prerequisites are not satisfied',
    ],
]);

// =============================================================================
// The shared namespace a target sits in belongs to the host
// =============================================================================
//
// A real provisioning run reached target creation and then failed several
// minutes later inside install-public-storage-access, which proved the runtime
// user could not write its own shared/storage. Every directory the run had
// created was exactly right. The blocker was one level above them:
//
//   /home/www/rateguru/production   deploy-rateguru:rateguru-production-code 2750
//
// That directory used to BE a production application's root. The multi-target
// registry made it a shared namespace whose children are independent targets —
// and a runtime user has to traverse it to reach its own tree, which mode 2750
// owned by a group it is not in forbids. The synthetic fixture had always
// modelled the new architecture, so nothing failed until a real host did.
//
// The fix is a boundary, not a permission: the namespace belongs to the host,
// a target-scoped run refuses it instead of repairing it, and Prepare Host
// converges it as one directory entry.

it('refuses to provision into a namespace the host still owns the old way, before touching the target', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, ['legacyNamespace' => true]);
        $fs = $scratch.'/fs';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);

        // Named as what it is — a host prerequisite — and pointed at the
        // operation that owns it, never repaired here.
        expect($output)
            ->toContain('host-level prerequisites are not satisfied')
            ->toContain('host prerequisite, not this target')
            ->toContain('never creates or re-owns host infrastructure')
            ->toContain('No mutation was performed');

        // And the child's own words say which path and which ownership.
        expect($output)
            ->toContain('host:/home/www/rateguru/production')
            ->toContain('deploy-rateguru:rateguru-production-code')
            ->toContain('run install-bootstrap-host-layout --apply WITHOUT --target');

        // Nothing about demo-shop was created: no identity, no directory, no
        // service file. The real run got much further than this before it
        // discovered the problem.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
        expect(file_exists($fs.'/home/www/rateguru/production/demo-shop'))->toBeFalse();
        expect(file_exists($fs.'/home/deploy-rateguru-demo-shop'))->toBeFalse();
        expect(file_get_contents($fs.'/etc-passwd'))->not->toContain('demo-shop');
        expect(file_get_contents($fs.'/etc-group'))->not->toContain('demo-shop');

        // The host's own state is left exactly as it was. Provisioning is
        // target-scoped; converging shared infrastructure is a host operation
        // with its own review.
        expect(provisionOwnerTableRows($scratch)[$fs.'/home/www/rateguru/production'] ?? null)
            ->toBe(['deploy-rateguru', 'rateguru-production-code'], 'a refused run must not re-own the namespace');
        expect(fileperms($fs.'/home/www/rateguru/production') & 0o7777)->toBe(0o2750);
    } finally {
        provisionCleanup($scratch);
    }
});

it('provisions the same target once the host owns the namespace', function () {
    // The other half: with the namespace converged to the host contract, the
    // identical command succeeds. Nothing about the target changed.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $fs = $scratch.'/fs';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TARGET INFRASTRUCTURE: PROVISIONED');
        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop/shared/storage'))->toBeTrue();
    } finally {
        provisionCleanup($scratch);
    }
});

it('names no brand in the namespace it refuses, so a second production target behaves identically', function () {
    // demo-shop exists nowhere in this repository except the fixture registry.
    // The refusal above is therefore about the namespace, not about a target
    // anybody wrote code for — which is what makes food-guru and animals-guru
    // siblings rather than special cases.
    $sources = [
        'infrastructure/scripts/install-bootstrap-host-layout',
        'infrastructure/scripts/bootstrap-host-preflight',
        'infrastructure/scripts/provision-target',
    ];

    foreach ($sources as $path) {
        $source = File::get(base_path($path));

        foreach (['tits-guru', 'demo-shop', 'food-guru', 'animals-guru', 'rateguru-production-code'] as $brand) {
            expect($source)->not->toContain($brand, "{$path} must not name a target to manage the shared namespace");
        }
    }

    // It is derived from the registry, which is what makes it generic.
    expect(File::get(base_path('infrastructure/scripts/install-bootstrap-host-layout')))
        ->toContain('target_namespace_directories');
});

// =============================================================================
// One authority: this bundle, and a host that agrees with it
// =============================================================================
//
// provision-target reads the lifecycle, the application_root and the target's
// identities out of THIS bundle's registry, and the installers it delegates to
// read the same file. The host's own installed bundle is independently
// versioned and legitimately older — a host prepared before this tooling
// existed has an installed `common` with no lifecycle gate in it at all — so
// it is treated as a prerequisite to prove, never as a second opinion to
// consult. The tests below are about that boundary in both directions.

it('proves the host operational bundle before it inspects any target state', function () {
    // The ordering IS the contract: a target's lifecycle, root and identities
    // read against a stale host are not facts worth collecting, so nothing
    // about the target is looked at until the host is known to agree.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        @unlink($scratch.'/toggles/operations-installer-compliant');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('install-target-operations --verify does not pass')
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('refresh it through Prepare Host')
            ->toContain('No target state was inspected. No mutation was performed');

        // Target-scoped, and it says so: provisioning refuses rather than
        // refreshing host-global tooling on its own initiative.
        expect($output)->toContain('never updates host-global tooling itself');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');

        // The children were never asked anything about this target either.
        expect(provisionLog($scratch, 'children.log'))
            ->not->toContain('--target demo-shop');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a host whose runtime registry is not this bundle\'s', function (string $shape) {
    // A host that disagrees about this target's lifecycle or root is a host
    // whose operational bundle is behind. Provisioning does not pick a winner
    // between two registry revisions — there is no correct winner, only a
    // target created from one description and operated by another.
    $scratch = provisionScratchDir();

    try {
        $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
        $demo = provisionDemoTarget();

        switch ($shape) {
            case 'root':
                $demo['application_root'] = '/home/www/rateguru/production/demo-shop-elsewhere';
                $registry['targets']['demo-shop'] = $demo;
                break;
            case 'lifecycle':
                $demo['lifecycle'] = 'active';
                $registry['targets']['demo-shop'] = $demo;
                break;
            case 'absent':
                // The host predates the target entirely — the ordinary state
                // of a host prepared before this brand was declared.
                break;
        }

        $env = provisionFixture($scratch, [
            'installedRegistryJson' => json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ]);

        $fs = $scratch.'/fs';

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain("the host's runtime registry differs from this bundle's")
            ->toContain("demo-shop's lifecycle and application_root disagree between them")
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('No target state was inspected. No mutation was performed');

        // NEITHER root was created — not the one this bundle names, and not
        // the one the host's registry names.
        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop'))
            ->toBeFalse("the trusted registry's root must not be created");
        expect(is_dir($fs.'/home/www/rateguru/production/demo-shop-elsewhere'))
            ->toBeFalse("the installed registry's root must not be created");

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'a different application_root' => ['root'],
    'a different lifecycle' => ['lifecycle'],
    'a target the host has never heard of' => ['absent'],
]);

it('refuses a host with no runtime registry at all', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, [
            'installedRegistry' => $scratch.'/fs/home/www/rateguru/etc/does-not-exist.json',
        ]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the host has no readable runtime target registry')
            ->toContain('the installed RateGuru operational bundle is stale')
            ->toContain('No target state was inspected. No mutation was performed');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('reads the lifecycle and the root from this bundle, never from the host', function () {
    // The positive half, and the one that makes the refusals meaningful: with
    // an exactly current host, provisioning proceeds — and every path it
    // creates is the one THIS bundle's registry names.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TARGET INFRASTRUCTURE: PROVISIONED');

        expect(is_dir($scratch.'/fs/home/www/rateguru/production/demo-shop/releases'))->toBeTrue();

        // The report says which host it agreed with, in the modes that print
        // one: --apply is a transcript of what it did, --verify is the report.
        [$verifyExit, $verifyOutput] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($verifyExit)->toBe(0, $verifyOutput);
        expect($verifyOutput)
            ->toContain('PASS     host:install-target-operations')
            ->toContain('PASS     host:runtime-registry');

        // install-target-operations was asked to verify, and never to apply:
        // updating the host's operational bundle is a host operation.
        $children = provisionLog($scratch, 'children.log');

        expect($children)->toContain('operations-installer --verify');
        expect($children)->not->toContain('operations-installer --apply');
    } finally {
        provisionCleanup($scratch);
    }
});

it('says a bundle missing the lifecycle gate is a broken bundle, never a missing command', function () {
    // The other direction: this file sources the `common` beside itself, so a
    // library that cannot answer the lifecycle question means the BUNDLE is
    // incomplete. It says that, rather than reaching the gate as a bare
    // "command not found" partway through a run.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $common = $scratch.'/repo/infrastructure/scripts/common';

        file_put_contents($common, preg_replace(
            '/^require_provisionable_target\(\) \{.*?\n\}\n/ms',
            '',
            File::get($common),
        ));

        expect(File::get($common))->not->toContain('require_provisionable_target() {');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('this infrastructure bundle is inconsistent')
            ->toContain('has no require_provisionable_target')
            ->toContain('nothing was changed')
            ->not->toContain('command not found');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'chown.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('claims the machine before anything, and refuses a host that has no lock directory', function () {
    // Targets share a host. Provisioning converges service configuration and
    // reloads services staging also uses, so it holds the machine for the whole
    // run — and a host whose run root does not exist has never been
    // bootstrapped, which is a refusal rather than something to create.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch, ['omitHostRoots' => ['/home/www/rateguru/run']]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1);
        expect($output)
            ->toContain('the operational lock directory does not exist')
            ->toContain('never creates it');

        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a target that already carries deployment-owned state', function (
    string $shape,
    string $expected,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';

        @mkdir($root.'/releases/20240101120000', 0o755, true);
        @mkdir($root.'/shared', 0o755, true);

        switch ($shape) {
            case 'current':
                symlink($root.'/releases/20240101120000', $root.'/current');
                break;
            case 'previous':
                symlink($root.'/releases/20240101120000', $root.'/previous');
                break;
            case 'release':
                // The release directory alone, with no pointer to it.
                break;
            case 'env':
                rmdir($root.'/releases/20240101120000');
                file_put_contents($root.'/shared/.env', "APP_KEY=base64:SOMEBODY-ELSE\n");
                break;
        }

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain($expected);
        expect($output)->toContain('never deletes, moves or re-owns anything to make a target look new');

        // Nothing was created, and above all nothing was removed. Each shape
        // is asked about the artifact it actually planted: the env case has no
        // release directory, and letting it fall through to the release
        // assertion would have proved nothing about the file it is named for.
        expect(provisionLog($scratch, 'identity.log'))->toBe('');

        if ($shape === 'env') {
            expect(File::get($root.'/shared/.env'))
                ->toBe("APP_KEY=base64:SOMEBODY-ELSE\n", 'the foreign environment file must be untouched');
        } else {
            expect(is_dir($root.'/releases/20240101120000'))->toBeTrue();
        }

        // --check reports the same conflict rather than pretending it is drift.
        [$checkExit, $checkOutput] = provisionRun(['--check', '--target', 'demo-shop'], $env);
        expect($checkExit)->toBe(1);
        expect($checkOutput)
            ->toContain('CONFLICT state:demo-shop')
            ->toContain('TARGET INFRASTRUCTURE: BLOCKED');
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'current' => ['current', 'current already exists'],
    'previous' => ['previous', 'previous already exists'],
    'an existing release' => ['release', 'releases already contains'],
    'an environment file' => ['env', 'shared/.env already exists'],
]);

it('refuses an existing account whose metadata is incompatible, rather than rewriting it', function () {
    $scratch = provisionScratchDir();

    try {
        // A runtime account that already exists with a login shell: rewriting
        // it could break unrelated automation, so it is a decision for an
        // operator, not for an installer.
        $env = provisionFixture($scratch, [
            'passwdExtra' => ['rateguru-demo-shop:x:6001:6001::/home/www/rateguru/production/demo-shop:/bin/bash'],
            'groupExtra' => ['rateguru-demo-shop:x:6001:'],
        ]);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            // The refusal names the specific account and the specific drift,
            // not merely "something is unresolvable".
            ->toContain('CONFLICT user:rateguru-demo-shop')
            ->toContain('shell is /bin/bash, required /usr/sbin/nologin')
            ->toContain('incompatible existing account');
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
        expect(provisionLog($scratch, 'install.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('refuses a path conflict instead of deleting whatever is in the way', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        $root = $scratch.'/fs/home/www/rateguru/production/demo-shop';

        @mkdir($root, 0o755, true);
        // A regular file where a managed directory belongs. Resolving it would
        // mean deleting something, which this never does.
        file_put_contents($root.'/locks', "NOT-A-DIRECTORY-SENTINEL\n");

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('CONFLICT path:/home/www/rateguru/production/demo-shop/locks')
            ->toContain('is a regular file, expected directory')
            ->toContain('never deletes, replaces or follows a conflicting path');
        expect(file_get_contents($root.'/locks'))->toBe("NOT-A-DIRECTORY-SENTINEL\n");
        expect(provisionLog($scratch, 'identity.log'))->toBe('');
    } finally {
        provisionCleanup($scratch);
    }
});

it('never activates a service on configuration its own parser rejected', function (
    string $toggle,
    string $installedPath,
) {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);
        touch($scratch.'/toggles/'.$toggle);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('install-bootstrap-services --apply --target demo-shop --provisioning failed');

        // The candidate was rolled back by the services installer's own
        // transaction, so nothing invalid is left installed.
        expect(file_exists($scratch.'/fs'.$installedPath))
            ->toBeFalse("a rejected candidate must not survive: {$installedPath}");

        // The layout converged first and is deliberately left converged: a
        // rerun resumes rather than starting over.
        expect(is_dir($scratch.'/fs/home/www/rateguru/production/demo-shop/releases'))->toBeTrue();
    } finally {
        provisionCleanup($scratch);
    }
})->with([
    'a rejected PHP-FPM pool' => ['php-fpm-t-fail', '/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf'],
    'a rejected Nginx vhost' => ['nginx-t-fail', '/etc/nginx/sites-available/rateguru-demo-shop'],
    'a rejected Supervisor program' => ['supervisor-reread-fail', '/etc/supervisor/conf.d/rateguru-demo-shop-queue.conf'],
]);

it('aborts when a delegated child installer fails, and never reports success', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        // The public-storage ACL is owned by its own installer; a failure
        // there is a failure of the whole provisioning run.
        @unlink($scratch.'/toggles/public-storage-installer-compliant');
        touch($scratch.'/toggles/public-storage-installer-apply-fail');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('install-public-storage-access --apply --target demo-shop --provisioning failed')
            ->not->toContain('TARGET INFRASTRUCTURE: PROVISIONED')
            ->not->toContain('RATEGURU_PROVISION_RESULT=');
    } finally {
        provisionCleanup($scratch);
    }
});
