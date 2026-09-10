<?php

use Illuminate\Support\Facades\File;

/**
 * Host recovery's own scope guard: what it establishes, and — more importantly
 * — everything it deliberately does not begin.
 *
 * It ends when a lost target can be rebuilt onto a prepared replacement
 * machine from one exact offsite backup and the exact commit that backup
 * names, with the host left deliberately not serving until that commit
 * arrives. The named operator workflows that press the button have since
 * landed and are guarded by RecoverWorkflowsTest; the disposable-host
 * rehearsal, the measured RPO/RTO, production activation and DNS cutover are
 * each their own later work; the rejected durable artifact archive stays
 * rejected; and every
 * accepted operation — deploy, rollback, backup, restore-test, Prepare Host,
 * live Restore, controlled restore alignment, Repair Target — behaves exactly
 * as it did.
 */

// =============================================================================
// What host recovery adds
// =============================================================================

it('adds exactly one server primitive and one transport action', function () {
    expect(File::exists(base_path('infrastructure/scripts/recover-host')))->toBeTrue();
    expect(File::exists(base_path('.github/actions/recover-rateguru-host/action.yml')))->toBeTrue();

    // infrastructure/scripts stays exactly the CLI manifest plus the two
    // sourced libraries — recovery adds a CLI, never a third library and never
    // an unmanifested script.
    $flat = collect(glob(base_path('infrastructure/scripts/*')) ?: [])
        ->filter(static fn (string $path): bool => is_file($path))
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    $expected = [...requiredCliManifestNames(), ...sourcedLibraryNames()];
    sort($expected);

    expect($flat)->toBe($expected);
    expect(requiredCliManifestNames())->toContain('recover-host');
});

it('installs recover-host through the existing installer, and adds no second installer family', function () {
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    // It is part of the operational bundle for one specific reason: `deploy`
    // runs `recover-host --inspect` as its authoritative proof that a
    // replacement host is still held, and a deployment cannot depend on a
    // binary that only exists inside a per-run bundle.
    expect($installer)
        ->toContain('infrastructure/scripts/recover-host')
        ->toContain('DST_BIN_ROOT}/recover-host"')
        ->toContain('install_regular_file_transactional "${STAGE_DIR}/recover-host" "${DST_RECOVER_HOST}" "${INSTALL_OWNER}" "${INSTALL_GROUP}" "${CLI_MODE}"');

    // prepare-host and repair-target stay transported per run: neither is ever
    // reached by a deployment.
    foreach (['prepare-host', 'repair-target'] as $transported) {
        expect($installer)->not->toContain('scripts/'.$transported);
    }

    // The external-material installer joins the bundle for the same reason,
    // with the committed vhost sources it derives its table from: backup
    // captures the recovery material through it, and restore-test and
    // verify-backup judge that material through it, on a live host.
    expect($installer)
        ->toContain('infrastructure/scripts/install-target-prerequisites')
        ->toContain('DST_BIN_ROOT}/install-target-prerequisites"')
        ->toContain('DST_CONFIG_ROOT}/nginx"');

    // The authoritative counts were updated honestly, not left stale.
    expect($installer)
        ->toContain('twenty-eight files')
        ->toContain('all twenty-eight source files are present regular files')
        ->toContain('bash -n passed for all twenty-two source shell scripts')
        ->not->toContain('twenty-three files')
        ->not->toContain('all twenty-one source');

    // And no second installer was created for it.
    $installers = collect(glob(base_path('infrastructure/scripts/install-*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->all();

    expect($installers)->not->toContain('install-recover-host')
        ->not->toContain('install-target-recovery');
});

it('gives the controlled recovery deployment the same authoritative hold proof a restore alignment has', function () {
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    // deploy re-derives NEITHER proof: each is delegated to the one read-only
    // implementation that owns it.
    $authorization = shellFunctionBody($deploy, 'assert_recovery_alignment_authorized');

    expect($authorization)
        ->toContain('"${RECOVER_HOST_BIN}"')
        ->toContain('--inspect')
        ->toContain('RATEGURU_RECOVER_RESULT=')
        ->toContain('expected exactly one');

    // The three runtime facts it demands of that result, none of which deploy
    // decides for itself.
    expect($authorization)
        ->toContain('.queue // empty')
        ->toContain('.scheduler // empty')
        ->toContain('.current_release // empty');

    // And deploy grows no cron reader for recovery: the queue reader it
    // already has belongs to the ordinary deployment.
    expect(executableSourceLines($deploy))->not->toContain('cron.d');

    // The proof itself lives in recover-host, once each, and is what
    // --inspect, --apply, --resume and the deployment all reach.
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    expect(substr_count($recover, "\nassert_runtime_still_held() {"))->toBe(1);
    expect(substr_count($recover, "\nassert_runtime_resumed() {"))->toBe(1);
    expect(substr_count($recover, "\nclassify_recovery_stage() {"))->toBe(1);
});

it('recognises both safe recovery stages, and lets only one of them be deployed into', function () {
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));
    $classifier = shellFunctionBody($recover, 'classify_recovery_stage');

    // Two stages, because a recovery outlives the workflow that drives it: a
    // controlled deployment that succeeded and then lost its runner is a
    // legitimate resumable state, not damage.
    expect($classifier)
        ->toContain('RECOVERY_STAGE="awaiting-code"')
        ->toContain('RECOVERY_STAGE="ready-to-resume"');

    // The runtime half is non-negotiable in BOTH: code arriving is what should
    // happen next, a worker starting is not.
    expect(mb_strpos($classifier, 'assert_runtime_still_held'))
        ->toBeLessThan(mb_strpos($classifier, 'RECOVERY_STAGE="awaiting-code"'));

    // And only the first is deployable: the deployment demands awaiting-code,
    // so a second deployment into an already-deployed recovery is refused.
    expect(shellFunctionBody(File::get(base_path('infrastructure/scripts/deploy')), 'assert_recovery_alignment_authorized'))
        ->toContain('not awaiting-code');

    // --resume takes only the second.
    expect(shellFunctionBody($recover, 'perform_resume'))
        ->toContain('"${RECOVERY_STAGE}" == "ready-to-resume"');
});

it('serialises against a running data operation on the lock, not only on its guard', function () {
    // A guard is a long-lived interlock, not a mutual exclusion primitive: two
    // processes can both read "no guard" at the same instant, and an operation
    // takes its lock before it writes its guard. Both halves are needed, and
    // they cover different windows — the lock covers "started, not yet
    // guarded", the guard covers "exited, still owned".
    $prepare = File::get(base_path('infrastructure/scripts/prepare-host'));
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    // ONE OWNER PER LOCK. Preparation runs install-target-operations, which
    // takes the two data-operation locks itself — so holding those across the
    // run would deadlock a Prepare against its own grandchild, because flock
    // treats independently opened descriptors independently even inside one
    // process tree. Preparation therefore owns a lock of its own and only
    // CHECKS theirs, releasing each immediately.
    expect(executableSourceLines($prepare))
        ->toContain('prepare-host-${namespace}.lock')
        ->toContain('${prefix}-${namespace}.lock')
        ->toContain('exec {lock_fd}>&-')
        ->toContain('flock -n');

    // The installer keeps holding both, because nothing runs underneath it that
    // wants them.
    expect(executableSourceLines($installer))
        ->toContain('restore-target')
        ->toContain('recover-host')
        ->toContain('${prefix}-${namespace}.lock')
        ->toContain('flock -n');

    // And the other side of the pair: the data operations check preparation's
    // lock after taking their own, so whichever starts first is the one that
    // wins and the second always refuses.
    expect(substr_count(File::get(base_path('infrastructure/scripts/restore-common')), "\nassert_no_host_preparation_running() {"))->toBe(1);

    foreach (['restore-target', 'recover-host'] as $operation) {
        $source = File::get(base_path('infrastructure/scripts/'.$operation));

        expect($source)->toContain('assert_no_host_preparation_running "${BACKUP_NAMESPACE}"');

        // Own lock FIRST, preparation's second — the ordering is what makes
        // the pair airtight.
        expect(mb_strpos($source, 'flock -n 2'))
            ->toBeLessThan(mb_strpos($source, 'assert_no_host_preparation_running'));
    }

    // And the recovery writes its guard before it downloads anything, so the
    // guard covers the staging window too rather than only the activation.
    $pipeline = shellFunctionBody(File::get(base_path('infrastructure/scripts/recover-host')), 'perform_recovery');

    expect(mb_strpos($pipeline, 'write_recovery_guard in-progress'))
        ->toBeLessThan(mb_strpos($pipeline, 'RESTORE_FETCH_BACKUP_BIN'));
});

it('refuses to prepare a host another data operation is holding', function () {
    $prepare = File::get(base_path('infrastructure/scripts/prepare-host'));

    // Preparation's children reconverge the target's Supervisor program and
    // its scheduler cron entry, and both a restore and a recovery hold exactly
    // those aside on purpose. An --apply in that window could put the
    // scheduler back and start a worker against data whose code has not
    // arrived. GitHub concurrency is not enough: a hold outlives the workflow
    // that created it.
    expect($prepare)
        ->toContain('restores/${TARGET_ID}/restore-guard')
        ->toContain('recoveries/${TARGET_ID}/recovery-guard')
        ->toContain('preparation is refused while a data operation owns');

    // The gate runs before any child, in every mode — before the ordinary
    // slice loop and before the recovery pipeline alike.
    $apply = shellFunctionBody($prepare, 'run_apply');

    expect(mb_strpos($apply, 'gate_no_data_operation_hold'))
        ->toBeLessThan(mb_strpos($apply, 'converge_slice'))
        ->toBeLessThan(mb_strpos($apply, 'run_recovery_slices'));

    foreach (['run_check', 'run_verify'] as $readOnly) {
        expect(shellFunctionBody($prepare, $readOnly))->toContain('gate_no_data_operation_hold');
    }

    // Read-only modes report and continue; only --apply refuses. An operator
    // looking at a held target needs the diagnosis.
    $gate = shellFunctionBody($prepare, 'gate_no_data_operation_hold');

    expect($gate)->toContain('[[ "${MODE}" != apply ]]');

    // Existence-based, never a second parser for the guard documents: `common`
    // owns that, prepare-host cannot source it, and a copy here would drift.
    expect($gate)->not->toContain('required_source_sha');
});

it('reuses the existing backup primitives and implements none of them again', function () {
    $source = executableSourceLines(File::get(base_path('infrastructure/scripts/recover-host')));

    expect($source)
        ->toContain('"${RESTORE_FETCH_BACKUP_BIN}"')
        ->toContain('"${RESTORE_VERIFY_BACKUP_BIN}"')
        ->toContain('"${RESTORE_DATABASE_BIN}"')
        ->toContain('"${RESTORE_STORAGE_BIN}"');

    foreach ([
        'rclone',
        'sha256sum',
        'pg_restore',
        'createdb',
        'manifest.json',
        'SHA256SUMS',
        'tar -xzf',
        'tar -tzf',
    ] as $reimplementation) {
        expect($source)->not->toContain($reimplementation, "recover-host must not reimplement: {$reimplementation}");
    }
});

it('keeps one implementation of every shared data-operation concern', function () {
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    foreach ([
        'restore_read_operation_kind()',
        'restore_require_step_phase()',
        'restore_locate_operation_root()',
        'restore_assert_application_role_safe()',
        'restore_application_query()',
        'observe_queue_program()',
        'recovery_history_append()',
    ] as $function) {
        expect(substr_count($library, "\n{$function} {"))->toBe(1, "{$function} must be defined exactly once in restore-common");
    }

    // The guards and the recovery authorization live in common, once each,
    // because deploy must read them without sourcing the restore library.
    $common = File::get(base_path('infrastructure/scripts/common'));

    foreach ([
        'recovery_guard_file()',
        'assert_no_recovery_hold()',
        'assert_no_conflicting_operation_holds()',
        'assert_no_operation_hold()',
        'recovery_operation_state_file()',
        'assert_recovery_alignment_operation()',
    ] as $function) {
        expect(substr_count($common, "\n{$function} {"))->toBe(1, "{$function} must be defined exactly once in common");
    }

    // And neither CLI redefines any of them.
    foreach (['recover-host', 'restore-target', 'restore-database', 'restore-storage', 'deploy'] as $cli) {
        $cliSource = File::get(base_path('infrastructure/scripts/'.$cli));

        foreach ([
            'recovery_guard_file()',
            'assert_no_recovery_hold()',
            'observe_queue_program()',
            'restore_require_step_phase()',
        ] as $function) {
            expect($cliSource)->not->toContain("\n{$function} {");
        }
    }
});

// =============================================================================
// Its own state machine, never a masquerade
// =============================================================================

it('uses its own namespace, guard and journal rather than a restore operation wearing a label', function () {
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));
    $library = File::get(base_path('infrastructure/scripts/restore-common'));
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect($library)
        ->toContain('RESTORE_OPERATION_NAMESPACE_HOST_RECOVERY=recoveries')
        ->toContain('RESTORE_OPERATION_NAMESPACE_TARGET_RESTORE=restores');

    expect($common)->toContain("printf '%s/recoveries/%s/recovery-guard\\n'");
    expect($library)->toContain("printf '%s/recovery-history.jsonl\\n'");

    // A recovery never writes a restore guard, never reads one as its own, and
    // never uses the restore history journal.
    expect(executableSourceLines($recover))
        ->not->toContain('write_restore_guard')
        ->not->toContain('clear_restore_guard')
        ->not->toContain('restore_history_append');

    // The emergency-backup phase is a live restore's strongest safety claim,
    // and a recovery that took no emergency backup must never write it.
    expect($recover)->not->toContain('emergency-backup-verified');
    expect($recover)->toContain('recovery-activation-authorized');
});

it('takes no emergency backup, enters no maintenance mode and runs no migration', function () {
    $source = executableSourceLines(File::get(base_path('infrastructure/scripts/recover-host')));

    foreach ([
        'RESTORE_BACKUP_BIN',
        'RESTORE_RESTORE_TEST_BIN',
        'artisan down',
        'artisan up',
        'artisan migrate',
        'artisan_as_runtime_user',
        'framework/down',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden, "a host recovery must never: {$forbidden}");
    }
});

it('reads its operation kind from persisted state, never from a command line', function () {
    // The two primitives that perform the staged swap take a STEP name and
    // look the allowed phases up from the kind the state records. Neither
    // accepts a kind, a context or a phase as an argument.
    foreach (['restore-database', 'restore-storage'] as $primitive) {
        $source = File::get(base_path('infrastructure/scripts/'.$primitive));

        expect($source)->toContain('restore_require_step_phase "${STATE_FILE}" "${TARGET_ID}"');

        foreach (['--kind', '--operation-kind', '--context', '--phase', '--recovery'] as $rejected) {
            expect($source)->not->toContain("{$rejected})");
        }
    }

    // And the kind is read through the one library function, which fails on an
    // unknown value rather than falling back to the weaker gate.
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    expect($library)->toContain('operation state records an unknown operation kind');
});

// =============================================================================
// Every ordinary operation refuses while a recovery owns a target
// =============================================================================

it('makes every ordinary target mutation refuse while a recovery guard exists', function () {
    foreach (['backup', 'deploy', 'rollback', 'cleanup', 'restore-target'] as $script) {
        expect(File::get(base_path('infrastructure/scripts/'.$script)))
            ->toContain('assert_no_operation_hold "${TARGET_ID}" "${RUN_ROOT}"');
    }

    // repair-target reports through its own item vocabulary rather than a
    // single refusal, and blocks on the guard in its own apply gate.
    $repair = File::get(base_path('infrastructure/scripts/repair-target'));

    expect($repair)
        ->toContain('RECOVERY-HOLD')
        ->toContain('recovery_guard_status')
        ->toContain('repair-target --apply refuses while any recovery guard exists');

    // Nothing but recover-host writes or clears a recovery guard.
    foreach (operationalFiles() as $path) {
        if (basename($path) === 'recover-host' || basename($path) === 'common') {
            continue;
        }

        foreach (['write_recovery_guard', 'clear_recovery_guard'] as $forbidden) {
            expect(File::get($path))->not->toContain($forbidden);
        }
    }
});

// =============================================================================
// The controlled recovery deployment: one deploy, one runtime policy fork
// =============================================================================

it('extends the existing deploy rather than adding a second deployment implementation', function () {
    // One script whose job is deploying an application release. The
    // Nightwatch marker recorder is deliberately not one of them — it deploys
    // nothing and never switches a release.
    $deployScripts = collect(glob(base_path('infrastructure/scripts/*deploy*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->reject(static fn (string $name): bool => $name === 'record-nightwatch-deployment')
        ->sort()
        ->values()
        ->all();

    expect($deployScripts)->toBe(['deploy']);

    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    expect($deploy)
        ->toContain('--recovery-operation')
        ->toContain('--restore-operation and --recovery-operation are mutually exclusive')
        ->toContain('--migrate and --recovery-operation are mutually exclusive');

    // Both mutual exclusions are enforced in PARSING, before anything is
    // resolved, locked or extracted.
    foreach ([
        '--restore-operation and --recovery-operation are mutually exclusive',
        '--migrate and --recovery-operation are mutually exclusive',
    ] as $rule) {
        expect(mb_strpos($deploy, $rule))->toBeLessThan(mb_strpos($deploy, "\nperform_deploy() {"));
    }
});

it('never resumes a target during a controlled recovery deployment', function () {
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    // The recovery path returns from perform_deploy before the ordinary
    // post-switch sequence — that ordering is the guarantee.
    $fork = mb_strpos($deploy, 'if recovery_alignment_mode; then'.PHP_EOL.'        finalize_recovery_alignment');

    expect($fork)->not->toBeFalse('the recovery post-switch fork is missing');
    expect($fork)->toBeLessThan(mb_strpos($deploy, '"${HEALTH_CHECK_BIN}"'));
    expect($fork)->toBeLessThan(mb_strpos($deploy, 'perform_queue_transition'.PHP_EOL.PHP_EOL));

    $section = shellFunctionBody($deploy, 'finalize_recovery_alignment');

    foreach ([
        'HEALTH_CHECK_BIN',
        'perform_queue_transition',
        'perform_nightwatch_transition',
        'artisan up',
        'supervisorctl start',
        'clear_recovery_guard',
        'cron.d',
        'artisan migrate',
        'PREVIOUS_LINK}.new',
    ] as $forbidden) {
        expect($section)->not->toContain($forbidden, "a recovery deployment must never: {$forbidden}");
    }

    // previous stays ABSENT: a rebuilt host has no earlier release, and
    // synthesising one would arm a rollback to undo the recovery.
    expect($section)->toContain('previous is deliberately absent');
});

it('reads the required commit from the server, never from the caller', function () {
    $action = File::get(base_path('.github/actions/deploy-rateguru/action.yml'));

    // The action passes only the operation ID, and nothing that could name a
    // commit, a release or a build.
    expect($action)->toContain('--recovery-operation %q');

    foreach (['source-sha', 'source_sha', 'required-sha', 'commit'] as $rejected) {
        expect($action)->not->toContain($rejected.':');
    }

    // The server reads it from its own two documents.
    $common = File::get(base_path('infrastructure/scripts/common'));

    expect(shellFunctionBody($common, 'assert_recovery_alignment_operation'))
        ->toContain('recovery_guard_file')
        ->toContain('recovery_operation_state_file')
        ->toContain('refusing to deploy into a host whose own recovery documents disagree');
});

// =============================================================================
// What this deliberately does NOT begin
// =============================================================================

it('adds no rehearsal harness and no host provisioner', function () {
    // The two named operator workflows are the ONE operator surface this
    // primitive was always going to grow, and they landed with their own scope
    // guard (RecoverWorkflowsTest). The generic TARGET provisioner has landed
    // since, separately, with its own scope guard (ProvisionTargetTest) — and
    // recovery gained nothing from it: it neither calls it nor is called by
    // it. What is still deliberately absent is a rehearsal harness that
    // automates a disposable machine and a HOST provisioner that creates one.
    foreach ([
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/workflows/rehearse-recovery.yml',
        'infrastructure/scripts/provision-host',
        'infrastructure/scripts/rehearse-recovery',
    ] as $laterWork) {
        expect(File::exists(base_path($laterWork)))
            ->toBeFalse("{$laterWork} is later work and must not exist yet");
    }

    // Recovery and target provisioning stay strangers to each other.
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/recover-host'))))
        ->not->toContain('provision-target');
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/provision-target'))))
        ->not->toContain('recover-host');

    // One recovery workflow per environment, named for the environment, like
    // every other operator-facing operation here — and no third one.
    $workflows = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($workflows)->toBe([
        'ci.yml',
        'coverage.yml',
        'deploy-staging.yml',
        'label-review-bot-prs.yml',
        'prepare-production-host.yml',
        'prepare-staging-host.yml',
        'recover-production.yml',
        'recover-staging.yml',
        'release.yml',
        'repair-production.yml',
        'repair-staging.yml',
        'restore-production.yml',
        'restore-staging.yml',
        'rollback-production.yml',
        'rollback-staging.yml',
    ]);
});

it('adds no durable release-artifact archive', function () {
    foreach (operationalFiles() as $path) {
        $source = File::get($path);

        foreach ([
            'rateguru-release-artifacts',
            'B2_ARTIFACT_',
            'ARTIFACT_BUCKET',
            'artifact_retention',
            'rateguru/artifacts/',
        ] as $rejected) {
            expect($source)->not->toContain($rejected);
        }
    }

    // The recovery primitive rebuilds from a commit, and stores no artifact.
    $recover = executableSourceLines(File::get(base_path('infrastructure/scripts/recover-host')));

    foreach (['tar.gz', 'artifact', 'composer', 'npm ', 'git clone', 'git checkout'] as $forbidden) {
        expect($recover)->not->toContain($forbidden, "recover-host must never handle a build artifact: {$forbidden}");
    }

    // And the documentation says so rather than promising one later.
    expect(File::get(base_path('infrastructure/runbooks/backups.md')))
        ->toContain('there is deliberately no durable artifact archive')
        ->not->toContain('A durable, immutable artifact archive is separate, planned work');
});

it('activates no production target, provisions nothing and changes no DNS', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    foreach (operationalFiles() as $path) {
        $source = File::get($path);

        foreach (['cloudflare', 'route53', 'dns_record', 'certbot --force-renewal'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }

    // The registry stays free of physical host addresses: a TARGET is a
    // logical identity and a HOST is a replaceable machine.
    foreach ($registry['targets'] as $target) {
        expect($target)->not->toHaveKey('host');
        expect($target)->not->toHaveKey('ip');
        expect($target)->not->toHaveKey('deploy_host');
    }

    // And the recovery primitive never edits the registry or an environment.
    expect(executableSourceLines(File::get(base_path('infrastructure/scripts/recover-host'))))
        ->not->toContain('DEPLOY_HOST')
        ->not->toContain('deployment-targets.json');
});

it('records no observability marker of its own', function () {
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    foreach (['sentry', 'Sentry', 'nightwatch', 'Nightwatch', 'record-rateguru-deployment'] as $forbidden) {
        expect($recover)->not->toContain($forbidden);
    }

    // There is still exactly one marker implementation.
    expect(File::exists(base_path('.github/actions/record-rateguru-deployment/action.yml')))->toBeTrue();
});

// =============================================================================
// Backward compatibility
// =============================================================================

it('leaves every accepted operational surface it does not extend untouched', function () {
    $changed = branchChangedCodeFiles();

    // The host-global services, the host perimeter, the SSH restriction, mail
    // capture, the wrappers and the sudoers grants: a recovery reuses all of
    // them and adds nothing to any. install-target-operations and prepare-host
    // are deliberately NOT on this list — the first installs recover-host so a
    // deployment can reach it, the second gained the interlock that keeps it
    // off a held target — and both are asserted positively above. The backup
    // writers are not on it either: they read the shared offsite-write hold,
    // and that is asserted structurally below.
    foreach ([
        'infrastructure/scripts/install-target-perimeter',
        'infrastructure/scripts/install-mail-capture',
        'infrastructure/config/cron/rateguru-backups',
        'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        'infrastructure/config/cron/rateguru-staging-scheduler',
        'infrastructure/config/deployment-targets.json',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }

    // No new wrapper and no new sudoers grant: the recovery credential is the
    // existing privileged bootstrap one, and it needs neither.
    foreach (['infrastructure/config/wrappers', 'infrastructure/config/ssh', 'infrastructure/config/sudoers'] as $directory) {
        foreach ($changed as $path) {
            expect($path)->not->toStartWith($directory.'/');
        }
    }
})->skip(fn (): bool => branchBaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('adds no wrapper and no sudoers grant of its own', function () {
    $wrappers = collect(glob(base_path('infrastructure/config/wrappers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($wrappers)->toBe([
        'rateguru-cleanup',
        'rateguru-deploy',
        'rateguru-nightwatch-deployment',
        'rateguru-restore',
        'rateguru-rollback',
    ]);

    $sudoers = collect(glob(base_path('infrastructure/config/sudoers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($sudoers)->toBe(['rateguru-deploy', 'rateguru-nightwatch-deployment']);

    // The deploy account can never reach a recovery: the restricted wrappers
    // name only the operations that credential is for.
    foreach (array_merge(
        glob(base_path('infrastructure/config/wrappers/*')) ?: [],
        glob(base_path('infrastructure/config/sudoers/*')) ?: [],
    ) as $path) {
        expect(File::get($path))->not->toContain('recover-host');
    }
});

it('reads the one backup format common states, and defines no second one', function () {
    $common = File::get(base_path('infrastructure/scripts/common'));
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    // The closed file sets and the current manifest schema are stated once,
    // in common; restore-common binds to them and recover-host reads the
    // schema through the shared verification, never by parsing a manifest
    // itself.
    expect($common)
        ->toContain('BACKUP_MANIFEST_SCHEMA_CURRENT=3')
        ->toContain('TARGET_REGISTRY_SCHEMA_VERSION=1');

    expect($library)
        ->toContain('RESTORE_BACKUP_CHECKSUMMED_FILES=("${BACKUP_CHECKSUMMED_FILES_LEGACY[@]}")')
        ->toContain('RESTORE_BACKUP_SCHEMA3_CHECKSUMMED_FILES=("${BACKUP_CHECKSUMMED_FILES_SCHEMA3[@]}")');

    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    expect(executableSourceLines($recover))
        ->not->toContain('manifest_schema_version')
        ->not->toContain('database.dump');

    // The schema it requires is the verified identity's, and the requirement
    // is the shared one: a backup is clean-host-recovery-capable exactly when
    // it carries the recovery material.
    expect($recover)
        ->toContain('.backup_schema')
        ->toContain('not clean-host-recovery-capable');

    // Only backup writes a manifest, and it writes the current schema by
    // name; no operational file spells the number out a second time.
    foreach (operationalFiles() as $path) {
        $source = executableSourceLines(File::get($path));

        if (str_ends_with($path, '/scripts/backup')) {
            expect($source)->toContain('--argjson manifest_schema_version "${BACKUP_MANIFEST_SCHEMA_CURRENT}"');

            continue;
        }

        expect($source)->not->toContain('--argjson manifest_schema_version');
    }

    // The commit comes from the backup's existing release.json, never from a
    // new field duplicated for recovery.
    expect($recover)
        ->toContain('release.json')
        ->not->toContain('recovery_source_sha');
});

it('keeps the live restore phase gates byte-for-byte what they were', function () {
    // The kind table is the ONLY place the mapping lives, and the
    // target-restore column is exactly the set restore-database and
    // restore-storage demanded before host recovery existed.
    $table = shellFunctionBody(File::get(base_path('infrastructure/scripts/restore-common')), 'restore_require_step_phase');

    foreach ([
        'target-restore:stage-database)     allowed=(backup-verified)',
        'target-restore:stage-storage)      allowed=(database-staged)',
        'target-restore:activate-database)  allowed=(emergency-backup-verified)',
        'target-restore:activate-storage)   allowed=(database-activated)',
        'target-restore:commit)             allowed=(verified)',
    ] as $gate) {
        expect($table)->toContain($gate);
    }

    expect($table)->toContain('allowed=(emergency-backup-verified database-activated storage-activated verified)');

    // A legacy operation state with no operation_kind is a live target
    // restore, because nothing else could have written one.
    expect(shellFunctionBody(File::get(base_path('infrastructure/scripts/restore-common')), 'restore_read_operation_kind'))
        ->toContain('RESTORE_OPERATION_KIND="${RESTORE_OPERATION_KIND_TARGET_RESTORE}"');
});
