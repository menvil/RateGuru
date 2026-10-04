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
 *               privileged infrastructure code from `main`, so privileged code
 *               must be promoted through a develop -> main pull request before
 *               it may act on production.
 *
 *               The production-tits-guru Environment is to allow `main` and
 *               `v*`, and not `develop` — that allowlist is GitHub
 *               configuration no pull request can set, so it is an operator
 *               prerequisite rather than something this file can assert. What
 *               this file does assert is the half that lives in the repository:
 *               which ref each workflow checks out.
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
 * The ONE ref an operational workflow may legitimately take that is not its
 * control-plane branch: the exact historical commit a restore or recovery was
 * told to rebuild.
 *
 * Deliberately narrow. An earlier version of this also admitted
 * `needs.*.outputs.checkout_ref` and `github.ref`, which widened the production
 * exception to cover two refs production has no business using — an
 * operator-selected staging ref, and the release tag. Each exception here is a
 * hole in the assertion, so each is named exactly, and the job that produces it
 * is pinned too: only `restore` and `recover` may name a historical commit.
 *
 * Recognised by SHAPE rather than by step name, so a renamed step cannot quietly
 * become an exception.
 */
function controlPlaneHistoricalSourceRef(string $ref): bool
{
    return (bool) preg_match('/^\$\{\{\s*needs\.(restore|recover)\.outputs\.required_source_sha\s*\}\}$/', $ref);
}

/**
 * Staging only: the ref an operator selected for a staging deployment, resolved
 * by the workflow's own `resolve` job.
 *
 * Kept separate from the historical-source exception above, and never offered to
 * a production workflow. Staging is where develop is verified, so deploying an
 * arbitrary selected ref there is the point; production has no equivalent, and
 * must not inherit one by sharing a matcher.
 */
function controlPlaneStagingSelectedRef(string $ref): bool
{
    return (bool) preg_match('/^\$\{\{\s*needs\.resolve\.outputs\.checkout_ref\s*\}\}$/', $ref);
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
    // Asserted here because it is the half that lives in the repository, and
    // because a mistake should be caught in review rather than by a production
    // run that GitHub refuses. The Environment allowlist is the operator's half
    // and is a prerequisite, not something this can check.
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
        if (controlPlaneHistoricalSourceRef($ref)) {
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

it('pins the classification of every operational workflow literally', function () {
    // The closed-world test below proves the map COVERS every operational
    // workflow. It cannot prove each is classified CORRECTLY — flipping an entry
    // to 'develop' and the YAML with it would satisfy every map-derived
    // assertion in this file at once. So the map's own contents are pinned here,
    // by name, independent of anything derived. Changing the control plane of a
    // workflow has to be stated twice, deliberately.
    expect(trustedToolingRefs())->toBe([
        'configure-tits-guru.yml' => 'main',
        'provision-tits-guru.yml' => 'main',
        'prepare-production-host.yml' => 'main',
        'repair-production.yml' => 'main',
        'restore-production.yml' => 'main',
        'recover-production.yml' => 'main',
        'rollback-production.yml' => 'main',
        'deploy-staging.yml' => 'develop',
        'prepare-staging-host.yml' => 'develop',
        'repair-staging.yml' => 'develop',
        'restore-staging.yml' => 'develop',
        'recover-staging.yml' => 'develop',
        'rollback-staging.yml' => 'develop',
    ]);

    // Every production workflow is named `*production*` or is one of the two
    // onboarding operations, and nothing else is production. Stated so a new
    // production workflow cannot be classified as staging by a slip of the eye.
    foreach (trustedToolingRefs() as $file => $ref) {
        $looksProduction = str_contains($file, 'production') || str_contains($file, 'tits-guru');

        expect($ref)->toBe(
            $looksProduction ? 'main' : 'develop',
            "{$file} is classified {$ref}, which does not match what its name says it is",
        );
    }
});

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
        if (controlPlaneHistoricalSourceRef($ref) || controlPlaneStagingSelectedRef($ref)) {
            continue;
        }

        expect($ref)->toBe('develop', "{$file}: {$where} must take staging tooling from develop, not {$ref}");
    }
})->with(controlPlaneWorkflowsByRef()['develop']);

it('never offers a production workflow the operator-selected staging ref', function (string $file) {
    // The exception that must stay staging's. A production workflow resolving an
    // operator-chosen ref would be a production deploy path by another name.
    $refs = controlPlaneCheckoutRefs(controlPlaneWorkflow($file));

    foreach ($refs as $where => $ref) {
        expect(controlPlaneStagingSelectedRef($ref))
            ->toBeFalse("{$file}: {$where} resolves an operator-selected ref, which only staging may do");
    }
})->with(controlPlaneWorkflowsByRef()['main']);

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
