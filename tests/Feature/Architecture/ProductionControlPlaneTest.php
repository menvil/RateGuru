<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * main is RateGuru's production control plane, and the only branch it is.
 *
 * The branch model this file enforces:
 *
 *   feature/*  ->  develop  ->  staging
 *                     |
 *                     +-- PR -->  main  -->  production control plane
 *                                   |
 *                                   +--  v* tag contained in main  -->  production
 *
 * Two separate rules, which are easy to conflate and must not be:
 *
 *   TOOLING     A production operational workflow — Configure, Provision,
 *               Prepare, Repair, Restore, Recover, Rollback — takes its
 *               privileged infrastructure code from `main`. The
 *               production-tits-guru GitHub Environment allows `main` and `v*`
 *               and deliberately not `develop`, so a production workflow
 *               pointed at develop cannot run at all; and it should not,
 *               because privileged code must be promoted through a
 *               develop -> main pull request before it may act on production.
 *
 *   APPLICATION A new release reaches production ONLY through a `v*` tag whose
 *               commit is contained in main. main's HEAD is never deployed as
 *               an application. Allowing main in the Environment is what lets
 *               the emergency operations above run; it is not a deploy path.
 *
 * And two deliberate exceptions, which a mechanical "replace develop with main"
 * would destroy:
 *
 *   - a restore or recovery builds the EXACT historical source SHA its backup
 *     names, so that checkout stays the server-named commit;
 *   - a production release builds the EXACT tagged commit, not main's HEAD, and
 *     the artifact verified on staging is the one promoted to production.
 *
 * Staging stays on develop. That is what develop is for, and a later cleanup
 * must not quietly promote the staging control plane to main.
 */
function controlPlaneWorkflow(string $file): array
{
    $path = base_path('.github/workflows/'.$file);

    expect(File::exists($path))->toBeTrue("missing workflow: {$file}");

    return Yaml::parse(File::get($path));
}

/**
 * Every privileged-tooling checkout ref in a workflow, as
 * "job / step name" => ref.
 *
 * @return array<string, string>
 */
function controlPlaneCheckoutRefs(array $workflow): array
{
    $refs = [];

    foreach ($workflow['jobs'] ?? [] as $jobName => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (! str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                continue;
            }

            $refs[$jobName.' / '.($step['name'] ?? '<unnamed>')] = (string) ($step['with']['ref'] ?? '');
        }
    }

    return $refs;
}

/**
 * The refs that are legitimately NOT the control-plane branch: an exact
 * historical commit a backup named, or an exact tag/commit being released.
 *
 * Recognised by SHAPE — a workflow expression — rather than by step name, so a
 * renamed step cannot quietly become an exception.
 */
function controlPlaneProvenanceRef(string $ref): bool
{
    return (bool) preg_match('/^\$\{\{\s*(needs\.[a-z-]+\.outputs\.(required_source_sha|source-sha|checkout_ref)|github\.ref)\s*\}\}$/', $ref);
}

/**
 * The operational workflows this contract governs, by control plane.
 *
 * @return array<string, list<string>>
 */
function controlPlaneWorkflowsByRef(): array
{
    $byRef = [];

    foreach (trustedToolingRefs() as $file => $ref) {
        $byRef[$ref][] = $file;
    }

    return $byRef;
}

// =============================================================================
// A. No production operational workflow trusts develop
// =============================================================================

it('never takes production tooling from develop', function (string $file) {
    // The rule the GitHub Environment already enforces, asserted here so it is
    // caught in review rather than by a failed production run: the
    // production-tits-guru Environment allows main and v*, not develop.
    $refs = controlPlaneCheckoutRefs(controlPlaneWorkflow($file));

    expect($refs)->not->toBeEmpty("{$file} checks nothing out");

    foreach ($refs as $where => $ref) {
        expect($ref)->not->toBe('develop', "{$file}: {$where} takes production tooling from develop");
    }
})->with(controlPlaneWorkflowsByRef()['main']);

it('names no production workflow that still mentions develop as a trusted source', function (string $file) {
    // Prose drifts out of step with YAML and then misleads the next operator.
    // Any remaining mention must be explaining that develop is NOT the source.
    $source = File::get(base_path('.github/workflows/'.$file));

    foreach (preg_split('/\R/', $source) ?: [] as $number => $line) {
        if (! str_contains($line, 'develop')) {
            continue;
        }

        $trimmed = ltrim($line);

        // Executable YAML must not name develop at all.
        expect(str_starts_with($trimmed, '#'))
            ->toBeTrue("{$file}:".($number + 1).' names develop outside a comment: '.trim($line));

        // And a comment may only name it to rule it out, or to describe the
        // promotion path into main.
        expect(preg_match('/(\bnot\b|\bnever\b|\brather than\b|\bNOT\b|\binstead of\b|\bintegration\b|->\s*main)/', $line))
            ->toBe(1, "{$file}:".($number + 1).' still presents develop as a trusted production source: '.trim($line));
    }
})->with(controlPlaneWorkflowsByRef()['main']);

// =============================================================================
// B. Manual production operational workflows take their tooling from main
// =============================================================================

it('takes every privileged production checkout from main, except a named provenance ref', function (string $file) {
    // The positive half. Each checkout is either the control plane or an exact
    // commit something else named — never anything operator-selectable.
    $refs = controlPlaneCheckoutRefs(controlPlaneWorkflow($file));

    foreach ($refs as $where => $ref) {
        if (controlPlaneProvenanceRef($ref)) {
            continue;
        }

        expect($ref)->toBe('main', "{$file}: {$where} must take tooling from main, not {$ref}");
    }
})->with(controlPlaneWorkflowsByRef()['main']);

it('keeps the historical source checkout in restore and recovery as the commit the server named', function (string $file) {
    // The exception that matters most, and the one a mechanical replacement
    // would have broken: a restore or recovery exists to put back an EXACT
    // historical release. If this became main, the operation would silently
    // install different code than the data belongs to.
    $refs = controlPlaneCheckoutRefs(controlPlaneWorkflow($file));

    $historical = array_filter(
        $refs,
        static fn (string $ref): bool => str_contains($ref, 'required_source_sha'),
    );

    expect($historical)->not->toBeEmpty("{$file} never checks out the commit the server named");

    foreach ($historical as $where => $ref) {
        expect($ref)->toBe(
            '${{ needs.'.(str_contains($file, 'recover') ? 'recover' : 'restore').'.outputs.required_source_sha }}',
            "{$file}: {$where} must build the exact commit the server named",
        );
    }
})->with(['restore-production.yml', 'recover-production.yml']);

it('classifies every operational workflow in the repository', function () {
    // Closed-world: a new operational workflow must be classified deliberately,
    // so it cannot default into either control plane by being forgotten.
    $operational = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->reject(static fn (string $name): bool => in_array($name, [
            // Repository plumbing, not an operational control plane.
            'ci.yml',
            'coverage.yml',
            'label-review-bot-prs.yml',
            // The application release path, governed by its own rules below.
            'release.yml',
        ], true))
        ->sort()
        ->values()
        ->all();

    expect(array_keys(trustedToolingRefs()))->toEqualCanonicalizing(
        $operational,
        'trustedToolingRefs() must classify exactly the operational workflows that exist',
    );
});

// =============================================================================
// C. The production application release stays tag-only
// =============================================================================

it('releases to production only from a v* tag', function () {
    $workflow = controlPlaneWorkflow('release.yml');

    // Exactly one trigger, and it is a tag push.
    expect(array_keys($workflow['on']))->toBe(['push']);
    expect($workflow['on']['push'])->toBe(['tags' => ['v*']]);

    // None of the ways a production deploy could become selectable.
    foreach (['workflow_dispatch', 'workflow_call', 'schedule', 'repository_dispatch', 'pull_request'] as $forbidden) {
        expect(array_key_exists($forbidden, $workflow['on']))
            ->toBeFalse("release.yml must not be triggerable by {$forbidden}");
    }

    expect(array_key_exists('branches', $workflow['on']['push']))
        ->toBeFalse('release.yml must never run on a branch push');
});

it('accepts only a semantic v* tag whose commit is contained in main', function () {
    // Both halves are the production application boundary: the tag shape, and
    // the ancestry proof. Either one missing lets production be deployed from a
    // commit that was never promoted.
    $workflow = controlPlaneWorkflow('release.yml');
    $validate = collect($workflow['jobs']['validate']['steps'])
        ->firstWhere('id', 'release');

    expect($validate)->not->toBeNull('release.yml must validate the tag in a step with id: release');

    $run = (string) $validate['run'];

    // The semantic-version gate, fail-closed.
    expect($run)->toContain('tag_regex=');
    expect($run)->toContain('Invalid production tag');
    expect($run)->toMatch('/exit 1/');

    // The ancestry proof, against main, fail-closed.
    expect($run)->toContain('refs/remotes/origin/main');
    expect($run)->toContain('git merge-base');
    expect($run)->toContain('--is-ancestor');
    expect($run)->toContain('does not point to a commit contained in main');

    // Proven of the TAGGED commit, not of HEAD.
    expect($run)->toMatch('/--is-ancestor\s*\\\\?\s*\n?\s*"\$\{source_sha\}"/');
});

it('builds and deploys the exact tagged commit, never a branch head', function () {
    // main is the production control plane, not a deployable application ref.
    // Every application checkout here is the validated tagged SHA.
    $workflow = controlPlaneWorkflow('release.yml');
    $refs = controlPlaneCheckoutRefs($workflow);

    expect($refs)->not->toBeEmpty();

    foreach ($refs as $where => $ref) {
        expect(in_array($ref, ['${{ github.ref }}', '${{ needs.validate.outputs.source-sha }}'], true))
            ->toBeTrue("release.yml: {$where} checks out {$ref}, which is neither the tag nor the validated commit");

        expect(in_array($ref, ['main', 'develop'], true))
            ->toBeFalse("release.yml: {$where} must never deploy a branch head");
    }
});

it('builds the production artifact once and promotes that same artifact', function () {
    // Provenance: the tarball verified on staging is the one that reaches
    // production, so there is exactly one build job and both deploys consume it.
    $workflow = controlPlaneWorkflow('release.yml');

    $buildJobs = collect($workflow['jobs'])
        ->filter(static fn (array $job): bool => collect($job['steps'] ?? [])
            ->contains(static fn (array $step): bool => str_contains((string) ($step['uses'] ?? ''), 'actions/build-rateguru')))
        ->keys()
        ->all();

    expect($buildJobs)->toBe(['build'], 'production must be built exactly once, in the build job');

    foreach (['deploy-staging', 'deploy-production'] as $job) {
        // toContain's second argument is another expected needle, not a message.
        expect(in_array('build', (array) data_get($workflow, "jobs.{$job}.needs"), true))
            ->toBeTrue("{$job} must consume the one build");
    }

    // And production is gated behind the staging verification of that artifact.
    expect(in_array('deploy-staging', (array) data_get($workflow, 'jobs.deploy-production.needs'), true))
        ->toBeTrue('deploy-production must be gated behind deploy-staging');
    expect(data_get($workflow, 'jobs.deploy-production.environment'))->toBe('production-tits-guru');
});

// =============================================================================
// D. Staging stays on develop
// =============================================================================

it('keeps every staging control plane on develop', function (string $file) {
    // The other half of the model. A future cleanup that "unified" the refs
    // would take staging off the branch it is supposed to track, and staging
    // would stop being the place develop is verified.
    $refs = controlPlaneCheckoutRefs(controlPlaneWorkflow($file));

    expect($refs)->not->toBeEmpty("{$file} checks nothing out");

    foreach ($refs as $where => $ref) {
        if (controlPlaneProvenanceRef($ref)) {
            continue;
        }

        expect($ref)->toBe('develop', "{$file}: {$where} must take staging tooling from develop, not {$ref}");
    }
})->with(controlPlaneWorkflowsByRef()['develop']);

it('keeps staging deployment operator-triggered and defaulted to develop', function () {
    // Staging deployment is manual by design — an operator decides WHAT to
    // deploy, never an automatic reaction to a green build — and the ref it
    // offers defaults to develop. Promoting the control plane must not quietly
    // change either half: not the default away from develop, and not the
    // manual-only trigger into an automatic one.
    $workflow = controlPlaneWorkflow('deploy-staging.yml');

    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect(data_get($workflow, 'on.workflow_dispatch.inputs.ref.default'))->toBe('develop');

    // Staging is where develop is verified, so staging — unlike production —
    // may legitimately deploy an arbitrary operator-selected ref.
    expect(data_get($workflow, 'on.workflow_dispatch.inputs.ref.required'))->toBeTrue();
});

// =============================================================================
// Lifecycle gates are untouched by the control-plane change
// =============================================================================

it('leaves tits-guru planned, and leaves every fail-closed lifecycle gate alone', function () {
    // Promoting the control plane says nothing about whether the target is
    // live. tits-guru stays planned, and the gates that refuse a planned target
    // keep refusing it.
    $registry = json_decode(
        File::get(base_path('infrastructure/config/deployment-targets.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(data_get($registry, 'targets.tits-guru.lifecycle'))->toBe('planned');
    expect(data_get($registry, 'targets.staging-main.lifecycle'))->toBe('active');

    // The production workflows that must still refuse a planned target do so
    // through require_active_target in the shared library, not in YAML.
    expect(File::get(base_path('infrastructure/scripts/common')))
        ->toContain('require_active_target');
});
