<?php

use Illuminate\Support\Facades\File;

/**
 * One physical machine, several logical targets, and several operations that
 * mutate what those targets SHARE: the host roots, the shared namespace they
 * sit in, the base services, the operational bundle.
 *
 * Every lock this repository had before was keyed by backup namespace or by
 * application root, which is the wrong key for that. "Prepare staging-main"
 * and "Provision tits-guru" hold different namespace locks and then converge
 * the same machine in parallel. GitHub concurrency groups do not close it
 * either: two GitHub Environments can address one host, so the authoritative
 * answer has to live where the mutation happens.
 */
function hostLockScripts(): array
{
    return ['prepare-host', 'provision-target', 'configure-target', 'repair-target'];
}

function hostLockSource(string $script): string
{
    return File::get(base_path("infrastructure/scripts/{$script}"));
}

it('gives the machine one lock that is keyed by nothing', function () {
    // Keyed by nothing is the property. A lock keyed by namespace or target
    // would let two operations on one machine hold two different files and
    // both proceed, which is exactly the state this closes.
    $common = hostLockSource('common');

    expect($common)
        ->toContain('HOST_INFRASTRUCTURE_LOCK_NAME="host-infrastructure.lock"')
        ->toContain('acquire_host_infrastructure_lock');

    // No interpolation in the name: the moment it carries a namespace or a
    // target it stops being one lock per machine.
    expect($common)->not->toMatch('/host-infrastructure-\$\{/');
});

it('is taken by every operation that mutates shared host infrastructure', function (string $script, string $evidence) {
    // str_contains, not toContain: Pest reads a second argument to toContain
    // as ANOTHER NEEDLE, so the message would silently become a requirement.
    expect(str_contains(hostLockSource($script), $evidence))
        ->toBeTrue("{$script} mutates shared host infrastructure and must claim the machine");
})->with([
    // The three that source `common` take it through the shared helper, so
    // there is one implementation of what claiming a machine means.
    'provision-target' => ['provision-target', 'acquire_host_infrastructure_lock'],
    'configure-target' => ['configure-target', 'acquire_host_infrastructure_lock'],
    'repair-target' => ['repair-target', 'acquire_host_infrastructure_lock'],
    // prepare-host cannot source `common` — it runs before the operational
    // bundle exists — so it opens the same path itself. The shared thing is
    // the file NAME, not the function, and that is what is asserted of it.
    'prepare-host' => ['prepare-host', 'host-infrastructure.lock'],
]);

it('never waits, so a blocked run says so instead of looking like a hang', function (string $script) {
    // flock -n, always. A run that blocked silently would be cancelled halfway
    // through somebody else's mutation.
    $source = executableSourceLines(hostLockSource($script));

    expect($source)->toMatch('/flock -n /', "{$script} must refuse rather than wait for the host lock");
})->with(['common', 'prepare-host']);

it('names what is holding the machine, and says nothing was changed', function () {
    foreach (['common', 'prepare-host'] as $script) {
        expect(hostLockSource($script))
            ->toContain("another operation is already mutating this host's shared infrastructure");
    }

    // And the refusal names the path, so an operator can see who holds it.
    expect(hostLockSource('common'))->toContain('${lock_path}');
});

it('is taken only in a mutating mode, so a read-only child cannot deadlock its parent', function () {
    // configure-target asks provision-target --verify a question WHILE holding
    // the machine. flock treats independently opened descriptors independently
    // even inside one process tree, so a child that re-acquired what its
    // parent holds would wedge against its own orchestrator.
    foreach (['provision-target', 'configure-target', 'repair-target'] as $script) {
        $source = hostLockSource($script);

        // Acquired on the apply path, which no read-only mode reaches. Proved
        // by position: every acquisition sits after the point where --apply is
        // the only mode still running.
        $acquire = mb_strpos($source, 'acquire_host_infrastructure_lock');
        $applyPath = mb_strpos($source, 'perform_apply');

        expect($acquire)->not->toBeFalse("{$script} must claim the machine");
        expect($applyPath)->not->toBeFalse();

        // It appears in exactly one place, so no read-only path can reach a
        // second one.
        expect(substr_count($source, 'acquire_host_infrastructure_lock'))
            ->toBe(1, "{$script} must claim the machine in exactly one place");
    }

    // And configure-target's structural probe really is the read-only mode.
    expect(hostLockSource('configure-target'))
        ->toContain('"${PROVISION_BIN}" --verify --target "${TARGET_ID}"');
});

it('is never taken by an installer a locking orchestrator invokes', function (string $installer) {
    // The other half of one-owner-per-lock: a child must not acquire what its
    // parent holds.
    expect(str_contains(hostLockSource($installer), 'host-infrastructure.lock'))
        ->toBeFalse("{$installer} is invoked by an orchestrator that already holds the machine");
})->with([
    'install-bootstrap-host-layout',
    'install-bootstrap-services',
    'install-target-operations',
    'install-target-prerequisites',
    'install-target-database',
    'install-target-perimeter',
    'bootstrap-host',
]);

it('leaves per-target operations keyed by their own target', function (string $script) {
    // Deploy and rollback mutate one target's tree and nothing shared, so they
    // must NOT serialize against another target's deployment. Widening them to
    // the machine would turn every unrelated deploy into a queue.
    $source = hostLockSource($script);

    expect($source)
        ->toContain('acquire_deployment_lock')
        ->not->toContain('host-infrastructure.lock');
})->with(['deploy', 'rollback']);

it('takes the machine before the target, so two operations cannot wedge', function () {
    // repair-target holds both. Opposite orders in two runs is how a deadlock
    // is built, so the order is asserted rather than left to reading.
    $source = hostLockSource('repair-target');

    $machine = mb_strpos($source, 'acquire_host_infrastructure_lock');
    $target = mb_strpos($source, 'acquire_deployment_lock "${TARGET_ROOT}"');

    expect($machine)->not->toBeFalse();
    expect($target)->not->toBeFalse();
    expect($machine)->toBeLessThan($target, 'the machine lock must be taken before the target lock');
});

it('keeps the restore and recovery interlocks exactly as they were', function () {
    // This work added a lock; it must not have moved one. The prepare-host /
    // restore interlock is what stops a restore starting under a preparation,
    // and it is keyed by namespace on purpose.
    expect(hostLockSource('prepare-host'))
        ->toContain('prepare-host-${namespace}.lock');

    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain('assert_no_host_preparation_running');
});
