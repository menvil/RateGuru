<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface for host recovery: recover-staging.yml and
 * recover-production.yml.
 *
 * These are policy files. Every mechanism they use already exists — one
 * preparation, one recovery, one build, one deploy, one observability marker —
 * and the only thing they add is the decision of what runs when, for which
 * fixed target, against which machine.
 *
 * So this file asserts policy, and specifically the parts of it that are
 * dangerous to get wrong:
 *
 *   * the target is structural, never an operator input. Two named buttons,
 *     no dropdown, anywhere in the repository;
 *   * the required commit is never an operator input either. It flows backup
 *     -> verified release.json -> recovery state -> action output -> checkout,
 *     and nothing in that chain is typed by a person;
 *   * the REPLACEMENT machine is an operator input, and is the only thing that
 *     is: a recovery exists because the machine the environment names is gone.
 *     It must never be the machine the target is bound to now, and the machine
 *     the target is bound to now must never be touched;
 *   * the privileged recovery credential and the restricted deploy credential
 *     stay separate, with no fallback in either direction;
 *   * the historical build holds no environment and no secret. It compiles an
 *     arbitrary commit out of this repository's past, and it must never be
 *     able to reach a credential;
 *   * the whole chain shares ONE concurrency group, because prepare -> recover
 *     -> build -> controlled deploy -> resume -> verify is one logical
 *     mutation of one target;
 *   * nothing resumes the host except recover-host --resume, nothing declares
 *     success except recover-host --verify, and nothing records a marker until
 *     that verification passed.
 *
 * @return array{0: array, 1: string}
 */
function recoverWorkflow(string $file): array
{
    $path = base_path(".github/workflows/{$file}");

    expect(File::exists($path))->toBeTrue("{$file} is missing");

    $source = File::get($path);

    return [Yaml::parse($source), $source];
}

/** @return array<string, array> */
function recoverWorkflowStepsByName(array $workflow, string $job): array
{
    return collect(data_get($workflow, "jobs.{$job}.steps", []))
        ->filter(static fn (array $step): bool => isset($step['name']))
        ->keyBy('name')
        ->all();
}

/** Every `with:` value a workflow passes to any action, keyed by input name. */
function recoverWorkflowInputsUsed(array $workflow, string $name): array
{
    $values = [];

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        foreach ((array) data_get($job, 'steps', []) as $step) {
            $value = data_get($step, "with.{$name}");

            if ($value !== null) {
                $values[$jobName.' / '.($step['name'] ?? 'unnamed')] = $value;
            }
        }
    }

    return $values;
}

dataset('recover workflows', [
    'staging' => ['recover-staging.yml', 'Recover staging host', 'staging-main', 'staging', 'rateguru-staging-deployment'],
    'production' => ['recover-production.yml', 'Recover production host', 'tits-guru', 'production', 'rateguru-production-release'],
]);

// =============================================================================
// The operator surface
// =============================================================================

it('is manual-only, fixes its own target, and offers no target selector', function (
    string $file,
    string $name,
    string $target,
    string $environment,
    string $concurrency,
) {
    [$workflow] = recoverWorkflow($file);

    expect(data_get($workflow, 'name'))->toBe($name)
        ->and(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read']);

    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    // No target input under any name. The operator picks the workflow whose
    // title names the environment, and the target is a literal below.
    //
    // array_key_exists + toBeFalse rather than not->toHaveKey: toHaveKey's
    // SECOND argument is the expected VALUE, not a message
    // (toHaveKey($key, $value = new Any, $message = '')), so a negated
    // toHaveKey given a diagnostic asserts "does not have this key holding
    // that sentence" — which passes for every real value the key could hold,
    // and the guard silently stops guarding. toBeFalse takes a real message.
    foreach (['target', 'deployment-target', 'deployment_target', 'environment'] as $forbidden) {
        expect(array_key_exists($forbidden, $inputs))
            ->toBeFalse("{$file} must not let an operator choose a target: {$forbidden}");
    }

    // And no commit input under any name either: the operator names a backup
    // and a machine, never a commit.
    foreach ([
        'sha', 'source-sha', 'source_sha', 'required_source_sha', 'required-source-sha',
        'historical_sha', 'ref', 'branch', 'tag', 'release', 'source',
    ] as $forbidden) {
        expect(array_key_exists($forbidden, $inputs))
            ->toBeFalse("{$file} must not let an operator choose the code: {$forbidden}");
    }

    // Nor any of the things the server decides: migrations, the backup's
    // location, or a namespace to read it out of.
    foreach ([
        'run-migrations', 'run_migrations', 'migrations',
        'remote', 'bucket', 'path', 'namespace', 'command',
    ] as $forbidden) {
        expect(array_key_exists($forbidden, $inputs))
            ->toBeFalse("{$file} must not let an operator choose {$forbidden}");
    }

    // Every job that talks to the target does so at the fixed identity.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        foreach ((array) data_get($job, 'steps', []) as $step) {
            $stepTarget = data_get($step, 'with.deployment-target');

            if ($stepTarget !== null) {
                expect($stepTarget)->toBe($target, "{$file}:{$jobName} must act on {$target}");
            }

            $stepEnvironment = data_get($step, 'with.environment');

            if ($stepEnvironment !== null) {
                expect($stepEnvironment)->toBe($environment, "{$file}:{$jobName} must act in {$environment}");
            }
        }
    }
})->with('recover workflows');

it('offers exactly the two recovery modes and nothing that could name a backup vaguely', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);
    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    expect(data_get($inputs, 'mode.type'))->toBe('choice')
        ->and(data_get($inputs, 'mode.options'))->toBe(['start', 'continue-held'])
        ->and(data_get($inputs, 'mode.default'))->toBe('start')
        ->and(data_get($inputs, 'mode.required'))->toBeTrue();

    // No "latest" anywhere: a backup is named exactly, or not at all. And no
    // source selector — a recovery models the loss of the machine the local
    // backups lived on, so offsite is the only thing there is.
    expect(data_get($inputs, 'backup.type'))->toBe('string')
        ->and(data_get($inputs, 'backup.required'))->toBeFalse()
        ->and(data_get($inputs, 'backup.default'))->toBe('')
        ->and(data_get($inputs, 'operation.type'))->toBe('string')
        ->and(data_get($inputs, 'operation.required'))->toBeFalse()
        ->and(data_get($inputs, 'operation.default'))->toBe('');

    // No implicit selection of any kind: no "latest" backup, no source
    // selector, no local copy. The rejected shapes are named exactly, because
    // a bare `latest` would collide with `runs-on: ubuntu-latest`.
    expect(executableSourceLines($source))
        ->not->toContain('latest backup')
        ->not->toContain("'latest'")
        ->not->toContain('restore-source')
        ->not->toContain('backup: latest');

    // The replacement machine is the one thing an operator must supply, and
    // the port has the only default in the workflow.
    expect(data_get($inputs, 'replacement-host.type'))->toBe('string')
        ->and(data_get($inputs, 'replacement-host.required'))->toBeTrue()
        ->and(data_get($inputs, 'replacement-port.type'))->toBe('string')
        ->and(data_get($inputs, 'replacement-port.required'))->toBeFalse()
        ->and(data_get($inputs, 'replacement-port.default'))->toBe('22');
})->with('recover workflows');

it('enforces the request contract before any environment or secret is reached', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);

    // The validation job holds no GitHub Environment, so a malformed request
    // never even becomes an approval request. Its FIRST step is the request
    // check itself: nothing is fetched before the request is judged.
    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull()
        ->and(data_get($workflow, 'jobs.validate.permissions'))->toBe(['contents' => 'read'])
        ->and(data_get($workflow, 'jobs.validate.steps.0.uses'))->toBeNull();

    expect($source)
        ->toContain('mode=start requires an exact offsite backup timestamp YYYYMMDD-HHMMSS')
        ->toContain('mode=start must not name an operation')
        ->toContain('mode=continue-held requires the recovery operation ID')
        ->toContain('mode=continue-held must not name a backup')
        ->toContain('a new recovery must never be started over a held one')
        ->toContain('^[0-9]{8}-[0-9]{6}$')
        ->toContain('^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$');

    // Pasted IDs and addresses arrive with whitespace; it is stripped before
    // anything is judged, exactly as the restore workflows do it.
    expect($source)->toContain("tr -d '[:space:]'");

    // Every job that touches the target or the replacement machine depends on
    // that validation — not just the first one. A job later rewired to start
    // earlier would silently stop being gated, so each one names it directly.
    foreach (['binding', 'prepare', 'recover', 'build', 'deploy', 'resume', 'verify'] as $job) {
        expect(data_get($workflow, "jobs.{$job}"))->not->toBeNull("{$file} must define the {$job} job");

        // in_array + toBeTrue rather than toContain: toContain is variadic in
        // Pest, so a second "message" argument becomes another needle.
        expect(in_array('validate', (array) data_get($workflow, "jobs.{$job}.needs"), true))
            ->toBeTrue("{$file}:{$job} must not start before the request is validated");
    }
})->with('recover workflows');

it('treats the replacement address as data and refuses anything that is not one', function (
    string $file,
) {
    [, $source] = recoverWorkflow($file);

    expect($source)
        ->toContain("host_label='[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?'")
        ->toContain('replacement-host must be a DNS hostname or an IPv4 address')
        ->toContain('replacement-host is required')
        ->toContain('replacement-host is longer than a DNS name may be')
        ->toContain('^([0-9]{1,3}\.){3}[0-9]{1,3}$')
        ->toContain('replacement-port must be between 1 and 65535')
        // The leading-zero octal trap, in both places a number is compared.
        ->toContain('10#${replacement_port}')
        ->toContain('10#${octet}');
})->with('recover workflows');

it('refuses a target that is not active before any GitHub Environment is entered', function (
    string $file,
    string $name,
    string $target,
) {
    [$workflow, $source] = recoverWorkflow($file);

    $steps = recoverWorkflowStepsByName($workflow, 'validate');
    $names = array_keys($steps);

    // The lifecycle question is asked in the job that holds no environment,
    // and it is asked of the repository's own registry through its own CLI —
    // no lifecycle rule is reimplemented in YAML.
    expect($names[0])->toBe('Validate the recovery request')
        ->and(data_get($steps['Checkout the trusted target registry'], 'with.ref'))->toBe('develop')
        ->and(data_get($steps['Checkout the trusted target registry'], 'with.persist-credentials'))->toBeFalse();

    expect($source)
        ->toContain('DEPLOYMENT_TARGET: '.$target)
        ->toContain('infrastructure/scripts/targets')
        ->toContain('infrastructure/config/deployment-targets.json')
        ->toContain('show --target "${DEPLOYMENT_TARGET}" --file "${registry}"')
        ->toContain('jq -r \'.lifecycle // empty\'')
        ->toContain('if [[ "${lifecycle}" != "active" ]]; then')
        ->toContain('Recovery is refused before any GitHub Environment is entered.');

    // Reading a lifecycle is not writing one. Nothing here edits the registry,
    // and nothing here provisions.
    expect(executableSourceLines($source))
        ->not->toContain('targets set')
        ->not->toContain('provision')
        ->not->toContain('certbot');

    // Every environment-bearing job comes after that job, transitively or
    // directly, so no secret is loaded for a target that must not exist.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        if (data_get($job, 'environment') === null) {
            continue;
        }

        expect(in_array('validate', (array) data_get($job, 'needs'), true))
            ->toBeTrue("{$file}:{$jobName} enters a GitHub Environment without waiting for the lifecycle gate");
    }
})->with('recover workflows');

it('holds one concurrency group for the entire recovery chain', function (
    string $file,
    string $name,
    string $target,
    string $environment,
    string $concurrency,
) {
    [$workflow] = recoverWorkflow($file);

    // Workflow level, not job level: prepare -> recover -> build -> controlled
    // deploy -> resume -> verify is one logical mutation, and a deploy,
    // rollback, Restore or Repair slipping in between two of its jobs would
    // act on a target whose runtime is deliberately held.
    expect(data_get($workflow, 'concurrency.group'))->toBe($concurrency)
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse();

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        expect(data_get($job, 'concurrency'))->toBeNull("{$file}:{$jobName} must not carry its own concurrency group");
    }
})->with('recover workflows');

it('shares its concurrency domain with the other workflows that mutate the same target', function () {
    [$staging] = recoverWorkflow('recover-staging.yml');
    [$production] = recoverWorkflow('recover-production.yml');

    $deploy = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $restoreStaging = Yaml::parse(File::get(base_path('.github/workflows/restore-staging.yml')));
    $repairStaging = Yaml::parse(File::get(base_path('.github/workflows/repair-staging.yml')));
    $release = Yaml::parse(File::get(base_path('.github/workflows/release.yml')));
    $restoreProduction = Yaml::parse(File::get(base_path('.github/workflows/restore-production.yml')));

    expect($staging['concurrency'])->toBe($deploy['concurrency'])
        ->and($staging['concurrency'])->toBe($restoreStaging['concurrency'])
        ->and($staging['concurrency'])->toBe($repairStaging['concurrency'])
        ->and($production['concurrency'])->toBe($release['concurrency'])
        ->and($production['concurrency'])->toBe($restoreProduction['concurrency']);
});

// =============================================================================
// The replacement machine, and the machine that must not be touched
// =============================================================================

it('reads the current host binding only to refuse it, and never to connect to it', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = recoverWorkflow($file);

    // Exactly one reference, in exactly one job, and that job opens no
    // connection to anything: it compares two strings and stops.
    expect(substr_count(executableSourceLines($source), 'vars.DEPLOY_HOST'))->toBe(1);

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        $encoded = json_encode($job);

        if ($jobName === 'binding') {
            expect($encoded)->toContain('vars.DEPLOY_HOST');

            continue;
        }

        expect($encoded)->not->toContain('vars.DEPLOY_HOST');
    }

    $binding = data_get($workflow, 'jobs.binding');

    expect(data_get($binding, 'environment'))->toBe($environment)
        ->and(data_get($binding, 'needs'))->toBe('validate')
        ->and(collect(data_get($binding, 'steps'))->pluck('uses')->filter()->all())->toBe([]);

    expect($source)
        ->toContain('The replacement host is the host currently bound to this target.')
        ->toContain('Recover Host is only for a lost/replacement machine. Use Restore or Repair for the existing host.')
        ->toContain('if [[ "${REPLACEMENT_HOST,,}" == "${CURRENT_HOST,,}" ]]; then')
        // A missing binding cannot be read as "not the same host".
        ->toContain('if [[ -z "${CURRENT_HOST}" ]]; then');

    // Both jobs that could reach the host wait for that refusal.
    foreach (['prepare', 'recover', 'build', 'deploy', 'resume', 'verify', 'observability'] as $job) {
        expect(in_array('binding', (array) data_get($workflow, "jobs.{$job}.needs"), true))
            ->toBeTrue("{$file}:{$job} runs without proving the replacement host is not the current host");
    }
})->with('recover workflows');

it('points every operation at the replacement machine and never at the target binding', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);

    $host = '${{ needs.validate.outputs.replacement_host }}';
    $port = '${{ needs.validate.outputs.replacement_port }}';

    // Preparation, recovery, the controlled deployment and the Nightwatch
    // marker: every single one addresses the replacement machine.
    foreach (['bootstrap-host', 'recovery-host', 'deploy-host'] as $input) {
        $used = recoverWorkflowInputsUsed($workflow, $input);

        expect($used)->not->toBe([], "{$file} passes no {$input} anywhere");

        foreach ($used as $where => $value) {
            expect($value)->toBe($host, "{$file}: {$where} does not address the replacement machine");
        }
    }

    foreach (['bootstrap-port', 'recovery-port', 'deploy-port'] as $input) {
        foreach (recoverWorkflowInputsUsed($workflow, $input) as $where => $value) {
            expect($value)->toBe($port, "{$file}: {$where} does not use the replacement machine's port");
        }
    }

    // Nothing here repoints the binding, edits the registry or touches DNS.
    $executable = executableSourceLines($source);

    foreach ([
        'gh variable set', 'gh secret set', 'DEPLOY_HOST=', 'cloudflare', 'route53',
        'dns_record', 'nsupdate', 'contabo', 'hetzner', 'digitalocean', 'terraform',
    ] as $forbidden) {
        expect(mb_strtolower($executable))->not->toContain(mb_strtolower($forbidden));
    }

    // And no SSH of its own: every connection belongs to a shared action.
    foreach (['ssh -', 'scp -', 'rsync ', 'ssh-keyscan', 'StrictHostKeyChecking=no', 'PasswordAuthentication'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

// =============================================================================
// Credentials
// =============================================================================

it('keeps the privileged recovery credential and the restricted deploy credential apart', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);

    // The replacement machine has its own privileged credential. There is no
    // fallback to the long-lived host's bootstrap key, and none to the deploy
    // key: a fallback in either direction quietly widens what a key is for.
    expect($source)
        ->toContain('${{ vars.RECOVERY_BOOTSTRAP_USER }}')
        ->toContain('${{ secrets.RECOVERY_BOOTSTRAP_SSH_KEY }}')
        ->toContain('${{ secrets.RECOVERY_KNOWN_HOSTS }}');

    foreach ([
        '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
        '${{ vars.BOOTSTRAP_USER }}',
        '${{ secrets.DEPLOY_KNOWN_HOSTS }}',
    ] as $forbidden) {
        // str_contains + toBeFalse rather than not->toContain: toContain is
        // variadic and has no message parameter, so a trailing diagnostic
        // becomes a second needle and the negation then passes on anything.
        expect(str_contains($source, $forbidden))
            ->toBeFalse("{$file} falls back to a credential that belongs to the current host: {$forbidden}");
    }

    // Which credential each job uses, asserted per job rather than per file:
    // the whole point is that they never mix.
    $privileged = ['prepare', 'recover', 'resume', 'verify'];
    $restricted = ['deploy', 'observability'];

    foreach ($privileged as $jobName) {
        $encoded = json_encode(data_get($workflow, "jobs.{$jobName}"));

        expect($encoded)->toContain('secrets.RECOVERY_BOOTSTRAP_SSH_KEY')
            ->toContain('secrets.RECOVERY_KNOWN_HOSTS');

        // Never the deploy key: the deploy key reaches only the narrow sudo
        // wrappers and cannot prepare, recover or resume a host.
        expect($encoded)->not->toContain('secrets.DEPLOY_SSH_KEY');
    }

    foreach ($restricted as $jobName) {
        $encoded = json_encode(data_get($workflow, "jobs.{$jobName}"));

        expect($encoded)->toContain('secrets.DEPLOY_SSH_KEY')
            // The host key of the machine actually being addressed.
            ->toContain('secrets.RECOVERY_KNOWN_HOSTS');

        // Never the privileged one: a deployment and a marker are ordinary
        // operations, and neither is host administration.
        expect($encoded)->not->toContain('secrets.RECOVERY_BOOTSTRAP_SSH_KEY');
    }

    // Strict host key checking is the shared actions' contract; nothing here
    // may weaken it, and nothing here may discover a host key at run time.
    foreach (['ssh-keyscan', 'StrictHostKeyChecking', 'UserKnownHostsFile', 'known_hosts'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

it('supplies the target external material and a recovery-specific offsite credential', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);
    $steps = recoverWorkflowStepsByName($workflow, 'prepare');
    $prepare = collect($steps)->first(static fn (array $step): bool => data_get($step, 'uses') === './.github/actions/prepare-rateguru-host');

    expect($prepare)->not->toBeNull("{$file} must prepare through the shared preparation action");

    foreach ([
        'laravel-env' => 'PREPARE_LARAVEL_ENV',
        'deploy-authorized-keys' => 'PREPARE_DEPLOY_AUTHORIZED_KEYS',
        'basic-auth' => 'PREPARE_BASIC_AUTH',
        'tls-certificate' => 'PREPARE_TLS_CERTIFICATE',
        'tls-private-key' => 'PREPARE_TLS_PRIVATE_KEY',
        'tls-dhparams' => 'PREPARE_TLS_DHPARAMS',
        'nginx-tls-options' => 'PREPARE_NGINX_TLS_OPTIONS',
        'mail-tls-certificate' => 'PREPARE_MAIL_TLS_CERTIFICATE',
        'mail-tls-private-key' => 'PREPARE_MAIL_TLS_PRIVATE_KEY',
    ] as $input => $secret) {
        expect(data_get($prepare, "with.{$input}"))->toBe('${{ secrets.'.$secret.' }}');
    }

    // The one deliberate exception. The offsite credential a replacement
    // machine gets is its own, so a rehearsal can be given one that READS the
    // real backup namespace and cannot write to, prune or otherwise alter it.
    // A silent fallback to PREPARE_RCLONE_CONFIG would hand the disposable
    // machine the credential that OWNS the namespace it is reading.
    expect(data_get($prepare, 'with.rclone-config'))->toBe('${{ secrets.RECOVERY_RCLONE_CONFIG }}');

    [, $source] = recoverWorkflow($file);

    expect(executableSourceLines($source))->not->toContain('PREPARE_RCLONE_CONFIG');

    // No bucket, namespace or path override of any kind reaches GitHub: where
    // a backup lives is the server's own configuration.
    foreach (['B2_ACCOUNT', 'B2_KEY', 'B2_APPLICATION', 'rclone.conf', 'backup-namespace', 'backup_namespace'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

// =============================================================================
// Recovery: apply, inspect, and the decision that follows
// =============================================================================

it('prepares only a new recovery, and never one the server is already holding', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $prepare = data_get($workflow, 'jobs.prepare');

    expect(data_get($prepare, 'environment'))->toBe($environment)
        ->and(data_get($prepare, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'start' }}")
        ->and(data_get($prepare, 'needs'))->toBe(['validate', 'binding']);

    $steps = recoverWorkflowStepsByName($workflow, 'prepare');

    expect(data_get($steps['Checkout trusted bootstrap tooling'], 'with.ref'))->toBe('develop');

    // A continuation must not prepare: preparation reconverges the target's
    // Supervisor program and scheduler entry, which is exactly what a recovery
    // holds aside. Skipping the job is orchestration; the server-side
    // interlock that refuses a Prepare against a guarded target is the
    // guarantee, and it stays where it is.
    $recoverCondition = preg_replace('/\s+/', ' ', (string) data_get($workflow, 'jobs.recover.if'));

    expect($recoverCondition)
        ->toContain("needs.prepare.result == 'success'")
        ->toContain("needs.prepare.result == 'skipped' && needs.validate.outputs.mode == 'continue-held'");
})->with('recover workflows');

it('recovers through the shared action and decides the rest from its result alone', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = recoverWorkflow($file);
    $steps = recoverWorkflowStepsByName($workflow, 'recover');

    expect(data_get($workflow, 'jobs.recover.environment'))->toBe($environment);
    expect(data_get($steps['Checkout trusted recovery tooling'], 'with.ref'))->toBe('develop');

    $apply = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'apply');
    $inspect = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'inspect');

    expect(data_get($apply, 'uses'))->toBe('./.github/actions/recover-rateguru-host')
        ->and(data_get($apply, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'start' }}")
        ->and(data_get($apply, 'with.backup-id'))->toBe('${{ needs.validate.outputs.backup }}')
        ->and(data_get($apply, 'with.operation-id'))->toBeNull();

    expect(data_get($inspect, 'uses'))->toBe('./.github/actions/recover-rateguru-host')
        ->and(data_get($inspect, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'continue-held' }}")
        ->and(data_get($inspect, 'with.operation-id'))->toBe('${{ needs.validate.outputs.operation }}')
        ->and(data_get($inspect, 'with.backup-id'))->toBeNull();

    // GitHub never tells the server which commit the data belongs to; there is
    // no input on the action that could, and the workflow passes none.
    $action = Yaml::parse(File::get(base_path('.github/actions/recover-rateguru-host/action.yml')));

    foreach (['source-sha', 'expected-source-sha', 'ref', 'release', 'restore-source', 'remote', 'bucket', 'path'] as $forbidden) {
        expect((array) data_get($action, 'inputs'))->not->toHaveKey($forbidden);
    }

    foreach (recoverWorkflowInputsUsed($workflow, 'source-sha') as $where => $value) {
        expect($where)->not->toContain('recover');
    }

    // The branch is decided from the machine-readable result, never by
    // grepping a log — and index syntax is used for the hyphenated output
    // names, where `a.b-c || a.b-c` would otherwise read as a subtraction.
    expect($source)
        ->toContain("steps.apply.outputs['required-source-sha'] || steps.inspect.outputs['required-source-sha']")
        ->toContain('case "${STATUS}" in')
        ->toContain('Unexpected recovery status: ${STATUS}');
})->with('recover workflows');

it('branches on the two safe stages and fails closed on anything else', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);

    // awaiting-code needs everything; ready-to-resume needs only the resume.
    // The two arms are asserted as arms, so a future edit cannot make
    // ready-to-resume rebuild and redeploy an identical tree.
    $decide = collect(recoverWorkflowStepsByName($workflow, 'recover'))
        ->first(static fn (array $step): bool => data_get($step, 'id') === 'decide');

    $run = (string) data_get($decide, 'run');

    // A YAML block scalar arrives dedented, and the indentation inside it is
    // not what this asserts about — the arms are.
    $lines = implode("\n", array_map('trim', preg_split('/\R/', $run) ?: []));

    expect($lines)
        ->toContain("awaiting-code)\n# The data is on the replacement host")
        ->toContain("ready-to-resume)\n# A previous run already built")
        ->toContain('Only awaiting-code and ready-to-resume are stages this workflow may act on.');

    // Written out per arm rather than inferred, and checked as a set: the
    // three flags are what the job graph reads.
    foreach ([
        "build_required=yes\ndeploy_required=yes\nresume_required=yes",
        "build_required=no\ndeploy_required=no\nresume_required=yes",
    ] as $arm) {
        expect($lines)->toContain($arm);
    }

    // A result that contradicts itself is refused rather than interpreted.
    expect($run)
        ->toContain('if [[ "${DATA_RESTORED}" != "true" ]]; then')
        ->toContain('^[0-9a-f]{40}$')
        ->toContain('The server named a required commit that is not a full SHA')
        ->toContain('The server reports ready-to-resume, but names current release');

    // The release version comes from the BACKUP's own release, and a
    // malformed one is never patched over with a nearest tag or a v0.0.0.
    expect($source)
        ->toContain('^(v[0-9]+\.[0-9]+\.[0-9]+)-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}$')
        ->toContain('refusing to invent a version for the recovery build')
        ->toContain('release_version="${BASH_REMATCH[1]}"');
})->with('recover workflows');

// =============================================================================
// The historical build
// =============================================================================

it('builds the exact required commit with trusted tooling and no privilege whatsoever', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);
    $build = data_get($workflow, 'jobs.build');
    $steps = recoverWorkflowStepsByName($workflow, 'build');

    // The trust boundary. This job compiles an arbitrary historical commit,
    // chosen by a backup rather than by a person: it must hold no GitHub
    // Environment, and therefore no recovery credential, no deployment key, no
    // Sentry token, no B2 credential and no Prepare material.
    expect(data_get($build, 'environment'))->toBeNull("{$file}: the historical build must hold no GitHub Environment")
        ->and(data_get($build, 'permissions'))->toBe(['contents' => 'read'])
        ->and(data_get($build, 'if'))->toBe("\${{ needs.recover.outputs.build_required == 'yes' }}");

    $buildSource = json_encode($build);

    foreach ([
        'secrets.', 'vars.', 'DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS', 'SENTRY_AUTH_TOKEN',
        'B2_', 'RECOVERY_', 'PREPARE_', 'BOOTSTRAP_',
    ] as $forbidden) {
        // toContain is variadic in Pest: a second "message" argument is read
        // as another needle, and a negation then passes on anything. The
        // diagnostic goes in a comment, never in the call.
        expect($buildSource)->not->toContain($forbidden);
    }

    // Two checkouts, and which is which is the whole point: the operational
    // tooling always comes from develop, the application from the exact commit.
    $tooling = $steps['Checkout trusted build tooling'];
    $application = $steps['Checkout the required historical application source'];

    expect(data_get($tooling, 'with.ref'))->toBe('develop')
        ->and(data_get($tooling, 'with.persist-credentials'))->toBeFalse()
        ->and(data_get($tooling, 'with.path'))->toBeNull();

    expect(data_get($application, 'with.ref'))->toBe('${{ needs.recover.outputs.required_source_sha }}')
        ->and(data_get($application, 'with.path'))->toBe('application')
        ->and(data_get($application, 'with.persist-credentials'))->toBeFalse();

    // The ONE build implementation, loaded from the tooling checkout and
    // pointed at the historical one — never loaded from the historical commit.
    $buildStep = $steps['Build the recovery release artifact'];

    expect(data_get($buildStep, 'uses'))->toBe('./.github/actions/build-rateguru')
        ->and(data_get($buildStep, 'with.source-root'))->toBe('${{ github.workspace }}/application')
        ->and(data_get($buildStep, 'with.source-ref'))->toBe('${{ needs.recover.outputs.required_source_sha }}')
        ->and(data_get($buildStep, 'with.expected-source-sha'))->toBe('${{ needs.recover.outputs.required_source_sha }}')
        ->and(data_get($buildStep, 'with.release-version'))->toBe('${{ needs.recover.outputs.release_version }}')
        ->and(data_get($buildStep, 'with.release-metadata'))->toBe('${{ needs.recover.outputs.release_metadata }}');

    // Short-lived transport to the deployment job in the same run, and nothing
    // more: there is deliberately no durable artifact archive to fall back to.
    expect(data_get($buildStep, 'with.workflow-artifact-prefix'))->toBe('rateguru-host-recovery')
        ->and(data_get($buildStep, 'with.artifact-retention-days'))->toBe('3');

    // No fallback source of any kind. If the commit is gone or no longer
    // builds, the job fails and the host simply stays held.
    $checkoutRefs = collect($steps)
        ->map(static fn (array $step) => data_get($step, 'with.ref'))
        ->filter()
        ->values()
        ->all();

    expect($checkoutRefs)->toBe(['develop', '${{ needs.recover.outputs.required_source_sha }}']);
})->with('recover workflows');

it('adds recovery provenance to the artifact without redefining its identity', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);

    $decide = collect(recoverWorkflowStepsByName($workflow, 'recover'))
        ->first(static fn (array $step): bool => data_get($step, 'id') === 'decide');

    $run = (string) data_get($decide, 'run');

    expect($run)
        ->toContain('environment: "'.$environment.'",')
        ->toContain('recovery_operation: $operation,')
        ->toContain('recovery_backup: $backup,')
        ->toContain('host_recovery: true');

    // build-rateguru refuses any attempt to redefine a core release.json
    // field, and the metadata document above names none of them. Scoped to
    // that document rather than the whole file, where `sentry-project:` would
    // otherwise read as a redefinition of `project`.
    $core = ['project', 'source_ref', 'source_sha', 'release', 'built_at', 'workflow_run_id', 'workflow_run_number'];

    foreach ($core as $field) {
        expect($run)->not->toContain($field.': $');
    }
})->with('recover workflows');

// =============================================================================
// The controlled recovery deployment
// =============================================================================

it('deploys through the one deploy action, to the replacement machine, without migrating', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $deploy = data_get($workflow, 'jobs.deploy');
    $steps = recoverWorkflowStepsByName($workflow, 'deploy');

    expect(data_get($deploy, 'environment'))->toBe($environment)
        ->and(data_get($deploy, 'if'))->toBe("\${{ needs.recover.outputs.deploy_required == 'yes' }}");

    // Deployment tooling always comes from develop, never from the historical
    // ref that is about to be installed.
    expect(data_get($steps['Checkout deployment action'], 'with.ref'))->toBe('develop');

    // Checked on this side before anything is uploaded — and deliberately NOT
    // the authorization: the server reads the required commit from the
    // operation's own documents and refuses an artifact built from anything
    // else.
    expect(data_get($steps['Prove the artifact is the commit the server requires'], 'run'))
        ->toContain('if [[ "${BUILT_SOURCE_SHA}" != "${REQUIRED_SOURCE_SHA}" ]]; then')
        ->toContain('The recovery remains held on the replacement host.');

    $deployStep = $steps['Deploy the recovery release'];

    // The SAME deployment action every ordinary release uses. The only
    // difference is the operation ID — never a commit.
    expect(data_get($deployStep, 'uses'))->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($deployStep, 'with.deployment-target'))->toBe($target)
        ->and(data_get($deployStep, 'with.run-migrations'))->toBe('false')
        ->and(data_get($deployStep, 'with.recovery-operation'))->toBe('${{ needs.recover.outputs.operation }}')
        ->and(data_get($deployStep, 'with.restore-operation'))->toBeNull()
        ->and(data_get($deployStep, 'with.release-id'))->toBe('${{ needs.build.outputs.release-id }}');

    // The target's ordinary restricted deployment identity, on the replacement
    // machine's address. Only the address and the host key change.
    expect(data_get($deployStep, 'with.deploy-user'))->toBe('${{ vars.DEPLOY_USER }}')
        ->and(data_get($deployStep, 'with.deploy-incoming'))->toBe('${{ vars.DEPLOY_INCOMING }}')
        ->and(data_get($deployStep, 'with.deploy-wrapper'))->toBe('${{ vars.DEPLOY_WRAPPER }}')
        ->and(data_get($deployStep, 'with.deploy-root'))->toBe('${{ vars.DEPLOY_ROOT }}')
        ->and(data_get($deployStep, 'with.ssh-private-key'))->toBe('${{ secrets.DEPLOY_SSH_KEY }}')
        ->and(data_get($deployStep, 'with.known-hosts'))->toBe('${{ secrets.RECOVERY_KNOWN_HOSTS }}');

    foreach (['source-sha', 'required-source-sha', 'commit', 'previous', 'previous-release'] as $forbidden) {
        expect(data_get($deployStep, "with.{$forbidden}"))->toBeNull("{$file}: the recovery deploy must never name a commit or a previous release");
    }
})->with('recover workflows');

it('makes a migration impossible during a controlled recovery deployment', function (
    string $file,
) {
    [, $source] = recoverWorkflow($file);

    // Three independent reasons, and the workflow only owns the first: the
    // literal false it passes, the perimeter check in the deploy action, and
    // the server's own refusal.
    expect($source)->toContain('run-migrations: "false"')
        ->not->toContain('run-migrations: "true"');

    $action = File::get(base_path('.github/actions/deploy-rateguru/action.yml'));

    expect($action)
        ->toContain('run-migrations must be false when recovery-operation is set');
})->with('recover workflows');

// =============================================================================
// Resume, verify, and what a success is allowed to mean
// =============================================================================

it('makes recover-host --resume the only thing that ends a hold', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = recoverWorkflow($file);
    $resume = data_get($workflow, 'jobs.resume');
    $steps = recoverWorkflowStepsByName($workflow, 'resume');

    expect(data_get($resume, 'environment'))->toBe($environment)
        ->and(data_get($resume, 'needs'))->toBe(['validate', 'binding', 'recover', 'build', 'deploy']);

    // Runs after a successful controlled deployment AND on the continue-held
    // path where the required commit is already installed — but never after a
    // failed build or a failed deployment.
    $condition = preg_replace('/\s+/', ' ', (string) data_get($resume, 'if'));

    expect($condition)
        ->toContain("needs.recover.outputs.resume_required == 'yes'")
        ->toContain("needs.build.result == 'success' || needs.build.result == 'skipped'")
        ->toContain("needs.deploy.result == 'success' || needs.deploy.result == 'skipped'")
        ->toContain('!cancelled()');

    $resumeStep = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'resume');

    expect(data_get($resumeStep, 'uses'))->toBe('./.github/actions/recover-rateguru-host')
        ->and(data_get($resumeStep, 'with.operation-id'))->toBe('${{ needs.recover.outputs.operation }}')
        ->and(data_get($resumeStep, 'with.backup-id'))->toBeNull();

    // What the SERVER reported after finishing, never what the build produced.
    expect(data_get($steps['Prove the server finished the recovery it was asked to finish'], 'run'))
        ->toContain('if [[ "${STATUS}" != "completed" ]]; then')
        ->toContain('if [[ -z "${CURRENT_RELEASE}" ]]; then')
        ->toContain('if [[ "${SOURCE_SHA}" != "${REQUIRED_SOURCE_SHA}" ]]; then')
        ->toContain('if [[ "${HEALTH}" != "pass" ]]; then');

    // Nothing anywhere in the workflow resumes a host by hand: no artisan up,
    // no queue start, no scheduler restoration, no guard removal.
    foreach ([
        'artisan up', 'queue:restart', 'supervisorctl', 'cron.d', 'recovery-guard',
        'restore-guard', 'rateguru-deploy --', 'artisan migrate',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

it('treats the independent final verification as the definition of success', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $verify = data_get($workflow, 'jobs.verify');
    $steps = recoverWorkflowStepsByName($workflow, 'verify');

    expect(data_get($verify, 'environment'))->toBe($environment)
        ->and(data_get($verify, 'needs'))->toBe(['validate', 'binding', 'recover', 'resume'])
        // No condition at all: every dependency must have SUCCEEDED, which is
        // exactly the gate this job is supposed to have. A `!cancelled()` here
        // would let a failed resume through.
        ->and(data_get($verify, 'if'))->toBeNull();

    $verifyStep = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'verify');

    expect(data_get($verifyStep, 'uses'))->toBe('./.github/actions/recover-rateguru-host')
        ->and(data_get($verifyStep, 'with.deployment-target'))->toBe($target)
        // A verification reports on the target as it stands now, so it takes
        // neither operand; the action refuses them outright.
        ->and(data_get($verifyStep, 'with.operation-id'))->toBeNull()
        ->and(data_get($verifyStep, 'with.backup-id'))->toBeNull();
})->with('recover workflows');

it('never succeeds around a failed stage', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    // Only the reporting job may run unconditionally. Every job that touches
    // the host, and the marker that describes it, is gated on real success.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        $condition = (string) data_get($job, 'if');

        if ($jobName === 'report') {
            expect($condition)->toBe('${{ always() }}');

            continue;
        }

        expect(str_contains($condition, 'always()'))
            ->toBeFalse("{$file}:{$jobName} may run after a failure");
    }

    // And the three jobs that must never tolerate a skipped or failed
    // predecessor carry no status-check escape hatch at all.
    foreach (['build', 'deploy', 'verify', 'observability'] as $jobName) {
        expect(str_contains((string) data_get($workflow, "jobs.{$jobName}.if"), 'cancelled()'))
            ->toBeFalse("{$file}:{$jobName} loosens its own gate");
    }
})->with('recover workflows');

// =============================================================================
// Observability
// =============================================================================

it('records a deployment marker only after the final verification passed', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $observability = data_get($workflow, 'jobs.observability');
    $steps = recoverWorkflowStepsByName($workflow, 'observability');

    // Depending on `verify` with no condition IS the gate: a marker cannot be
    // recorded for a recovery whose final contract did not hold.
    expect(data_get($observability, 'needs'))->toBe(['validate', 'binding', 'recover', 'resume', 'verify'])
        ->and(data_get($observability, 'if'))->toBeNull()
        ->and(data_get($observability, 'environment'))->toBe($environment);

    $record = $steps['Record deployment in Sentry and Nightwatch'];

    expect(data_get($record, 'uses'))->toBe('./.github/actions/record-rateguru-deployment')
        ->and(data_get($record, 'with.deployment-target'))->toBe($target)
        ->and(data_get($record, 'with.environment'))->toBe($environment);

    // The release the SERVER reported after a completed recovery, not the one
    // the build job produced — and on the continue-held path there is no build
    // job at all.
    expect(data_get($record, 'with.release-id'))->toBe('${{ needs.resume.outputs.current_release }}')
        ->and(data_get($record, 'with.source-sha'))->toBe('${{ needs.resume.outputs.source_sha }}');

    expect(json_encode($observability))->not->toContain('needs.build.outputs');

    // Nightwatch is reached on the machine that was actually recovered.
    expect(data_get($record, 'with.deploy-host'))->toBe('${{ needs.validate.outputs.replacement_host }}')
        ->and(data_get($record, 'with.deploy-port'))->toBe('${{ needs.validate.outputs.replacement_port }}');

    // There is still exactly one marker implementation, and it is fail-open —
    // asserted where fail-open actually lives rather than restated here.
    expect(File::get(base_path('.github/actions/record-rateguru-deployment/action.yml')))
        ->toContain('FAIL-OPEN IS THE CONTRACT');
})->with('recover workflows');

// =============================================================================
// What a failed run leaves behind
// =============================================================================

it('reports enough for an operator to continue, and no secret at all', function (
    string $file,
    string $name,
) {
    [$workflow] = recoverWorkflow($file);
    $report = data_get($workflow, 'jobs.report');

    expect(data_get($report, 'environment'))->toBeNull("{$file}: the report must hold no GitHub Environment")
        ->and(data_get($report, 'permissions'))->toBe(['contents' => 'read'])
        ->and(data_get($report, 'if'))->toBe('${{ always() }}');

    // It waits for everything, so a run that stopped anywhere still describes
    // where it stopped.
    expect(data_get($report, 'needs'))->toBe([
        'validate', 'binding', 'prepare', 'recover', 'build', 'deploy', 'resume', 'verify', 'observability',
    ]);

    $run = (string) data_get($report, 'steps.0.run');

    foreach ([
        'Replacement host', 'Mode', 'Backup', 'Recovery operation', 'Server status',
        'Required source SHA', 'Historical build required', 'Recovered release',
        'Controlled recovery deployment', 'Resume', 'Final verify', 'Deployment marker',
    ] as $fact) {
        expect($run)->toContain($fact);
    }

    // The one sentence an operator has to be able to find, and the exact
    // re-run it points at.
    // The held claim lives INSIDE the operation branch. Every failure before
    // the server assigns an operation — a malformed request, the binding
    // refusal, a failed preparation — leaves nothing held on the replacement
    // machine, and a summary claiming otherwise sends an operator hunting for
    // a guard that was never written.
    expect(mb_strpos($run, 'if [[ -n "${operation}" ]]; then'))
        ->toBeLessThan((int) mb_strpos($run, 'Recovery remains held on the replacement host.'));

    expect($run)
        ->toContain('Recovery remains held on the replacement host.')
        ->toContain('Re-run "'.$name.'" with:')
        ->toContain('echo "mode=continue-held"')
        ->toContain('echo "operation=${operation}"')
        ->toContain('echo "replacement-host=${REPLACEMENT_HOST}"')
        // Nothing is cleaned up to make a run look green.
        ->toContain('Nothing was cleaned up to make this run look green');

    // Identity only. No material, no credential, no fingerprint, no size, no
    // digest — and no GitHub Environment above to read one from.
    $encoded = json_encode($report);

    foreach ([
        'secrets.', 'vars.', 'SSH_KEY', 'KNOWN_HOSTS', 'RCLONE', 'LARAVEL_ENV',
        'sha256sum', 'md5sum', 'wc -c', 'ssh-keygen',
    ] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
})->with('recover workflows');

// =============================================================================
// Production stays fail-closed while tits-guru is planned
// =============================================================================

it('gates a production recovery behind an exact typed confirmation, before any environment', function () {
    [$workflow, $source] = recoverWorkflow('recover-production.yml');
    [$staging] = recoverWorkflow('recover-staging.yml');

    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    expect($inputs)->toHaveKey('confirmation')
        ->and(data_get($inputs, 'confirmation.required'))->toBeTrue()
        ->and(data_get($inputs, 'confirmation.type'))->toBe('string')
        ->and(data_get($inputs, 'confirmation.default'))->toBe('');

    expect($source)->toContain('if [[ "${CONFIRMATION}" != "RECOVER tits-guru" ]]; then');

    // Checked in the job that holds no environment, and checked FIRST inside
    // it, so an unconfirmed run ends before approval is requested and long
    // before any connection is opened.
    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull();

    $run = (string) data_get($workflow, 'jobs.validate.steps.0.run');

    expect(mb_strpos($run, 'CONFIRMATION'))->toBeLessThan((int) mb_strpos($run, 'case "${MODE}" in'));

    // Staging has no such input: the confirmation is a production gate, and
    // adding it to staging would train operators to type past it.
    expect((array) data_get($staging, 'on.workflow_dispatch.inputs'))->not->toHaveKey('confirmation');
});

it('cannot mutate production while tits-guru is planned, and does not activate it', function () {
    [$workflow, $source] = recoverWorkflow('recover-production.yml');

    // tits-guru stays planned, and this workflow is not what changes that.
    //
    // Activating production is what legitimately retires this assertion, and
    // it is deliberately not the only one: every operator-surface scope guard
    // in this directory pins the same lifecycle. Whoever activates the target
    // updates them together —
    // `git grep -l "tits-guru'\]\['lifecycle'\])->toBe('planned')" tests/`
    // lists them, rather than a doc that would go stale as guards are added.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // The lifecycle gate is in the job with no environment, so a real run
    // today fails before the production environment's approval is requested,
    // before a production secret is loaded, and before the replacement machine
    // is touched at all.
    $validateSteps = recoverWorkflowStepsByName($workflow, 'validate');

    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull()
        ->and($validateSteps)->toHaveKey('Refuse a recovery of a target that is not active');

    $lifecycleStep = $validateSteps['Refuse a recovery of a target that is not active'];

    expect(data_get($lifecycleStep, 'env.DEPLOYMENT_TARGET'))->toBe('tits-guru')
        ->and(data_get($lifecycleStep, 'run'))->toContain('if [[ "${lifecycle}" != "active" ]]; then');

    // Every environment-bearing job needs validate, so none of them can start.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        if (data_get($job, 'environment') === null) {
            continue;
        }

        expect(data_get($job, 'environment'))->toBe('production');
        expect(in_array('validate', (array) data_get($job, 'needs'), true))->toBeTrue();
    }

    // Asserted against executable content only: the workflow's own header
    // legitimately EXPLAINS that tits-guru is lifecycle=planned, and a blunt
    // whole-file scan would forbid saying so.
    $executable = executableSourceLines($source);

    foreach (['targets set', 'lifecycle": "active', 'certbot', 'provision'] as $forbidden) {
        expect(mb_strtolower($executable))->not->toContain(mb_strtolower($forbidden));
    }
});

// =============================================================================
// One implementation, two identities
// =============================================================================

it('keeps the two recovery workflows structurally identical apart from their identity', function () {
    [$staging] = recoverWorkflow('recover-staging.yml');
    [$production] = recoverWorkflow('recover-production.yml');

    // Same jobs, same order, same shared actions: production is not a second
    // implementation, it is the same one at a different identity.
    expect(array_keys($staging['jobs']))->toBe(array_keys($production['jobs']));

    $usesOf = static fn (array $workflow): array => collect($workflow['jobs'])
        ->flatMap(static fn (array $job): array => collect(data_get($job, 'steps', []))
            ->pluck('uses')
            ->filter()
            ->all())
        ->values()
        ->all();

    expect($usesOf($staging))->toBe($usesOf($production));

    // The production surface is the staging one plus exactly one input.
    $inputsOf = static fn (array $workflow): array => array_keys((array) data_get($workflow, 'on.workflow_dispatch.inputs'));

    expect($inputsOf($staging))->toBe(['mode', 'backup', 'operation', 'replacement-host', 'replacement-port'])
        ->and($inputsOf($production))->toBe([...$inputsOf($staging), 'confirmation']);
});

it('ships the runbook and points the README and roadmap at it', function () {
    expect(File::exists(base_path('infrastructure/runbooks/github-recover.md')))->toBeTrue();

    $runbook = File::get(base_path('infrastructure/runbooks/github-recover.md'));

    // The operations documentation must never blur together, and the two
    // things an operator most needs to find must be findable.
    expect($runbook)
        ->toContain('REPAIR TARGET')
        ->toContain('RESTORE TARGET DATA')
        ->toContain('RECOVER HOST')
        ->toContain('continue-held')
        ->toContain('RECOVER tits-guru')
        ->toContain('RECOVERY_BOOTSTRAP_USER')
        ->toContain('RECOVERY_BOOTSTRAP_SSH_KEY')
        ->toContain('RECOVERY_KNOWN_HOSTS')
        ->toContain('RECOVERY_RCLONE_CONFIG')
        ->toContain('Clean-host acceptance checklist');

    // The rehearsal rule that protects the real backup namespace. Asserted
    // against whitespace-flattened prose, because both sentences are long
    // enough to wrap and a rewrap is not a change in what they say.
    expect(preg_replace('/\s+/', ' ', $runbook))
        ->toContain('cannot write to, delete from, or apply retention to it')
        ->toContain('Do not delete or reuse the real staging backup namespace to make a rehearsal look clean.');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/github-recover.md')
        ->toContain('runbooks/recover-host.md');

    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toContain('runbooks/github-recover.md')
        ->toContain('7.7 GitHub Recover + clean-host rehearsal');

    // Implemented, not accepted: CI proves the structure, only a real
    // disposable machine proves the pipeline — and no RPO or RTO is claimed.
    $flattened = preg_replace('/\s+/', ' ', $roadmap);

    expect($flattened)
        ->toContain('implemented, awaiting the real disposable-host acceptance')
        ->toContain('this slice is implemented, not accepted. No RPO or RTO is claimed by it');
});

it('creates no second implementation of anything it uses', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    $uses = collect($workflow['jobs'])
        ->flatMap(static fn (array $job): array => collect(data_get($job, 'steps', []))->pluck('uses')->filter()->all())
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Exactly the shared actions plus the two pinned third-party ones. A new
    // recovery-shaped build, deploy or marker action appearing here is the
    // thing this asserts against.
    expect($uses)->toBe([
        './.github/actions/build-rateguru',
        './.github/actions/deploy-rateguru',
        './.github/actions/prepare-rateguru-host',
        './.github/actions/record-rateguru-deployment',
        './.github/actions/recover-rateguru-host',
        'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
        'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c',
    ]);

    foreach ([
        '.github/actions/recover-build-rateguru',
        '.github/actions/recover-deploy-rateguru',
        '.github/actions/recovery-rateguru',
        '.github/actions/archive-release-artifact',
        '.github/workflows/archive-release-artifact.yml',
    ] as $rejected) {
        expect(File::exists(base_path($rejected)))->toBeFalse("{$rejected} must not exist");
    }
})->with('recover workflows');
