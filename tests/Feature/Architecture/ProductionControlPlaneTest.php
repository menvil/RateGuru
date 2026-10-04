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
 *               The production-tits-guru Environment allows `main` and `v*`,
 *               and not `develop` — ONE Environment, by decision, with the
 *               main-only rule enforced in the repository instead of by
 *               splitting it. That allowlist is GitHub configuration no pull
 *               request can set, so it is an operator prerequisite. What this
 *               file asserts is the half that lives here: which ref each
 *               workflow checks out, and the fail-closed main-only gate that
 *               stands in front of every production Environment job.
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
        // promotion path into main. The vocabulary is a list of negation markers
        // rather than a judgement of meaning — deliberately crude, since the
        // alternative is a guard nobody can satisfy without contorting prose.
        expect(preg_match('/(\bnot\b|\bnever\b|\bneither\b|\bcannot\b|\brefus|\brather than\b|\bNOT\b|\binstead of\b|\bintegration\b|->\s*main)/', $line))
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

// =============================================================================
// Manual production operations run from main, and nowhere else
// =============================================================================
//
// The production-tits-guru Environment permits `main` and `v*`, by decision:
// `v*` has to be there, because Release to production runs with github.ref at
// the tag, and no second Environment is introduced. But GitHub matches an
// Environment's allowlist against the ref of the RUN, and a workflow_dispatch
// ref may be a tag — so the allowlist alone would also admit a manual dispatch
// of a production operational workflow from a tag.
//
// Each of those workflows therefore carries a repository-level, fail-closed
// main-ref gate in a job of its own holding NO environment, which every
// environment-bearing job is downstream of. A refused run never has the
// Environment's credentials available to it, because the job that owns them
// never begins.
//
// Scope, stated honestly: this stops accidental and casual misuse — a tag picked
// in the Run workflow dropdown, a habit of dispatching from develop. It is NOT a
// boundary against someone who can already create an arbitrary trusted tag, who
// would author the gate itself at that tag. A GitHub tag ruleset over `v*` is
// that boundary, and is a separate permission surface.

/**
 * The manual production operational workflows, pinned as a LITERAL list.
 *
 * Deliberately not derived from trustedToolingRefs(): the critical production
 * expectations must not share a single source with the implementation they
 * check, or one wrong entry would move the expectation along with the thing
 * being expected.
 *
 * @return list<string>
 */
function mainOnlyProductionWorkflows(): array
{
    return [
        'configure-tits-guru.yml',
        'provision-tits-guru.yml',
        'prepare-production-host.yml',
        'repair-production.yml',
        'restore-production.yml',
        'recover-production.yml',
        'rollback-production.yml',
    ];
}

/**
 * Is $job downstream of $ancestor through `needs`, at any depth?
 */
function controlPlaneJobIsDownstreamOf(array $workflow, string $job, string $ancestor): bool
{
    $needs = (array) data_get($workflow, "jobs.{$job}.needs");

    foreach ($needs as $dependency) {
        if ($dependency === $ancestor || controlPlaneJobIsDownstreamOf($workflow, $dependency, $ancestor)) {
            return true;
        }
    }

    return false;
}

it('covers exactly the manual production operational workflows', function () {
    // The literal list above and the classification map are two independent
    // statements of the same fact, so this compares them rather than letting one
    // stand in for the other. release.yml is deliberately absent from both: it is
    // the application release path, is not dispatchable, and is not main-only.
    $fromMap = array_keys(array_filter(trustedToolingRefs(), static fn (string $ref): bool => $ref === 'main'));

    expect(mainOnlyProductionWorkflows())->toEqualCanonicalizing($fromMap);

    expect(in_array('release.yml', mainOnlyProductionWorkflows(), true))
        ->toBeFalse('release.yml runs from a v* tag and must never be given a main-only gate');
});

it('is dispatch-only, so there is no second way in', function (string $file) {
    // A main-only gate on a workflow that also ran on a push would be a gate with
    // a door beside it.
    $workflow = controlPlaneWorkflow($file);

    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch'], "{$file} must be workflow_dispatch-only");
})->with(mainOnlyProductionWorkflows());

it('gates every production Environment job behind a main-ref check that holds no Environment', function (string $file) {
    $workflow = controlPlaneWorkflow($file);

    // The gate exists, and critically holds no environment of its own: that is
    // what keeps a refused run from ever having production credentials available.
    $gate = data_get($workflow, 'jobs.validate-ref');

    expect($gate)->not->toBeNull("{$file} has no validate-ref gate job");
    expect(data_get($gate, 'environment'))
        ->toBeNull("{$file}: the gate must hold no GitHub Environment, or a refused run has already been granted one");
    expect(data_get($gate, 'needs'))
        ->toBeNull("{$file}: the gate must run first, so it depends on nothing");

    // It compares against refs/heads/main and fails closed.
    $run = (string) data_get($gate, 'steps.0.run');

    expect($run)->toContain('refs/heads/main');
    expect($run)->toContain('exit 1');
    expect(data_get($gate, 'steps.0.env.RUN_REF'))->toBe('${{ github.ref }}');

    // And EVERY environment-bearing job is downstream of it, at any depth.
    $environmentJobs = collect(data_get($workflow, 'jobs'))
        ->filter(static fn (array $job): bool => isset($job['environment']))
        ->keys()
        ->all();

    expect($environmentJobs)->not->toBeEmpty("{$file} has no production Environment job at all");

    foreach ($environmentJobs as $job) {
        expect(controlPlaneJobIsDownstreamOf($workflow, $job, 'validate-ref'))
            ->toBeTrue("{$file}: job '{$job}' holds an Environment without being downstream of validate-ref");
    }
})->with(mainOnlyProductionWorkflows());

it('uses a byte-identical gate in every manual production workflow', function () {
    // Seven copies of a guard is seven chances for one to drift. An earlier
    // version of the behavioural test below extracted the gate from a single
    // workflow, so relaxing another one's gate to admit tags passed every test —
    // which is exactly the regression this contract exists to prevent. The copies
    // are now pinned to each other, and the behaviour below is checked against
    // each one individually.
    $gates = [];

    foreach (mainOnlyProductionWorkflows() as $file) {
        $gates[$file] = (string) data_get(controlPlaneWorkflow($file), 'jobs.validate-ref.steps.0.run');
    }

    expect(array_unique(array_values($gates)))
        ->toHaveCount(1, 'the main-only gate has drifted between workflows: '.implode(', ', array_keys($gates)));

    // And the one text is the one that matters.
    expect(reset($gates))
        ->toContain('refs/heads/main')
        ->toContain('exit 1');
});

it('refuses a tag ref, develop and any other branch, and admits only main', function (string $ref, bool $allowed) {
    // Each workflow's OWN gate logic, executed — not one workflow's standing in
    // for the rest. Extracted from the real YAML rather than restated, so this
    // cannot pass against a gate that says something else.
    foreach (mainOnlyProductionWorkflows() as $file) {
        $run = (string) data_get(controlPlaneWorkflow($file), 'jobs.validate-ref.steps.0.run');

        $script = tempnam(sys_get_temp_dir(), 'control-plane-gate-');
        file_put_contents($script, "#!/usr/bin/env bash\n".$run);

        try {
            exec('env RUN_REF='.escapeshellarg($ref).' bash '.escapeshellarg($script).' 2>&1', $output, $exit);

            expect($exit === 0)->toBe(
                $allowed,
                "{$file}: ".($allowed ? "{$ref} must be allowed" : "{$ref} must be refused").":\n".implode("\n", $output),
            );
        } finally {
            @unlink($script);
            $output = [];
        }
    }
})->with([
    'main' => ['refs/heads/main', true],
    // The ref the Environment allowlist would otherwise admit.
    'a release tag' => ['refs/tags/v1.2.3', false],
    'a pre-release tag' => ['refs/tags/v0.0.1-rc1', false],
    'develop' => ['refs/heads/develop', false],
    'a feature branch' => ['refs/heads/feature/anything', false],
    // A branch whose name merely contains main.
    'a branch named like main' => ['refs/heads/not-main', false],
    'main as a tag' => ['refs/tags/main', false],
    'an empty ref' => ['', false],
]);

it('leaves the release path on v*, with no main-ref gate and its ancestry proof intact', function () {
    // The deliberate exception, and the reason the Environment keeps v*.
    $workflow = controlPlaneWorkflow('release.yml');

    expect($workflow['on'])->toBe(['push' => ['tags' => ['v*']]]);
    expect(data_get($workflow, 'jobs.validate-ref'))
        ->toBeNull('release.yml must not carry a main-only gate: it runs from a tag by design');

    // What stands in for it is the ancestry proof — the tagged commit must be
    // contained in main — which is what keeps v* from being an independent
    // production entrance.
    $run = (string) collect($workflow['jobs']['validate']['steps'])->firstWhere('id', 'release')['run'];

    expect($run)->toContain('refs/remotes/origin/main');
    expect($run)->toContain('--is-ancestor');
    expect($run)->toContain('does not point to a commit contained in main');

    // And production is still the Environment this decision keeps shared.
    expect(data_get($workflow, 'jobs.deploy-production.environment'))->toBe('production-tits-guru');
});

it('introduces no second GitHub Environment', function () {
    // The decision: one Environment permitting main and v*, with the main-only
    // rule enforced in the repository instead of by splitting the Environment.
    $environments = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->flatMap(static function (string $path): array {
            $workflow = Yaml::parse(File::get($path));

            return collect(data_get($workflow, 'jobs') ?? [])
                ->map(static fn (array $job) => $job['environment'] ?? null)
                ->filter()
                ->values()
                ->all();
        })
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($environments)->toBe(['production-tits-guru', 'staging']);
});

it('gates a staging operation on develop, never on main', function (string $file) {
    // Staging has the same exposure for the same reason — the `staging`
    // Environment restricts no refs, so a dispatch from an arbitrary branch would
    // run THAT branch's YAML with staging's credentials — so it has the same
    // shape of gate. What differs is the ref it admits: develop, never main.
    //
    // `deploy-staging.yml` is the one exception and is excluded below: it takes a
    // ref input, and deploying an operator-selected ref to staging is the whole
    // point of it.
    $workflow = controlPlaneWorkflow($file);
    $gate = data_get($workflow, 'jobs.validate-ref');

    expect($gate)->not->toBeNull("{$file} has no control-plane gate");
    expect(data_get($gate, 'environment'))
        ->toBeNull("{$file}: the gate must hold no GitHub Environment");

    // EXECUTED, not grepped. Substring checks on the gate's text pass just as
    // happily against a gate that rejects develop and admits everything else —
    // the one mistake a develop-only gate can actually make. So each staging
    // gate runs, the same way the production ones do.
    $run = (string) data_get($gate, 'steps.0.run');
    $script = tempnam(sys_get_temp_dir(), 'staging-gate-');
    file_put_contents($script, "#!/usr/bin/env bash\n".$run);

    try {
        foreach ([
            'refs/heads/develop' => true,
            'refs/heads/main' => false,
            'refs/tags/v1.2.3' => false,
            'refs/heads/feature/anything' => false,
            'refs/heads/not-develop' => false,
            'refs/tags/develop' => false,
            '' => false,
        ] as $ref => $allowed) {
            $output = [];
            exec('env RUN_REF='.escapeshellarg($ref).' bash '.escapeshellarg($script).' 2>&1', $output, $exit);

            expect($exit === 0)->toBe(
                $allowed,
                "{$file}: ".($allowed ? "{$ref} must be allowed" : "{$ref} must be refused").":\n".implode("\n", $output),
            );
        }
    } finally {
        @unlink($script);
    }

    // Every Environment-bearing job downstream of it, at any depth.
    $environmentJobs = collect(data_get($workflow, 'jobs'))
        ->filter(static fn (array $job): bool => isset($job['environment']))
        ->keys()
        ->all();

    expect($environmentJobs)->not->toBeEmpty("{$file} has no Environment job at all");

    foreach ($environmentJobs as $job) {
        expect(controlPlaneJobIsDownstreamOf($workflow, $job, 'validate-ref'))
            ->toBeTrue("{$file}: job '{$job}' holds an Environment without being downstream of validate-ref");
    }
})->with(collect(controlPlaneWorkflowsByRef()['develop'])
    // Excluded deliberately: it takes a ref input, so an arbitrary ref IS its
    // purpose. Named here rather than filtered by a property, so removing the
    // input would not silently exempt it.
    ->reject(static fn (string $file): bool => $file === 'deploy-staging.yml')
    ->values()
    ->all());

it('uses a byte-identical gate in every gated staging workflow', function () {
    // Five copies is five chances for one to drift, and the production side
    // already learned that lesson: a behavioural check against one workflow let a
    // relaxed gate elsewhere pass. Each copy is executed above; here they are
    // pinned to each other, which is what makes a single divergent edit visible.
    $gates = [];

    foreach (controlPlaneWorkflowsByRef()['develop'] as $file) {
        if ($file === 'deploy-staging.yml') {
            continue;
        }

        $gates[$file] = (string) data_get(controlPlaneWorkflow($file), 'jobs.validate-ref.steps.0.run');
    }

    expect($gates)->toHaveCount(5);
    expect(array_unique(array_values($gates)))
        ->toHaveCount(1, 'the develop-only gate has drifted between workflows: '.implode(', ', array_keys($gates)));
});

it('leaves staging deployment ungated, because selecting a ref is its purpose', function () {
    // The exception, asserted as a property rather than left as an absence: if
    // this ever gained a gate, the one workflow whose job is to deploy an
    // operator-chosen ref would stop being able to.
    $workflow = controlPlaneWorkflow('deploy-staging.yml');

    expect(data_get($workflow, 'jobs.validate-ref'))
        ->toBeNull('deploy-staging takes a ref input, so it must not be gated to one branch');
    expect(data_get($workflow, 'on.workflow_dispatch.inputs.ref.required'))->toBeTrue();
});

it('never admits main into a staging gate, nor develop into a production one', function () {
    // The two gates must not drift into each other. Read from source, both ways.
    foreach (mainOnlyProductionWorkflows() as $file) {
        $run = (string) data_get(controlPlaneWorkflow($file), 'jobs.validate-ref.steps.0.run');

        expect($run)->toContain('refs/heads/main');
        expect($run)->not->toContain('refs/heads/develop');
    }

    foreach (controlPlaneWorkflowsByRef()['develop'] as $file) {
        $run = (string) data_get(controlPlaneWorkflow($file), 'jobs.validate-ref.steps.0.run');

        if ($run === '') {
            continue;
        }

        expect($run)->toContain('refs/heads/develop');
        expect($run)->not->toContain('refs/heads/main');
    }
});
