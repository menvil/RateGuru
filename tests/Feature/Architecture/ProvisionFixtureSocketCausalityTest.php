<?php

use Illuminate\Support\Facades\File;

/*
 * The simulated host's PHP-FPM sockets exist because something produced them.
 *
 * The fixture used to place them itself, before any pool configuration was
 * installed — a state no machine can be in, a pool socket with no pool. It made
 * the socket contract untestable in the direction that matters: a post-apply
 * verification could report the socket present without PHP-FPM ever having been
 * asked to create it, so a run that installed a pool and never reloaded looked
 * identical to one that did.
 *
 * The systemctl stub creates them now, from the pool files actually installed,
 * when the service is started or reloaded. These tests pin that causality in
 * both directions, because a fixture is only trustworthy where its own behaviour
 * is asserted rather than assumed.
 */

function demoShopSocket(string $scratch): string
{
    return $scratch.'/fs/run/php/rateguru-demo-shop.sock';
}

function stagingSocket(string $scratch): string
{
    return $scratch.'/fs/run/php/rateguru-staging.sock';
}

it('starts with the socket of the pool the host already has, and no other', function () {
    $scratch = provisionScratchDir();

    try {
        provisionFixture($scratch);

        // staging's pool is installed on this host, so its socket is there — and
        // it is there because the fixture loaded that pool, not because it was
        // placed by hand.
        expect(File::exists(stagingSocket($scratch)))->toBeTrue('the already-provisioned target has a pool, so it has a socket');

        // demo-shop has no pool yet. A fresh target's socket cannot exist.
        expect(File::exists(demoShopSocket($scratch)))->toBeFalse('a target with no pool configuration must have no socket');
    } finally {
        provisionCleanup($scratch);
    }
});

it('creates no socket in check mode', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit] = provisionRun(['--check', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1)
            ->and(File::exists(demoShopSocket($scratch)))->toBeFalse('a read-only run must create nothing, the socket included');
    } finally {
        provisionCleanup($scratch);
    }
});

it('creates the socket only once an apply has installed the pool and reloaded', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        expect(File::exists(demoShopSocket($scratch)))->toBeFalse();

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(0, $output);

        // The pool file is what the socket came from.
        expect(File::exists($scratch.'/fs/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf'))->toBeTrue()
            ->and(File::exists(demoShopSocket($scratch)))->toBeTrue('an applied pool, reloaded, produces its socket');

        // And it is reported as a socket rather than as the regular file it
        // physically is, which is what the installer's own contract reads.
        expect(File::get($scratch.'/fs/type-table.txt'))
            ->toContain(demoShopSocket($scratch).'|TYPE|socket');
    } finally {
        provisionCleanup($scratch);
    }
});

it('creates no socket when the reload fails, and the verification says so', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        // PHP-FPM refuses the reload. A pool that was never loaded has no socket,
        // and the run must not report otherwise.
        File::put($scratch.'/toggles/php8.5-fpm-reload-fail', '');

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);

        expect($exit)->not->toBe(0, "a failed reload must fail the apply:\n{$output}");

        // The run has to have REACHED the reload, or "no socket" says nothing: an
        // apply that failed earlier would satisfy the assertion below without the
        // reload having been attempted at all.
        // str_contains, not toContain($needle, $message): Pest reads the second
        // argument as another needle. The positive form at least fails loudly for it
        // — which is how this one was caught, while a negative one would have passed
        // in silence.
        expect(str_contains(provisionLog($scratch, 'systemctl.log'), 'reload php8.5-fpm'))
            ->toBeTrue('the apply must have attempted the reload this test makes fail');

        expect(File::exists(demoShopSocket($scratch)))->toBeFalse('a failed reload loads no pool, so it creates no socket');
    } finally {
        provisionCleanup($scratch);
    }
});

it('fails verification when the socket a provisioned pool should have is gone', function () {
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$applyExit, $applyOutput] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($applyExit)->toBe(0, $applyOutput);

        // What a crashed PHP-FPM leaves behind: the pool configuration is in
        // place, the socket is not.
        File::delete(demoShopSocket($scratch));

        [$exit, $output] = provisionRun(['--verify', '--target', 'demo-shop'], $env);

        expect($exit)->toBe(1, "a provisioned target with no pool socket is not provisioned:\n{$output}");
        expect($output)->toContain('rateguru-demo-shop.sock');
    } finally {
        provisionCleanup($scratch);
    }
});

it('drops the socket of a pool whose configuration is gone, and keeps the others', function () {
    // The other direction of the same contract. PHP-FPM unlinks the socket of a pool
    // it no longer loads, so a reload that only ever CREATED would leave one behind —
    // a verification passing on evidence of a pool that does not exist. And the
    // reconciliation is scoped: a pool that is still installed keeps its socket.
    $scratch = provisionScratchDir();

    try {
        $env = provisionFixture($scratch);

        [$exit, $output] = provisionRun(['--apply', '--target', 'demo-shop'], $env);
        expect($exit)->toBe(0, $output);

        expect(File::exists(demoShopSocket($scratch)))->toBeTrue()
            ->and(File::exists(stagingSocket($scratch)))->toBeTrue();

        // Somebody removes the pool. Until a reload, the socket is still there —
        // which is also true of a real host.
        File::delete($scratch.'/fs/etc/php/8.5/fpm/pool.d/rateguru-demo-shop.conf');

        provisionReloadPhpFpm($env);

        expect(File::exists(demoShopSocket($scratch)))
            ->toBeFalse('a reload must drop the socket of a pool it no longer loads');

        // The pool that is still installed is untouched, and so is its table row.
        expect(File::exists(stagingSocket($scratch)))->toBeTrue()
            ->and(File::get($scratch.'/fs/type-table.txt'))
            ->toContain(stagingSocket($scratch).'|TYPE|socket')
            ->not->toContain(demoShopSocket($scratch).'|TYPE|socket');
    } finally {
        provisionCleanup($scratch);
    }
});
