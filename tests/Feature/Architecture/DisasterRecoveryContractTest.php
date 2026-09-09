<?php

use Illuminate\Support\Facades\File;

/**
 * The disaster-recovery contract, stated once and proved.
 *
 * Recovering a lost host is not one script. It is a backup format, a
 * preparation, a server-side state machine, a historical build, a controlled
 * deployment, two guards, an offsite fence, two named workflows and a runbook
 * — and each of those has its own test file proving its own mechanism works.
 *
 * What none of them can prove is the thing an operator actually depends on:
 * that the pieces still add up to the promise. A backup format can be correct
 * and stop carrying what a recovery needs. A refusal can be correct and stop
 * being reachable from the path that needs it. A workflow can be correct and
 * quietly stop invoking the fence. Every one of those is a green suite and a
 * broken recovery.
 *
 * So this file asserts the PROMISE, clause by clause, and deliberately reaches
 * across surfaces to do it. It does not re-prove mechanisms — where a
 * behaviour is owned elsewhere, this asserts that the recovery path reaches
 * it, and names where the mechanism itself is proved.
 *
 * The promise, in full:
 *
 *   Given a new Ubuntu 22.04 x86_64 machine, recovery SSH access to it, its
 *   verified host key, the GitHub recovery values and one exact schema 3
 *   offsite backup — and NOTHING from the lost machine — one workflow dispatch
 *   rebuilds the target onto that machine: its data and storage from that
 *   backup, its environment file byte-for-byte from that backup, running the
 *   exact commit that backup's release.json names, with no migration, queue
 *   running, scheduler present, health passing, no guard left behind, its
 *   offsite writers still held, and the old machine, DEPLOY_HOST and DNS
 *   untouched.
 */

/** The two recovery workflows, which are one implementation at two identities. */
function disasterRecoveryWorkflows(): array
{
    return [
        'recover-staging.yml' => ['staging-main', 'staging'],
        'recover-production.yml' => ['tits-guru', 'production'],
    ];
}

function disasterRecoveryScript(string $name): string
{
    return File::get(base_path('infrastructure/scripts/'.$name));
}

/** Every `with:` value any job of a workflow passes to any action. */
function disasterRecoveryActionInputs(array $workflow): array
{
    $used = [];

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        foreach ((array) data_get($job, 'steps', []) as $step) {
            foreach ((array) data_get($step, 'with', []) as $input => $value) {
                $used[] = [
                    'job' => $jobName,
                    'step' => $step['name'] ?? 'unnamed',
                    'uses' => (string) data_get($step, 'uses'),
                    'input' => $input,
                    'value' => is_string($value) ? $value : json_encode($value),
                ];
            }
        }
    }

    return $used;
}

// =============================================================================
// 1. A lost host needs nothing from the lost host
// =============================================================================

it('reaches only the replacement machine, and reads the lost one only to refuse', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow, $source] = recoverWorkflow($file);

        // Every host a recovery connects to is the machine the operator named.
        // Not DEPLOY_HOST, not a registry address, not a hostname resolved from
        // anywhere: a recovery exists because the machine the environment names
        // is gone, so reaching it is not a fallback, it is a contradiction.
        foreach (disasterRecoveryActionInputs($workflow) as $used) {
            if (! in_array($used['input'], ['recovery-host', 'deploy-host', 'host'], true)) {
                continue;
            }

            expect($used['value'])->toBe(
                '${{ needs.validate.outputs.replacement_host }}',
                "{$file}: {$used['job']} / {$used['step']} points {$used['input']} at something other than the replacement machine",
            );
        }

        // vars.DEPLOY_HOST is read in exactly one job, and that job's only
        // purpose is to REFUSE a replacement that turns out to be the current
        // machine. Every other reader would be a recovery touching the host it
        // exists because of.
        $readsCurrentHost = [];

        foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
            if (str_contains(json_encode($job), 'vars.DEPLOY_HOST')) {
                $readsCurrentHost[] = $jobName;
            }
        }

        expect($readsCurrentHost)->toBe(['binding'], "{$file}: vars.DEPLOY_HOST is read outside the binding refusal");

        // And nothing copies a file off a second machine: the entire input is
        // the backup, and the backup comes from the offsite remote.
        foreach (['scp ', 'rsync ', 'ssh-keyscan'] as $forbidden) {
            expect(executableSourceLines($source))->not->toContain($forbidden);
        }
    }
});

// =============================================================================
// 2. What an operator types, and what they must never have to
// =============================================================================

it('takes the replacement machine and one exact backup, and never a commit', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

        // A closed input set. There is no target selector (the two workflows
        // ARE the selector), no ref, tag, release, commit or migration input,
        // and no backup LOCATION: recovery is offsite-only, from one exact
        // backup named by its timestamp. Production adds one input and only
        // one: the typed confirmation that gates a live-money target.
        $expected = ['mode', 'backup', 'operation', 'replacement-host', 'replacement-port'];

        if ($environment === 'production') {
            $expected[] = 'confirmation';
        }

        expect(array_keys($inputs))->toBe($expected, "{$file} offers an input the contract does not have");

        expect(data_get($inputs, 'mode.options'))->toBe(['start', 'continue-held']);

        // The commit is the one thing that MUST NOT be a person's decision:
        // the data belongs to exactly one commit, and only the backup knows
        // which. It flows backup -> release.json -> the server's own recovery
        // state -> the action's output -> the build's checkout, and every step
        // of that chain is machine-read.
        $requiredCommitReaders = [];

        foreach (disasterRecoveryActionInputs($workflow) as $used) {
            if (str_contains($used['value'], 'required_source_sha')) {
                $requiredCommitReaders[] = $used['value'];
            }
        }

        expect($requiredCommitReaders)->not->toBe([], "{$file} never uses the commit the server named");

        foreach ($requiredCommitReaders as $value) {
            expect($value)->toBe(
                '${{ needs.recover.outputs.required_source_sha }}',
                "{$file}: the required commit comes from somewhere other than the server's own recovery state",
            );
        }

        expect(json_encode($workflow))->not->toContain('inputs.commit');
    }
});

// =============================================================================
// 3. No PREPARE_* value, no hand-copied file
// =============================================================================

it('prepares the replacement machine out of the backup, from no hand-supplied material', function () {
    // The clean-host promise fails the moment a recovery needs a value that
    // lived on, or was set up for, the machine that is gone. The material
    // comes out of the backup; the deploy public key is derived on the runner
    // from the deploy private key that already exists.
    $read = recoveryValuesRead('recover-staging.yml')['all'];

    expect(recoveryValuesRead('recover-production.yml')['all'])->toBe($read);
    expect($read)->toBe([
        'DEPLOY_HOST',
        'DEPLOY_INCOMING',
        'DEPLOY_KNOWN_HOSTS',
        'DEPLOY_ROOT',
        'DEPLOY_SSH_KEY',
        'DEPLOY_USER',
        'DEPLOY_WRAPPER',
        'RECOVERY_BOOTSTRAP_SSH_KEY',
        'RECOVERY_BOOTSTRAP_USER',
        'RECOVERY_KNOWN_HOSTS',
        'RECOVERY_RCLONE_CONFIG',
    ]);

    foreach (array_keys(disasterRecoveryWorkflows()) as $file) {
        [, $source] = recoverWorkflow($file);

        expect(executableSourceLines($source))->not->toContain('PREPARE_');
    }

    // And there is no fallback hiding in the server-side preparation either:
    // the recovery entry point is --recovery-backup, and what it composes for
    // the prerequisite installers comes from the backup and the runner-derived
    // seed, never from a value an operator was asked to keep.
    expect(disasterRecoveryScript('prepare-host'))->toContain('--recovery-backup');
    expect(disasterRecoveryScript('fetch-recovery-material'))->toContain('REQUIRED_MANIFEST_SCHEMA=3');
});

// =============================================================================
// 4. What a backup has to be
// =============================================================================

it('recovers only from a backup that carries the host material, and never applies the server snapshot', function () {
    $common = disasterRecoveryScript('common');

    // One closed file set, defined once, in SHA256SUMS order. Eight files for
    // schema 3: the seven a live restore has always needed, plus the host
    // material that makes a clean-host recovery possible without a person
    // carrying anything.
    foreach ([
        'database.dump',
        'storage-app.tar.gz',
        'environment.env',
        'release.json',
        'server-configuration.tar.gz',
        'recovery-material.tar.gz',
        'manifest.json',
    ] as $member) {
        expect($common)->toContain($member);
    }

    expect($common)
        ->toContain('BACKUP_MANIFEST_SCHEMA_CURRENT=3')
        // Diagnostic, forensic, and never a reconstruction input. The two
        // archives are deliberately not one archive, and the difference is
        // that one of them is applied to a machine and the other never is.
        ->toContain('a diagnostic, forensic record of how the host was configured and is never');

    // Schema 1 and 2 stay fully restorable onto a LIVE target — an older
    // backup does not become unusable because the format grew — and are
    // refused by name as the source of a clean-host recovery, with no
    // fallback to material supplied by hand.
    expect(disasterRecoveryScript('recover-host'))
        ->toContain('a host recovery requires schema 3')
        ->toContain('This backup stays restorable onto a LIVE target through Restore Target Data')
        ->toContain('there is deliberately no fallback to hand-supplied material');

    // The mechanisms themselves are proved in BackupTest, RestoreTest,
    // VerifyBackupTest, FetchRecoveryMaterialTest and RecoverHostTest; what
    // matters here is that a recovery still reaches them.
    expect(disasterRecoveryScript('recover-host'))->toContain('server-configuration.tar.gz');
});

// =============================================================================
// 5. The code and the data are the same age
// =============================================================================

it('runs the exact commit the recovered data belongs to, without a migration', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        // Recovered data is a snapshot of a schema. Running newer code over it
        // is a guess; migrating it is a change to data an operator is trying
        // to get back. Both are refused, and the second one structurally: the
        // controlled deployment is the ordinary deployment with migrations
        // switched off.
        $deployStep = collect(disasterRecoveryActionInputs($workflow))
            ->first(static fn (array $used): bool => $used['input'] === 'run-migrations');

        expect($deployStep)->not->toBeNull("{$file} never states whether the recovery deployment migrates");
        expect($deployStep['value'])->toBe('false', "{$file} allows a migration during a recovery");
        expect($deployStep['job'])->toBe('deploy');

        // Trusted operational tooling always comes from develop, whatever
        // commit is being rebuilt: the recovery must not be steerable by the
        // historical application code it is recovering.
        foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (! str_starts_with((string) data_get($step, 'uses', ''), 'actions/checkout@')) {
                    continue;
                }

                $ref = (string) data_get($step, 'with.ref');
                $name = (string) data_get($step, 'name');

                if (str_contains($name, 'application') || str_contains($name, 'commit')) {
                    expect($ref)->toBe(
                        '${{ needs.recover.outputs.required_source_sha }}',
                        "{$file}: {$jobName} builds something other than the commit the server named",
                    );

                    continue;
                }

                expect($ref)->toBe('develop', "{$file}: {$jobName} takes its tooling from {$ref}, not develop");
            }
        }
    }

    // Neither end of the pipeline takes the other's word for it. The shared
    // deployment action refuses its own recovery mode with migrations on, so
    // a workflow that ever asked for one would be refused rather than obeyed
    // — the switch in the YAML is the request, not the guarantee.
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))
        ->toContain('run-migrations must be false when recovery-operation is set');

    // And the server proves it after the fact: a resume refuses to finish an
    // operation whose staged migration count is not the one it recorded, so
    // "no migration ran" is observed rather than promised.
    expect(disasterRecoveryScript('recover-host'))
        ->toContain('refusing to claim the schema is unchanged without the number it was staged with');
});

// =============================================================================
// 6. The environment is the backup's, byte for byte
// =============================================================================

it('recovers underneath the environment the data was taken with, and reports nothing about it but the verdict', function () {
    $recover = disasterRecoveryScript('recover-host');

    // The backup's data belongs to one environment file. Recovering underneath
    // a different one points the restored application at a different database,
    // storage disk, mail transport or key — so the comparison is exact, and it
    // happens before anything is activated.
    expect($recover)
        ->toContain('cmp -s "${backup_env}" "${SHARED_ENV}"')
        ->toContain('environment material: MATCH')
        ->toContain('environment material: MISMATCH')
        // A recovery never generates a Laravel environment and never rotates a
        // secret: it compares what the preparation placed against the backup's
        // own, and refuses rather than reconciling them.
        ->toContain('a recovery compares the prepared environment against the backup and never writes one');

    // MATCH or MISMATCH, and nothing else — no content, no length, no digest,
    // in a log an operator will paste into an issue.
    expect($recover)->toContain("Neither file's content, size or digest is reported here");

    foreach (['key:generate', 'artisan key', 'ALTER USER', 'ALTER ROLE'] as $forbidden) {
        expect($recover)->not->toContain($forbidden);
    }
});

// =============================================================================
// 7. A recovery outlives the run that started it
// =============================================================================

it('survives an interrupted run, and refuses to start a second recovery over a finished one', function () {
    $recover = disasterRecoveryScript('recover-host');

    // Two safe stages, and only two. A recovery that is interrupted anywhere
    // leaves the host held and the operation on disk; the continuation asks
    // the SERVER which stage it is at rather than deciding from what the
    // previous run happened to report.
    expect($recover)
        ->toContain('awaiting-code')
        ->toContain('ready-to-resume')
        ->toContain('which this script does not recognise');

    // awaiting-code builds and deploys; ready-to-resume does neither. An
    // identical rebuild of an already-deployed tree is work with a risk and no
    // purpose, and the workflow reads the decision from the server's answer.
    foreach (array_keys(disasterRecoveryWorkflows()) as $file) {
        [, $source] = recoverWorkflow($file);

        expect($source)
            ->toContain('build_required=no')
            ->toContain('deploy_required=no')
            ->toContain('resume_required=yes');
    }

    // And a recovery that already FINISHED is the case an operator meets with
    // the finished run's own operation ID in hand. It refuses — but as a
    // completed recovery, not as a machine that lost its state, because the
    // response to the second reading is to recover a healthy host all over
    // again.
    expect($recover)
        ->toContain('has already completed on')
        ->toContain('do not re-run the recovery workflow in either mode');
});

// =============================================================================
// 8. What "recovered" means
// =============================================================================

it('declares success only from an independent reading of the final contract', function () {
    $recover = disasterRecoveryScript('recover-host');

    // --verify takes no operation and reads no operation state: it asks the
    // host as it stands whether it is a recovered, serving target. Nothing
    // else in the chain is allowed to declare success, and it is asked even
    // when the resume's transport died, because a recovery that finished on
    // the machine is finished whatever the runner saw.
    expect($recover)
        ->toContain('still carries a recovery guard')
        ->toContain('carries a restore guard')
        ->toContain('has no current release symlink')
        ->toContain('RECOVERED: YES')
        ->toContain('HEALTH: PASS   QUEUE: RUNNING   SCHEDULER: PRESENT')
        ->toContain('OFFSITE WRITES: HELD');

    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        // The verification proves the CONTRACT; it does not know which
        // operation it was for, so the workflow makes that last identity check
        // itself. A host that is healthy on some other release can never read
        // as this recovery having succeeded.
        $steps = collect(data_get($workflow, 'jobs.verify.steps', []))
            ->filter(static fn (array $step): bool => isset($step['name']))
            ->keyBy('name');

        expect(data_get($steps['Prove the verified host serves the commit its data belongs to'], 'run'))
            ->toContain('if [[ "${SOURCE_SHA}" != "${REQUIRED_SOURCE_SHA}" ]]; then')
            ->toContain('if [[ "${OFFSITE_WRITES}" != "held" ]]; then');
    }
});

// =============================================================================
// 9. A rehearsal never writes to the real offsite namespace
// =============================================================================

it('fences the recovered machine out of the real offsite namespace, and never unfences it', function () {
    // A recovered machine is a COMPLETE host: it has the backup cron, the
    // uploader and the pruner, and it believes it is the target it was
    // recovered as. Two writers and two pruners on one namespace is how a
    // rehearsal deletes the backups it exists to protect.
    foreach (['backup-cycle', 'offsite-backup', 'offsite-retention'] as $writer) {
        expect(str_contains(disasterRecoveryScript($writer), 'assert_no_offsite_write_hold'))
            ->toBeTrue("{$writer} does not refuse on the offsite-write hold");
    }

    // One refusal, in the shared helper, before the lock, the history and the
    // first child — so a writer cannot half-run and then discover the fence.
    // The local backup and the local restore test are deliberately unaffected:
    // the fence is about the shared namespace, not about the machine working.
    expect(disasterRecoveryScript('common'))
        ->toContain('OFFSITE WRITES: HELD —')
        ->toContain('releasing it is part of deliberately adopting this machine, never a side effect of any operation');

    // Placed by the preparation, re-proved by every later recovery mode, and
    // released by nothing: adopting the machine is a deliberate act with
    // DEPLOY_HOST and DNS, and it is not part of a recovery.
    $recover = disasterRecoveryScript('recover-host');

    expect($recover)
        ->toContain('ensure_offsite_writes_held')
        ->toContain('assert_offsite_writes_held');

    expect(executableSourceLines($recover))->not->toMatch('/\brm\b[^\n]*offsite-write-hold/');
});

// =============================================================================
// 10. The old machine, the binding and DNS
// =============================================================================

it('moves no traffic and repoints no binding: a recovery is not an adoption', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow, $source] = recoverWorkflow($file);

        // A rehearsal that silently repointed the target would be an
        // uncontrolled cutover, and the operator would find out from traffic.
        $executable = executableSourceLines($source);

        foreach ([
            'gh variable set',
            'gh secret set',
            'gh api',
            'git push',
            'DEPLOY_HOST=',
        ] as $mutation) {
            expect($executable)->not->toContain($mutation);
        }

        // The committed registry is READ — a recovery refuses a target that is
        // not active — and never written. Asserted line by line rather than by
        // absence, because "the registry is not mentioned" would forbid the
        // refusal that makes production safe.
        foreach (preg_split('/\R/', $executable) as $line) {
            if (! str_contains($line, 'deployment-targets.json')) {
                continue;
            }

            foreach (['>', 'tee ', 'mv ', 'cp ', 'sed -i', 'jq -i'] as $write) {
                expect(str_contains($line, $write))
                    ->toBeFalse("{$file} writes to the target registry: ".trim($line));
            }
        }

        // GITHUB_TOKEN can write nothing: the workflow's whole permission is
        // to read the repository it builds from.
        expect(data_get($workflow, 'permissions'))->toBe(['contents' => 'read']);

        // And it says so where an operator reads it, on every path.
        expect($source)
            ->toContain('| DEPLOY_HOST | unchanged |')
            ->toContain('| DNS | unchanged |');
    }
});

// =============================================================================
// 11. Two identities, and no path between them
// =============================================================================

it('keeps the privileged bootstrap identity and the restricted deploy identity apart', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        foreach (disasterRecoveryActionInputs($workflow) as $used) {
            // The privileged identity prepares and recovers a machine; the
            // restricted one deploys to it and reports to Nightwatch. An
            // ordinary deployment credential must never become a root
            // credential, and a recovery credential must never be handed to
            // the historical code being rebuilt.
            if ($used['input'] === 'bootstrap-ssh-key') {
                expect($used['value'])->toBe('${{ secrets.RECOVERY_BOOTSTRAP_SSH_KEY }}', "{$file}: {$used['job']} bootstraps with the wrong key");
            }

            if ($used['input'] === 'ssh-private-key') {
                expect($used['value'])->toBe('${{ secrets.DEPLOY_SSH_KEY }}', "{$file}: {$used['job']} deploys with the wrong key");
            }
        }

        // The historical build is the one job that compiles an arbitrary
        // commit out of this repository's past, chosen by a backup rather than
        // by a person. It holds no GitHub Environment and therefore no secret
        // at all.
        expect(data_get($workflow, 'jobs.build.environment'))->toBeNull("{$file}: the historical build holds a GitHub Environment");
        expect(json_encode(data_get($workflow, 'jobs.build')))
            ->not->toContain('secrets.');
    }
});

// =============================================================================
// 12. Four operations, four preconditions
// =============================================================================

it('keeps restore, repair, prepare and recover as four operations with four preconditions', function () {
    // Healthy host + restore data            -> Restore Target
    // Healthy host + broken infrastructure   -> Repair Target
    // New or bare prepared host              -> Prepare Host
    // The whole machine is gone              -> Recover Host
    //
    // The failure this prevents is one operation quietly becoming a fallback
    // for another: a recovery that repairs whatever it finds is a recovery
    // with no precondition, which is a recovery that can run over a live host.
    $recover = disasterRecoveryScript('recover-host');

    expect(executableSourceLines($recover))
        ->not->toContain('repair-target')
        ->not->toContain('restore-target ');

    // A recovery demands an EMPTY prepared host and says so field by field.
    expect($recover)
        ->toContain('prepared/EMPTY recovery contract')
        ->toContain('a recovery restores into the EMPTY database Prepare Host created');

    // A live restore demands the opposite, and neither can start while the
    // other's guard exists — which is what makes "both guards present" a state
    // no automatic reading of is safe.
    expect(disasterRecoveryScript('common'))
        ->toContain('A live restore requires a deployed target and a host recovery requires an empty one');
});

// =============================================================================
// 13. One target, one mutation at a time
// =============================================================================

it('lets nothing else mutate a target while a recovery owns it', function () {
    // Every ordinary mutation calls ONE gate, and the gate refuses on either
    // guard. Giving them a single call is what stops a future guard from being
    // added to five of the six scripts that need it.
    foreach (['deploy', 'rollback', 'cleanup', 'backup', 'restore-target'] as $script) {
        expect(disasterRecoveryScript($script))
            ->toContain('assert_no_operation_hold');
    }

    // repair-target is deliberately not in that list: it is the diagnostic
    // orchestrator, and a guard is something it must REPORT rather than die
    // on. It still refuses to converge anything.
    expect(disasterRecoveryScript('repair-target'))
        ->toContain('recovery guard: ')
        ->toContain('guards:conflict');

    // And the whole GitHub chain — prepare, recover, build, controlled deploy,
    // resume, verify — is one logical mutation of one target, so it shares one
    // concurrency group with every other operation on that target and never
    // cancels itself in progress.
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        expect(data_get($workflow, 'concurrency.cancel-in-progress'))
            ->toBeFalse("{$file} may cancel a recovery in progress");
    }
});

// =============================================================================
// 14. A verified recovery is recorded
// =============================================================================

it('records the recovered release on both operator paths, and never lets that recording fail a recovery', function () {
    foreach (disasterRecoveryWorkflows() as $file => [$target, $environment]) {
        [$workflow] = recoverWorkflow($file);

        // The final verification decides, and it decides alone. A job with no
        // condition would inherit GitHub's default success() — "no job in my
        // ancestry failed or was skipped" — and the continue-held path is
        // legitimately full of skipped stages. The scenarios are run for real
        // in RecoverWorkflowsTest; this states which gate the contract has.
        expect(data_get($workflow, 'jobs.observability.if'))
            ->toBe("\${{ always() && needs.verify.result == 'success' }}", "{$file}: the marker is gated on something other than the final verification");
    }

    // One marker implementation, shared with deploy, release, rollback and
    // restore, and fail-open throughout: an unreachable Sentry or a Nightwatch
    // outage must never turn a host that is provably recovered into a recovery
    // an operator believes failed.
    expect(File::get(base_path('.github/actions/record-rateguru-deployment/action.yml')))
        ->toContain('FAIL-OPEN IS THE CONTRACT');
});

// =============================================================================
// 15. Production is planned, and stays planned
// =============================================================================

it('refuses a production recovery before an approval, a secret, a connection or a mutation', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect(data_get($registry, 'targets.tits-guru.lifecycle'))->toBe('planned');

    [$workflow] = recoverWorkflow('recover-production.yml');

    // The lifecycle is read from the COMMITTED registry, in the first job —
    // which deliberately holds no GitHub Environment. So a real production
    // dispatch fails before an approval is requested, before a secret is
    // loaded and before anything is connected to, rather than at the first
    // step that happens to need something production does not have.
    expect(data_get($workflow, 'jobs.validate.environment'))
        ->toBeNull('the production recovery asks for an approval before it refuses a planned target');

    $validate = json_encode(data_get($workflow, 'jobs.validate'));

    expect($validate)
        ->toContain('lifecycle')
        ->not->toContain('secrets.');

    // The refusal is a refusal, not a dry run that changes state and reports
    // it would not have: nothing before it touches a machine.
    expect(array_key_first((array) data_get($workflow, 'jobs')))->toBe('validate');
});
