<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Three different things were all spelled `production`, and separating them is
 * the point of this file:
 *
 *   GitHub Environment      a box of credentials, reviewers and protection
 *                           rules. Per TARGET: production-tits-guru.
 *   environment class       what the application IS. Per CLASS: production.
 *                           APP_ENV, SENTRY_ENVIRONMENT, the action input.
 *   deployment target       which brand: tits-guru.
 *
 * Collapsing any two of them is the mistake worth catching. A job that reaches
 * for the wrong box gets another target's credentials; a Sentry environment
 * named per target splits one product's errors across environments nobody
 * alerts on.
 *
 * @return array<string, mixed>
 */
function targetBindingWorkflow(string $name): array
{
    return Yaml::parseFile(base_path(".github/workflows/{$name}"));
}

/** @return list<string> */
function targetBindingWorkflowNames(): array
{
    return collect(glob(base_path('.github/workflows/*.{yml,yaml}'), GLOB_BRACE) ?: [])
        ->map(fn (string $path): string => basename($path))
        ->values()
        ->all();
}

it('binds every tits-guru job to that target own GitHub Environment', function () {
    // Every job that acts on tits-guru reaches into the box named for it, and
    // no tits-guru job is left reaching into the shared legacy one.
    $offenders = [];

    foreach (targetBindingWorkflowNames() as $name) {
        $workflow = targetBindingWorkflow($name);
        $source = File::get(base_path(".github/workflows/{$name}"));

        foreach (($workflow['jobs'] ?? []) as $id => $job) {
            $environment = $job['environment'] ?? null;
            $environment = is_array($environment) ? ($environment['name'] ?? null) : $environment;

            if ($environment !== 'production') {
                continue;
            }

            $offenders[] = "{$name}:{$id}";
        }

        // And the legacy box is not named in operator text either, which is
        // where an operator would be sent to configure the wrong environment.
        expect($source)->not->toMatch('/Environments -> production(?!-)/');
    }

    expect($offenders)->toBe([], 'these jobs still bind to the legacy shared GitHub Environment `production`');
});

it('keeps the environment CLASS out of the GitHub Environment name', function () {
    // The other direction, and the one a careless rename would break: what the
    // application is told it is must stay the class, never the box.
    foreach (targetBindingWorkflowNames() as $name) {
        $workflow = targetBindingWorkflow($name);

        foreach (($workflow['jobs'] ?? []) as $id => $job) {
            foreach (($job['steps'] ?? []) as $step) {
                $class = $step['with']['environment'] ?? null;

                if ($class === null) {
                    continue;
                }

                expect($class)->toBeIn(['staging', 'production'],
                    "{$name}:{$id} passes an environment CLASS of '{$class}' — a class is staging or production, never a GitHub Environment name");
            }
        }
    }
});

it('keeps Sentry grouped by environment class and separated by target tag', function () {
    // One Sentry project, one environment per class, and the brand carried as a
    // tag. Inventing production-<brand> environments would split one product's
    // errors across environments nobody has alerts on.
    $release = File::get(base_path('.github/workflows/release.yml'));

    expect($release)
        ->toContain('The SENTRY environment stays `production` for every production target')
        ->toContain('deliberately not the same thing as the GitHub Environment');

    foreach (targetBindingWorkflowNames() as $name) {
        $workflow = targetBindingWorkflow($name);

        foreach (($workflow['jobs'] ?? []) as $id => $job) {
            foreach (($job['steps'] ?? []) as $step) {
                $uses = $step['uses'] ?? '';

                if (! str_contains($uses, 'sentry-release') && ! str_contains($uses, 'record-rateguru-deployment')) {
                    continue;
                }

                $environment = $step['with']['environment'] ?? null;

                expect($environment)->toBeIn(['staging', 'production'],
                    "{$name}:{$id} reports a Sentry environment of '{$environment}' — Sentry groups by class, and the brand is the deployment_target tag");
            }
        }
    }
});

it('says the same three things in the configure workflow', function () {
    // The newest operator surface, where the distinction is easiest to get
    // wrong because all three appear within ten lines of each other.
    $workflow = targetBindingWorkflow('configure-tits-guru.yml');
    $job = $workflow['jobs']['configure'];

    expect($job['environment'])->toBe('production-tits-guru');

    $step = collect($job['steps'])->firstWhere('uses', './.github/actions/configure-rateguru-target');

    expect($step['with']['environment'])->toBe('production');
    expect($step['with']['deployment-target'])->toBe('tits-guru');
});
