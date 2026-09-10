<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface for provisioning tits-guru.
 *
 * One button, one decision: build the infrastructure a target already declared
 * `planned` needs, on the host that already exists. Every mechanism it uses
 * exists already — the workflow is policy, and the only policy it carries is
 * WHICH target, in WHICH environment class, reached on WHICH machine.
 *
 * The distinction those last two draw is the thing most likely to be broken by
 * a later edit, and it is the reason this file is mostly about identity:
 *
 *   GitHub Environment   = which credentials and approvals a run uses
 *   action `environment` = which environment class the TARGET belongs to
 *
 * A GitHub Environment is a LOGICAL credential boundary; it implies nothing
 * about how many machines exist. tits-guru is a production target whose
 * bootstrap credential currently lives in the staging environment, so the two
 * legitimately disagree today. Making them agree by "fixing" either one would
 * reach the wrong machine with the wrong credential, or apply staging's
 * contract to a production target.
 */

/** @return array{0: array, 1: string} */
function provisionWorkflow(): array
{
    $path = base_path('.github/workflows/provision-tits-guru.yml');

    expect(File::exists($path))->toBeTrue('provision-tits-guru.yml is missing');

    $source = File::get($path);

    return [Yaml::parse($source), $source];
}

it('is manual-only and asks the operator nothing', function () {
    [$workflow] = provisionWorkflow();

    expect(data_get($workflow, 'name'))->toBe('Provision tits.guru')
        ->and(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read']);

    // Not "no target dropdown" — no inputs at all. There is exactly one
    // decision here and pressing the button is it.
    expect(data_get($workflow, 'on.workflow_dispatch'))->toBeNull();
});

it('offers no input that could turn provisioning into something else', function () {
    [, $source] = provisionWorkflow();

    // Asserted against the parsed document rather than the text, because the
    // header legitimately EXPLAINS that none of these exist.
    [$workflow] = provisionWorkflow();
    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    foreach ([
        'target', 'deployment-target', 'deployment_target', 'environment',
        'ref', 'tag', 'branch', 'version', 'sha', 'source-sha', 'release',
        'run-migrations', 'migrations', 'material-dir', 'lifecycle', 'host',
    ] as $forbidden) {
        expect(array_key_exists($forbidden, $inputs))
            ->toBeFalse("provision-tits-guru.yml must not let an operator choose {$forbidden}");
    }

    expect($inputs)->toBe([])
        ->and($source)->toContain('workflow_dispatch:');
});

it('fixes the target and its environment class as literals', function () {
    [$workflow] = provisionWorkflow();

    $step = collect(data_get($workflow, 'jobs.provision.steps'))
        ->first(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/provision-rateguru-target');

    expect($step)->not->toBeNull('the workflow must call the shared provisioning action');

    expect(data_get($step, 'with.deployment-target'))->toBe('tits-guru')
        // The TARGET's class. Not the GitHub Environment below, which is a
        // different question with a different answer today.
        ->and(data_get($step, 'with.environment'))->toBe('production');

    foreach (['deployment-target', 'environment'] as $fixed) {
        expect(data_get($step, "with.{$fixed}"))->not->toContain('${{');
    }
});

it('reads the machine from the environment that currently binds it', function () {
    [$workflow, $source] = provisionWorkflow();

    // staging is the PHYSICAL HOST binding, not the target's class. The two
    // are allowed to disagree, and here they do.
    expect(data_get($workflow, 'jobs.provision.environment'))->toBe('staging')
        ->and(data_get($workflow, 'jobs.provision.runs-on'))->toBe('ubuntu-latest');

    $step = collect(data_get($workflow, 'jobs.provision.steps'))
        ->first(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/provision-rateguru-target');

    expect(data_get($step, 'with.bootstrap-host'))->toBe('${{ vars.DEPLOY_HOST }}')
        ->and(data_get($step, 'with.bootstrap-port'))->toBe('${{ vars.DEPLOY_PORT }}');

    // The comment is load-bearing: it is the only thing stopping a later edit
    // from "correcting" one of the two environments into the other.
    expect($source)
        ->toContain('GitHub Environment  = which credentials and approvals this run uses')
        ->toContain('action `environment`= which environment class the TARGET belongs to')
        ->toContain('A GitHub Environment is a LOGICAL credential and permission boundary');

    // ...and it must not explain the split as a consequence of hardware.
    // Production gets its own environment and its own credentials whether or
    // not it ever gets its own machine, and that environment may point at this
    // same VPS on the day it is created. Tying the two together would leave
    // whoever does that work believing a host has to move first.
    expect($source)
        ->toContain('does NOT wait for production to get its own VPS')
        ->toContain('point at this same machine');

    expect(mb_strtolower($source))->not->toContain('when production gets its own host');
});

it('provisions with the privileged credential and never the deploy key', function () {
    [$workflow, $source] = provisionWorkflow();

    $step = collect(data_get($workflow, 'jobs.provision.steps'))
        ->first(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/provision-rateguru-target');

    expect(data_get($step, 'with.bootstrap-user'))->toBe('${{ vars.BOOTSTRAP_USER }}')
        ->and(data_get($step, 'with.bootstrap-ssh-key'))->toBe('${{ secrets.BOOTSTRAP_SSH_KEY }}')
        ->and(data_get($step, 'with.bootstrap-known-hosts'))->toBe('${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}');

    // Provisioning creates system identities and service configuration, so it
    // needs root; the deploy key reaches only the narrow sudo wrappers. There
    // is no fallback between them, in either direction.
    foreach (['DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'DEPLOY_USER', 'DEPLOY_WRAPPER', 'DEPLOY_ROOT', 'DEPLOY_INCOMING'] as $forbidden) {
        expect(str_contains($source, 'secrets.'.$forbidden) || str_contains($source, 'vars.'.$forbidden))
            ->toBeFalse("provision-tits-guru.yml must not reach for the deployment credential: {$forbidden}");
    }
});

it('takes its tooling from develop and its logic from the shared action', function () {
    [$workflow] = provisionWorkflow();

    $checkout = collect(data_get($workflow, 'jobs.provision.steps'))
        ->first(fn (array $step): bool => str_starts_with((string) data_get($step, 'uses'), 'actions/checkout@'));

    expect(data_get($checkout, 'with.ref'))->toBe('develop')
        ->and(data_get($checkout, 'with.persist-credentials'))->toBeFalse();

    // Exactly the shared action plus the pinned checkout: no second
    // provisioning implementation, and nothing that builds or deploys.
    $uses = collect(data_get($workflow, 'jobs.provision.steps'))->pluck('uses')->filter()->values()->all();

    expect($uses)->toBe([
        'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
        './.github/actions/provision-rateguru-target',
    ]);
});

it('reimplements no provisioning logic in YAML', function () {
    [, $source] = provisionWorkflow();

    // Every decision belongs to infrastructure/scripts/provision-target. The
    // workflow may report the action's outputs and nothing else.
    $executable = executableSourceLines($source);

    foreach ([
        'provision-target', 'install-target-', 'ssh ', 'scp ', 'systemctl', 'nginx',
        'php-fpm', 'supervisorctl', 'psql', 'createdb', 'createuser', 'certbot',
        'artisan', 'composer', 'migrate', 'targets set', 'deployment-targets.json',
        'chown', 'chmod', 'useradd',
    ] as $forbidden) {
        expect(str_contains(mb_strtolower($executable), mb_strtolower($forbidden)))
            ->toBeFalse("provision-tits-guru.yml must orchestrate only: {$forbidden}");
    }
});

it('serializes against every other mutation of the same machine', function () {
    [$workflow] = provisionWorkflow();

    $deploy = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $prepare = Yaml::parse(File::get(base_path('.github/workflows/prepare-staging-host.yml')));

    // The staging group BECAUSE both logical targets sit on the same physical
    // host today. A second lane over one machine would serialize nothing.
    expect(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and($workflow['concurrency'])->toBe($deploy['concurrency'])
        ->and($workflow['concurrency'])->toBe($prepare['concurrency']);

    expect(data_get($workflow, 'jobs.provision.concurrency'))->toBeNull();
});

it('leaves the target planned, undeployed and unreachable', function () {
    [, $source] = provisionWorkflow();

    // The registry is what declares a lifecycle, and this run does not touch it.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // The summary reports the action's own outputs, and says plainly what did
    // not happen — a green run here is "infrastructure exists", nothing more.
    expect($source)
        ->toContain('steps.provision.outputs.provisioned')
        ->toContain('steps.provision.outputs.lifecycle')
        ->toContain("steps.provision.outputs['application-state']")
        ->toContain("steps.provision.outputs['public-state']")
        ->toContain('The target\'s infrastructure exists. That is all this run did.');

    // The one phrase this summary must never contain.
    expect(mb_strtolower($source))->not->toContain('production ready');
});

it('states the public boundary exactly, because provisioning does serve something', function () {
    [, $source] = provisionWorkflow();

    // provision-target installs an internal-only vhost and says so itself:
    // "the target answers only on its internal hostname over loopback". A
    // summary claiming the target is reachable from nowhere is therefore
    // wrong, and wrong in the direction that matters — it invites an operator
    // to conclude nothing is listening and then be surprised by what is.
    //
    // The guarantee worth stating is the PUBLIC boundary, which is the one
    // provisioning actually makes.
    expect(File::get(base_path('infrastructure/scripts/provision-target')))
        ->toContain('internal-only Nginx vhost')
        ->toContain('answers only on its internal hostname over loopback');

    expect($source)
        ->toContain('it has no public application')
        ->toContain('not reachable from the public internet')
        // And it says what DOES answer, rather than omitting it.
        ->toContain('internal-only vhost')
        ->toContain('over loopback');

    foreach (['reachable from nowhere', 'serves nothing', 'nothing is listening', 'listens on nothing'] as $overclaim) {
        expect(str_contains(mb_strtolower($source), $overclaim))
            ->toBeFalse("the summary must not overclaim: {$overclaim}");
    }

    // The contract itself is unweakened: the three states provisioning
    // guarantees are still exactly what the summary reports.
    expect($source)
        ->toContain('lifecycle=planned')
        ->toContain("steps.provision.outputs['application-state']")
        ->toContain("steps.provision.outputs['public-state']");
});
