<?php

use Illuminate\Support\Facades\File;

/**
 * The sudo perimeter is what turns a deploy credential into permission to run
 * four specific wrappers. It used to be a committed file naming one account,
 * which was correct while exactly one target could ever be deployed to and is
 * the wrong shape the moment a second is activated: somebody has to remember
 * to edit it, and "remember" is not a perimeter.
 *
 * It is derived from the registry now, and these tests are about the two
 * directions of that: an active target's deploy user is granted, and a target
 * that is not active is absent — not by being named in an exception, but by
 * never being emitted.
 */
function perimeterRender(array $registry): string
{
    // The shipped renderer, driven by a registry this test supplies. Running
    // the real implementation is the point: a reimplementation here would
    // prove only that two copies agree.
    $scratch = sys_get_temp_dir().'/perimeter-render-'.uniqid('', true);
    @mkdir($scratch, 0o755, true);

    file_put_contents($scratch.'/registry.json', json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $harness = $scratch.'/render.sh';
    file_put_contents($harness, implode("\n", [
        'set -Eeuo pipefail',
        'source '.escapeshellarg(base_path('infrastructure/scripts/install-target-perimeter')),
        'SRC_REGISTRY='.escapeshellarg($scratch.'/registry.json'),
        'render_sudoers_candidate',
        '',
    ]));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harness], $descriptors, $pipes, null, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);

    exec('rm -rf '.escapeshellarg($scratch));

    return $output;
}

function perimeterRegistry(): array
{
    return json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
}

it('renders exactly what the committed perimeter says, from the registry alone', function () {
    // The committed file stays committed so the perimeter is reviewable in a
    // diff. Proving it is the render is what stops the two drifting into a
    // file that grants a target the registry does not.
    expect(perimeterRender(perimeterRegistry()))
        ->toBe(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')));
});

it('grants nothing to tits-guru while it is planned', function () {
    $rendered = perimeterRender(perimeterRegistry());

    expect($rendered)
        ->toContain('deploy-rateguru-staging')
        ->not->toContain('deploy-rateguru-tits-guru');

    // And the committed file, which is what actually gets installed.
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('tits-guru');
});

it('includes that same deploy user the moment the registry says the target is active', function () {
    // The proof that activation is a registry change and nothing else: the
    // identical renderer, a registry whose only difference is one lifecycle
    // value, and a perimeter that now covers the brand — with no code path
    // anywhere naming it.
    $registry = perimeterRegistry();
    $registry['targets']['tits-guru']['lifecycle'] = 'active';

    $rendered = perimeterRender($registry);

    expect($rendered)
        ->toContain('Defaults:deploy-rateguru-tits-guru !requiretty')
        ->toContain('deploy-rateguru-tits-guru ALL=(root) NOPASSWD:')
        // Staging is still there: activating one target never withdraws another.
        ->toContain('deploy-rateguru-staging');

    // And it gets the same four wrappers as everybody else, not a variant.
    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup', 'rateguru-restore'] as $wrapper) {
        expect(substr_count($rendered, "/usr/local/sbin/{$wrapper}"))->toBe(2);
    }
});

it('drops a target from the perimeter when the registry disables it', function () {
    $registry = perimeterRegistry();
    $registry['targets']['staging-main']['lifecycle'] = 'disabled';
    $registry['targets']['tits-guru']['lifecycle'] = 'active';

    $rendered = perimeterRender($registry);

    expect($rendered)
        ->toContain('deploy-rateguru-tits-guru')
        ->not->toContain('deploy-rateguru-staging');
});

it('names no brand in the renderer, so a second production target needs no code', function () {
    // Scoped to the RENDERER. Elsewhere the installer legitimately names
    // tits-guru: it pins what the registry currently says about it, and it
    // probes that the wrappers really do reject it. Those are assertions about
    // today's state, not a code path a brand needs — and the difference is
    // exactly what this checks.
    $source = File::get(base_path('infrastructure/scripts/install-target-perimeter'));

    $start = mb_strpos($source, 'render_sudoers_candidate() {');
    $end = mb_strpos($source, 'validate_sudoers_content() {');

    expect($start)->not->toBeFalse();
    expect($end)->toBeGreaterThan($start);

    $renderer = mb_substr($source, $start, $end - $start);

    foreach (['tits-guru', 'food-guru', 'animals-guru', 'staging-main', 'deploy-rateguru-staging'] as $brand) {
        expect(str_contains($renderer, $brand))
            ->toBeFalse("the renderer must not name a target to render its rule: {$brand}");
    }

    // It reads the registry and emits; it decides nothing about who is who.
    expect($renderer)
        ->toContain('.value.lifecycle == "active"')
        ->toContain('.targets[$id].deploy_user');
});

it('grants the wrappers and never the binaries behind them', function () {
    $registry = perimeterRegistry();
    $registry['targets']['tits-guru']['lifecycle'] = 'active';

    $rendered = perimeterRender($registry);

    // A grant naming an operational binary directly would let the deploy
    // account call it with arbitrary arguments, bypassing every closed-class
    // check the wrapper exists to apply.
    expect($rendered)
        ->not->toContain('/home/www/rateguru/bin')
        ->not->toContain('ALL=(ALL)')
        ->not->toContain('NOPASSWD: ALL')
        ->not->toContain('/bin/bash')
        ->not->toContain('/bin/su');

    // Exactly the four, and nothing else reachable.
    preg_match_all('#/usr/local/sbin/([a-z-]+)#', $rendered, $matches);

    expect(array_values(array_unique($matches[1])))
        ->toBe(['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup', 'rateguru-restore']);
});

it('refuses a registry with no active target rather than installing an empty perimeter', function () {
    $registry = perimeterRegistry();

    foreach (array_keys($registry['targets']) as $id) {
        $registry['targets'][$id]['lifecycle'] = 'planned';
    }

    expect(perimeterRender($registry))->toContain('no active target');
});
