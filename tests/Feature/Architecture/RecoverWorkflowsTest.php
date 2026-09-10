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
 * The two documents themselves come from `recoverWorkflow()` in Pest.php: the
 * disaster-recovery contract asks about the same pair from the other
 * direction, and two readers of one file is exactly what that file is for.
 */

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

/**
 * Execute one workflow step's `run:` block against a fixed environment and
 * report its exit status.
 *
 * The replacement-host gate is the one part of these workflows whose value is
 * a DECISION rather than a shape, and a decision is only guarded by running
 * it. Every version of that gate so far has looked right in YAML and been
 * wrong about a real pair of secrets: a literal name comparison let the same
 * machine through under its IP, and comparing whatever host keys happened to
 * be recorded let it through again when the two secrets held different key
 * types for it. Both read perfectly well as text.
 *
 * @param  array<string, string>  $env
 */
function runRecoverWorkflowStep(string $file, string $job, string $stepName, array $env): int
{
    // The step is written for ubuntu-latest, and uses Bash 4+ parameter
    // expansion. macOS ships Bash 3.2 as /bin/bash, which cannot execute it
    // faithfully — skipping is honest there; CI runs it for real.
    exec('bash -c \'echo "${BASH_VERSINFO[0]}"\' 2>/dev/null', $probe, $probeStatus);

    if ($probeStatus !== 0 || (int) ($probe[0] ?? 0) < 4) {
        test()->markTestSkipped('needs Bash 4+; this host offers '.($probe[0] ?? 'no bash'));
    }

    [$workflow] = recoverWorkflow($file);

    $step = collect(data_get($workflow, "jobs.{$job}.steps", []))
        ->first(static fn (array $candidate): bool => data_get($candidate, 'name') === $stepName);

    expect($step)->not->toBeNull("{$file}:{$job} has no step named {$stepName}");

    $script = tempnam(sys_get_temp_dir(), 'rateguru-workflow-step-');

    // proc_open DROPS an environment entry whose value is the empty string,
    // leaving the variable unset. These steps run under `set -u`, where unset
    // and empty are different behaviours, and GitHub sets every key a step
    // declares in `env:` whether or not it has a value — so a harness that
    // cannot express "set but empty" cannot exercise the case a workflow
    // actually meets when an upstream job reported nothing.
    $exports = '';
    $passed = [];

    foreach ($env as $name => $value) {
        expect($name)->toMatch('/^[A-Za-z_][A-Za-z0-9_]*$/', 'environment names are exported into a script');

        if ($value === '') {
            $exports .= sprintf("export %s=''\n", $name);

            continue;
        }

        $passed[$name] = $value;
    }

    file_put_contents($script, "#!/usr/bin/env bash\n".$exports.data_get($step, 'run'));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $script], $descriptors, $pipes, null, ['PATH' => getenv('PATH'), ...$passed]);

    expect($process)->not->toBeFalse('could not start the workflow step under test');

    stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $status = proc_close($process);

    unlink($script);

    return $status;
}

/**
 * The two recovery workflows, for the assertions that need nothing but the
 * file — so they can be crossed with a second dataset without dragging five
 * unused parameters through every signature.
 *
 * @return array<string, array{0: string}>
 */
function recoverWorkflowFiles(): array
{
    return [
        'staging' => ['recover-staging.yml'],
        'production' => ['recover-production.yml'],
    ];
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
    foreach (['values', 'binding', 'prepare', 'recover', 'build', 'deploy', 'resume', 'verify'] as $job) {
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
        ->and(data_get($binding, 'needs'))->toBe(['validate', 'values'])
        ->and(collect(data_get($binding, 'steps'))->pluck('uses')->filter()->all())->toBe([]);

    expect($source)
        ->toContain('The replacement host is the host currently bound to this target.')
        ->toContain('Recover Host is only for a lost/replacement machine. Use Restore or Repair for the existing host.')
        ->toContain('if [[ "${REPLACEMENT_HOST,,}" == "${CURRENT_HOST,,}" ]]; then')
        // A missing binding cannot be read as "not the same host".
        ->toContain('if [[ -z "${CURRENT_HOST}" ]]; then');

    // The name comparison alone is not enough, and the reason is the job that
    // runs NEXT. Prepare Host has no empty-host precondition — it converges
    // whatever it finds, including a target that is already serving — and the
    // prepared/EMPTY contract that would refuse a live host belongs to
    // `recover-host --apply`, a whole job later. So one machine reached under
    // a second name gets MUTATED before anything checks it is empty.
    //
    // SSH host keys decide it without resolving anything: strict host key
    // checking is mandatory, so RECOVERY_KNOWN_HOSTS must carry the
    // replacement machine's own key, and if that machine is the bound one its
    // key is in both secrets whatever name was typed.
    expect($source)
        ->toContain('ed25519_identity()')
        ->toContain('CURRENT_KNOWN_HOSTS: ${{ secrets.DEPLOY_KNOWN_HOSTS }}')
        ->toContain('REPLACEMENT_KNOWN_HOSTS: ${{ secrets.RECOVERY_KNOWN_HOSTS }}')
        ->toContain('if [[ "${current_identity}" == "${replacement_identity}" ]]; then')
        ->toContain('The replacement machine presents the same ssh-ed25519 host key as the machine currently bound to this target.');

    // ONE canonical key type, required on BOTH sides, so that "no match" is
    // decisive rather than merely unproven: an ordinary OpenSSH server offers
    // several host keys, and two secrets holding different types for the SAME
    // machine would otherwise read as two machines.
    expect($source)
        ->toContain('$2 == "ssh-ed25519" && $3 != "" { print $3 }')
        ->toContain('if (( current_identity_count != 1 )); then')
        ->toContain('if (( replacement_identity_count != 1 )); then');

    // Hostname fields are ignored — they are exactly what differs between two
    // spellings of one machine — and a @cert-authority or @revoked line names
    // a CA or a withdrawn key, never this machine's own identity.
    expect($source)->toContain('$1 ~ /^@/ { next }');

    // The behaviour behind all of that is proven by running it, not by
    // reading it: see the canonical-identity test above.

    // Both jobs that could reach the host wait for that refusal.
    foreach (['prepare', 'recover', 'build', 'deploy', 'resume', 'verify', 'observability'] as $job) {
        expect(in_array('binding', (array) data_get($workflow, "jobs.{$job}.needs"), true))
            ->toBeTrue("{$file}:{$job} runs without proving the replacement host is not the current host");
    }
})->with('recover workflows');

it('decides machine identity by one canonical host key, and refuses when it cannot', function (
    string $file,
) {
    $currentEd25519 = 'AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAcurrent';
    $replacementEd25519 = 'AAAAC3NzaC1lZDI1NTE5AAAAIBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBreplace';
    $sharedRsa = 'AAAAB3NzaC1yc2EAAAADAQABAAABgQCsharedRSAkeyMATERIALxxxxxxxxxxxxxxx';

    $refuse = 1;
    $allow = 0;

    // Sharing a key proves one machine. NOT sharing one proves nothing on its
    // own — an ordinary OpenSSH server offers ed25519, ecdsa and rsa, so two
    // secrets can hold different key types FOR THE SAME MACHINE. Identity is
    // therefore pinned to one canonical key type, required on both sides, so
    // that the negative answer is decisive too.
    $cases = [
        'same machine, different spelling, same canonical key' => [
            $refuse,
            "current.example.com ssh-ed25519 {$currentEd25519}",
            "203.0.113.10 ssh-ed25519 {$currentEd25519}",
        ],
        'same machine, replacement secret carries only an RSA key' => [
            $refuse,
            "current.example.com ssh-ed25519 {$currentEd25519}",
            "203.0.113.10 ssh-rsa {$sharedRsa}",
        ],
        'genuinely different machines' => [
            $allow,
            "current.example.com ssh-ed25519 {$currentEd25519}",
            "203.0.113.10 ssh-ed25519 {$replacementEd25519}",
        ],
        'the bound host has no canonical key recorded' => [
            $refuse,
            "current.example.com ssh-rsa {$sharedRsa}",
            "203.0.113.10 ssh-ed25519 {$replacementEd25519}",
        ],
        'the bound host has two canonical keys, so identity is ambiguous' => [
            $refuse,
            "current.example.com ssh-ed25519 {$currentEd25519}\ncurrent.example.com ssh-ed25519 {$replacementEd25519}",
            "203.0.113.10 ssh-ed25519 {$replacementEd25519}",
        ],
        // A @cert-authority line names a CA, never this machine's identity.
        'a shared CA does not make two machines one' => [
            $allow,
            "@cert-authority *.example.com ssh-ed25519 {$currentEd25519}\ncurrent.example.com ssh-ed25519 {$currentEd25519}",
            "@cert-authority *.example.com ssh-ed25519 {$currentEd25519}\n203.0.113.10 ssh-ed25519 {$replacementEd25519}",
        ],
        // One machine whose recorded canonical keys disagree because a secret
        // predates a host-key rotation: some other key type still matches.
        'canonical keys disagree but another key still matches' => [
            $refuse,
            "current.example.com ssh-ed25519 {$currentEd25519}\ncurrent.example.com ssh-rsa {$sharedRsa}",
            "203.0.113.10 ssh-ed25519 {$replacementEd25519}\n203.0.113.10 ssh-rsa {$sharedRsa}",
        ],
        'the same key recorded under two names is still one key' => [
            $allow,
            "current.example.com ssh-ed25519 {$currentEd25519}\n[current.example.com]:2222 ssh-ed25519 {$currentEd25519}",
            "203.0.113.10 ssh-ed25519 {$replacementEd25519}",
        ],
    ];

    foreach ($cases as $description => [$expected, $currentKnownHosts, $replacementKnownHosts]) {
        $status = runRecoverWorkflowStep($file, 'binding', 'Refuse a recovery onto the host this target is already bound to', [
            'REPLACEMENT_HOST' => '203.0.113.10',
            'CURRENT_HOST' => 'current.example.com',
            'CURRENT_KNOWN_HOSTS' => $currentKnownHosts,
            'REPLACEMENT_KNOWN_HOSTS' => $replacementKnownHosts,
        ]);

        expect($status)->toBe($expected, "{$file}: {$description}");
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
    ] as $forbidden) {
        // str_contains + toBeFalse rather than not->toContain: toContain is
        // variadic and has no message parameter, so a trailing diagnostic
        // becomes a second needle and the negation then passes on anything.
        expect(str_contains($source, $forbidden))
            ->toBeFalse("{$file} falls back to a credential that belongs to the current host: {$forbidden}");
    }

    // Which credential each job uses, asserted per job rather than per file:
    // the whole point is that they never mix. The identity job reads the
    // deploy credential to derive its public half and connects nowhere; it
    // is asserted on its own below.
    $privileged = ['preflight', 'prepare', 'recover', 'resume', 'verify'];
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

    // The current host's known_hosts is readable in exactly one job, for
    // exactly one purpose: proving the replacement machine is a different
    // physical machine. It is never a connection parameter.
    expect(substr_count(executableSourceLines($source), 'secrets.DEPLOY_KNOWN_HOSTS'))->toBe(1);

    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        $encoded = json_encode($job);

        expect(str_contains($encoded, 'secrets.DEPLOY_KNOWN_HOSTS') && $jobName !== 'binding')
            ->toBeFalse("{$file}:{$jobName} reads the current host's known_hosts outside the identity gate");
    }

    foreach (recoverWorkflowInputsUsed($workflow, 'known-hosts') as $where => $value) {
        expect($value)->toBe('${{ secrets.RECOVERY_KNOWN_HOSTS }}', "{$file}: {$where} verifies the wrong machine's host key");
    }

    foreach (recoverWorkflowInputsUsed($workflow, 'bootstrap-known-hosts') as $where => $value) {
        expect($value)->toBe('${{ secrets.RECOVERY_KNOWN_HOSTS }}', "{$file}: {$where} verifies the wrong machine's host key");
    }

    // Strict host key checking is the shared actions' contract; nothing here
    // may weaken it, and nothing here may discover a host key at run time.
    foreach (['ssh-keyscan', 'StrictHostKeyChecking', 'UserKnownHostsFile'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

it('prepares the replacement host from the backup itself, seeding only the offsite credential and the deploy public key', function (
    string $file,
) {
    [$workflow, $source] = recoverWorkflow($file);
    $steps = recoverWorkflowStepsByName($workflow, 'prepare');
    $prepare = collect($steps)->first(static fn (array $step): bool => data_get($step, 'uses') === './.github/actions/prepare-rateguru-host');

    expect($prepare)->not->toBeNull("{$file} must prepare through the shared preparation action");

    // The exact backup the operator named is what the host is prepared FROM:
    // its environment file and every host-scope prerequisite come out of the
    // backup on the server, through fetch-recovery-material.
    expect(data_get($prepare, 'with.recovery-backup'))->toBe('${{ needs.validate.outputs.backup }}');

    // Two seeds, and no more. The offsite credential a replacement machine
    // gets is its own — the recovery one — never the credential that OWNS the
    // namespace it reads; and the deploy public key is derived on the runner
    // from the deployment credential, so no private key travels anywhere.
    expect(data_get($prepare, 'with.rclone-config'))->toBe('${{ secrets.RECOVERY_RCLONE_CONFIG }}')
        ->and(data_get($prepare, 'with.deploy-authorized-keys'))->toBe('${{ needs.deploy-identity.outputs.public_key }}');

    // Nothing is supplied by hand: no environment file, no TLS material, no
    // Basic Auth file. A recovery that accepted them would be a recovery
    // taking material from somewhere other than the backup.
    foreach ([
        'laravel-env', 'basic-auth', 'tls-certificate', 'tls-private-key', 'tls-dhparams',
        'nginx-tls-options', 'mail-tls-certificate', 'mail-tls-private-key',
    ] as $input) {
        expect(data_get($prepare, "with.{$input}"))->toBeNull("{$file}: {$input} must come from the backup, never from GitHub");
    }

    // And no PREPARE_* value of any kind — not as a secret, not as a fallback.
    // (The prose explaining that there is none is allowed to say so.)
    expect(executableSourceLines($source))->not->toMatch('/PREPARE_[A-Z_]+/');

    // No bucket, namespace or path override of any kind reaches GitHub: where
    // a backup lives is the server's own configuration.
    foreach (['B2_ACCOUNT', 'B2_KEY', 'B2_APPLICATION', 'rclone.conf', 'backup-namespace', 'backup_namespace'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
})->with('recover workflows');

it('derives the deploy public key on the runner and never sends the private key to the replacement host', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $identity = data_get($workflow, 'jobs.deploy-identity');

    expect($identity)->not->toBeNull("{$file} must derive the deploy identity in its own job");

    expect(data_get($identity, 'environment'))->toBe($environment)
        ->and(data_get($identity, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'start' }}")
        ->and(data_get($identity, 'needs'))->toBe(['validate', 'binding'])
        ->and(data_get($identity, 'outputs.public_key'))->toBe('${{ steps.identity.outputs.public_key }}');

    // The job holds exactly one secret, the deployment credential, and
    // connects to nothing: no host, no port, no known_hosts.
    $encoded = json_encode($identity);

    expect($encoded)->toContain('secrets.DEPLOY_SSH_KEY')
        ->not->toContain('RECOVERY_BOOTSTRAP')
        ->not->toContain('KNOWN_HOSTS')
        ->not->toContain('replacement_host')
        ->not->toContain('uses');

    $run = (string) data_get($identity, 'steps.0.run');

    // Private file, derived public half, temp file removed however the step
    // ends — and the value is only ever written through a redirection.
    expect($run)
        ->toContain('umask 077')
        ->toContain('install -m 0600 /dev/null')
        ->toContain('ssh-keygen -y -f')
        ->toContain("trap 'rm -f \"\${key_path}\"' EXIT")
        ->not->toContain('echo "${DEPLOY_SSH_KEY}"')
        ->not->toContain('DEPLOY_SSH_KEY }} |');

    // Only the public half leaves the job.
    expect($run)->toContain('public_key=');
    expect(substr_count($run, 'GITHUB_OUTPUT'))->toBe(1);
})->with('recover workflows');

it('needs exactly the recovery values and the existing deployment ones, and no PREPARE_ value at all', function (
    string $file,
) {
    [, $source] = recoverWorkflow($file);

    preg_match_all('/\b(secrets|vars)\.([A-Z_]+)/', $source, $matches);

    $referenced = array_values(array_unique(array_map(
        static fn (string $scope, string $name): string => "{$scope}.{$name}",
        $matches[1],
        $matches[2],
    )));
    sort($referenced);

    // The four values a clean-host recovery introduced, and the deployment
    // and observability values every deploying workflow already reads.
    // Nothing else: no material, no environment file, no host-specific
    // secret of any kind.
    expect($referenced)->toBe([
        'secrets.DEPLOY_KNOWN_HOSTS',
        'secrets.DEPLOY_SSH_KEY',
        'secrets.RECOVERY_BOOTSTRAP_SSH_KEY',
        'secrets.RECOVERY_KNOWN_HOSTS',
        'secrets.RECOVERY_RCLONE_CONFIG',
        'secrets.SENTRY_AUTH_TOKEN',
        'vars.DEPLOY_HOST',
        'vars.DEPLOY_INCOMING',
        'vars.DEPLOY_ROOT',
        'vars.DEPLOY_USER',
        'vars.DEPLOY_WRAPPER',
        'vars.RECOVERY_BOOTSTRAP_USER',
        'vars.SENTRY_ORG',
        'vars.SENTRY_PROJECT',
    ]);

    expect(executableSourceLines($source))->not->toMatch('/PREPARE_[A-Z_]+/');
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
        ->and(data_get($prepare, 'needs'))->toBe(['validate', 'binding', 'preflight', 'deploy-identity']);

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
        ->and(data_get($verify, 'needs'))->toBe(['validate', 'binding', 'recover', 'resume']);

    // Deliberately tolerant of a FAILED resume, and only of a failed one.
    // recover-host --resume clears the recovery guard as its commit point and
    // prints its machine-readable result afterwards, so a connection that dies
    // in between fails this workflow over a host that is fully recovered and
    // no longer held. Refusing to verify there strands the operator:
    // continue-held cannot continue an operation whose guard is gone, and
    // start cannot begin one on a host that is no longer empty.
    //
    // It weakens nothing — a resume that genuinely failed leaves the guard,
    // and --verify refuses any target that still carries one.
    $verifyCondition = preg_replace('/\s+/', ' ', (string) data_get($verify, 'if'));

    expect($verifyCondition)
        ->toContain("needs.resume.result == 'success' || needs.resume.result == 'failure'")
        ->toContain("needs.recover.result == 'success'")
        ->toContain('!cancelled()')
        // A resume that never ran means the recovery is still mid-flight.
        ->not->toContain('skipped');

    $verifyStep = collect($steps)->first(static fn (array $step): bool => data_get($step, 'with.mode') === 'verify');

    expect(data_get($verifyStep, 'uses'))->toBe('./.github/actions/recover-rateguru-host')
        ->and(data_get($verifyStep, 'with.deployment-target'))->toBe($target)
        // A verification reports on the target as it stands now, so it takes
        // neither operand; the action refuses them outright.
        ->and(data_get($verifyStep, 'with.operation-id'))->toBeNull()
        ->and(data_get($verifyStep, 'with.backup-id'))->toBeNull();

    // --verify takes no operation and reads no operation state, so it proves
    // the final contract but not WHICH commit this recovery was for. That last
    // identity check is made here, against the commit the server named when
    // the data was recovered — otherwise a host healthy on some other release
    // could be read as this recovery having succeeded.
    expect(data_get($steps['Prove the verified host serves the commit its data belongs to'], 'run'))
        ->toContain('if [[ "${SOURCE_SHA}" != "${REQUIRED_SOURCE_SHA}" ]]; then')
        ->toContain('if [[ -z "${CURRENT_RELEASE}" ]]; then');

    expect(data_get($verify, 'outputs.current_release'))->toBe("\${{ steps.verify.outputs['current-release'] }}")
        ->and(data_get($verify, 'outputs.source_sha'))->toBe("\${{ steps.verify.outputs['source-sha'] }}");
})->with('recover workflows');

it('never succeeds around a failed stage', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    // Only the reporting job may run unconditionally. Every job that touches
    // the host, and the marker that describes it, is gated on real success.
    foreach ((array) data_get($workflow, 'jobs') as $jobName => $job) {
        $condition = preg_replace('/\s+/', ' ', (string) data_get($job, 'if'));

        if ($jobName === 'report') {
            expect($condition)->toBe('${{ always() }}');

            continue;
        }

        if (! str_contains($condition, 'always()')) {
            continue;
        }

        // Any OTHER always() has to name the stage whose success it is
        // waiting for, and refuse everything else. The marker uses one
        // because the graph around it is legitimately full of skipped stages
        // on the continue-held path — not because it will run after a
        // failure.
        expect(preg_match("/needs\\.[a-z-]+\\.result == 'success'/", $condition))
            ->toBe(1, "{$file}:{$jobName} runs unconditionally without naming a stage that had to succeed");
    }

    // And the jobs that must never tolerate a skipped or failed predecessor
    // carry no status-check escape hatch at all. `verify` is deliberately NOT
    // among them: it is the one job that must still adjudicate a resume whose
    // transport died over an already-recovered host, and its own condition is
    // asserted where that behaviour is described. Nor is `observability`,
    // whose gate is the final verification's own result and nothing else.
    foreach (['build', 'deploy'] as $jobName) {
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

    // The final verification's own result IS the gate, written out rather
    // than inherited: a marker cannot be recorded for a recovery whose final
    // contract did not hold, and it must be recorded for every recovery whose
    // did. Leaving the condition implicit meant the second half of that
    // sentence was false on the continue-held path, where the preparation,
    // the build and the controlled deployment are all legitimately skipped
    // and GitHub's default `success()` inherits their skips.
    //
    // Deliberately NOT `needs: resume`. On the lost-runner path the resume job
    // fails over a host that is nonetheless complete, and a marker is still
    // owed for the release that host is provably serving.
    expect(data_get($observability, 'needs'))->toBe(['validate', 'binding', 'recover', 'verify'])
        ->and(data_get($observability, 'if'))->toBe("\${{ always() && needs.verify.result == 'success' }}")
        ->and(data_get($observability, 'environment'))->toBe($environment);

    $record = $steps['Record deployment in Sentry and Nightwatch'];

    expect(data_get($record, 'uses'))->toBe('./.github/actions/record-rateguru-deployment')
        ->and(data_get($record, 'with.deployment-target'))->toBe($target)
        ->and(data_get($record, 'with.environment'))->toBe($environment);

    // What the final VERIFICATION read off the host: the one source that is
    // present on every path a marker is owed on, including the one where the
    // resume result never arrived.
    expect(data_get($record, 'with.release-id'))->toBe('${{ needs.verify.outputs.current_release }}')
        ->and(data_get($record, 'with.source-sha'))->toBe('${{ needs.verify.outputs.source_sha }}');

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
// What runs, on the two paths an operator actually has
// =============================================================================

/**
 * The stage outputs a recovery reports on one of its two operator paths.
 *
 * `start` recovers the data onto an empty machine and leaves the operation
 * `awaiting-code`: the historical build, the controlled deployment and the
 * resume are all still owed. `continue-held` picks up an operation whose code
 * is already installed and reports `ready-to-resume`: the preparation, the
 * build and the deployment were performed by the first half of the operation
 * and rebuilding them would be work with a risk and no purpose.
 *
 * @return array<string, array<string, string>>
 */
function recoverWorkflowStageOutputs(string $mode): array
{
    $sha = '265c4d6b42ec6d08f3f41e0b689da9197385de01';
    $release = 'v0.4.1-20260909-211722-265c4d6';

    $verify = [
        'current_release' => $release,
        'source_sha' => $sha,
        'health' => 'pass',
        'queue' => 'running',
        'scheduler' => 'present',
        'offsite_writes' => 'held',
        'previous' => 'absent',
    ];

    if ($mode === 'start') {
        return [
            'validate' => [
                'mode' => 'start',
                'backup' => '20260909-113248',
                'operation' => '',
                'replacement_host' => '192.0.2.10',
                'replacement_port' => '22',
            ],
            'recover' => [
                'status' => 'awaiting-code',
                'operation' => '20260909-211722-e132b3',
                'required_source_sha' => $sha,
                'build_required' => 'yes',
                'deploy_required' => 'yes',
                'resume_required' => 'yes',
            ],
            'verify' => $verify,
        ];
    }

    return [
        'validate' => [
            'mode' => 'continue-held',
            'backup' => '',
            'operation' => '20260909-211722-e132b3',
            'replacement_host' => '192.0.2.10',
            'replacement_port' => '22',
        ],
        'recover' => [
            'status' => 'ready-to-resume',
            'operation' => '20260909-211722-e132b3',
            'required_source_sha' => $sha,
            'build_required' => 'no',
            'deploy_required' => 'no',
            'resume_required' => 'yes',
        ],
        'verify' => $verify,
    ];
}

it('records the marker on a recovery that ran start to finish here', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    $results = githubWorkflowJobResults($workflow, outputs: recoverWorkflowStageOutputs('start'));

    // Nothing is skipped on this path: a lost host needs every stage.
    foreach ([
        'validate', 'values', 'binding', 'deploy-identity', 'preflight', 'prepare',
        'recover', 'build', 'deploy', 'resume', 'verify', 'observability', 'report',
    ] as $job) {
        expect($results[$job])->toBe('success', "{$file}: {$job} did not run on the start path");
    }
})->with('recover workflows');

it('records the marker on a recovery that was interrupted and continued', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    $results = githubWorkflowJobResults($workflow, outputs: recoverWorkflowStageOutputs('continue-held'));

    // Legitimately skipped, and this is the whole point of continue-held: the
    // first half of the operation already prepared the machine, built the
    // commit the recovered data belongs to and deployed it.
    foreach (['deploy-identity', 'preflight', 'prepare', 'build', 'deploy'] as $job) {
        expect($results[$job])->toBe('skipped', "{$file}: {$job} must not run again on continue-held");
    }

    // And this is what those skips took with them once. The marker is owed to
    // a verified recovery whichever half of the operation finished it.
    foreach (['recover', 'resume', 'verify', 'observability'] as $job) {
        expect($results[$job])->toBe('success', "{$file}: {$job} inherited a legitimate skip");
    }
})->with('recover workflows');

it('would withhold the marker again if the gate went back to being implicit', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    // The same continue-held run, with the marker's condition removed — which
    // is exactly the shape the workflow had when a real interrupted recovery
    // completed, verified, and recorded nothing. If this ever stops failing to
    // record, the assertions above have stopped proving anything, because the
    // behaviour they guard against would no longer exist to guard against.
    unset($workflow['jobs']['observability']['if']);

    $results = githubWorkflowJobResults($workflow, outputs: recoverWorkflowStageOutputs('continue-held'));

    expect($results['verify'])->toBe('success')
        ->and($results['observability'])->toBe('skipped');
})->with('recover workflows');

it('records no marker for a recovery the final verification did not pass', function (
    string $file,
    string $case,
    array $outcomes,
    string $expected,
) {
    [$workflow] = recoverWorkflow($file);

    $results = githubWorkflowJobResults($workflow, outcomes: $outcomes, outputs: recoverWorkflowStageOutputs('start'));

    // A verification that FAILED found the host not to satisfy the contract; a
    // verification that was CANCELLED never finished asking; a verification
    // that was SKIPPED never adjudicated the host at all. In none of the three
    // is there a recovered release this workflow may claim.
    expect($results['verify'])->toBe($expected, "{$file}: {$case} did not leave the verification {$expected}")
        ->and($results['observability'])->toBe('skipped', "{$file}: a marker was recorded for a {$expected} verification");

    // And the run still reports, because the operator's next move depends on
    // knowing where it stopped.
    expect($results['report'])->toBe('success');
})->with(recoverWorkflowFiles())->with([
    'the verification failed' => ['the verification failed', ['verify' => 'failure'], 'failure'],
    'the verification was cancelled' => ['the verification was cancelled', ['verify' => 'cancelled'], 'cancelled'],
    // The one way a verification legitimately does not run at all: the
    // recovery of the data itself failed, so there is nothing to adjudicate.
    'the recovery never got that far' => ['the recovery never got that far', ['recover' => 'failure'], 'skipped'],
]);

it('lets a failed marker leave an already verified recovery successful', function (
    string $file,
) {
    [$workflow] = recoverWorkflow($file);

    // Fail-open is implemented once, in the shared marker action, and is
    // asserted there. What this proves is the workflow half of it: a marker
    // that nonetheless failed does not change the verdict the verification
    // reached, and does not change the summary the operator reads either.
    $results = githubWorkflowJobResults(
        $workflow,
        outcomes: ['observability' => 'failure'],
        outputs: recoverWorkflowStageOutputs('start'),
    );

    expect($results['verify'])->toBe('success')
        ->and($results['observability'])->toBe('failure')
        ->and($results['report'])->toBe('success');

    $report = data_get($workflow, 'jobs.report');
    $summary = tempnam(sys_get_temp_dir(), 'rateguru-recovery-summary-');

    $outputs = recoverWorkflowStageOutputs('start');

    $env = array_fill_keys(array_keys((array) data_get($report, 'steps.0.env', [])), '');

    $status = runRecoverWorkflowStep($file, 'report', 'Summarize the recovery', [
        ...$env,
        'GITHUB_STEP_SUMMARY' => $summary,
        'MODE' => 'start',
        'BACKUP' => $outputs['validate']['backup'],
        'REPLACEMENT_HOST' => $outputs['validate']['replacement_host'],
        'REPLACEMENT_PORT' => $outputs['validate']['replacement_port'],
        'STATUS' => $outputs['recover']['status'],
        'OPERATION' => $outputs['recover']['operation'],
        'SERVER_BACKUP' => $outputs['validate']['backup'],
        'REQUIRED_SOURCE_SHA' => $outputs['recover']['required_source_sha'],
        'RECOVERED_RELEASE' => $outputs['verify']['current_release'],
        'RECOVERED_SOURCE_SHA' => $outputs['verify']['source_sha'],
        'RECOVERED_QUEUE' => $outputs['verify']['queue'],
        'RECOVERED_SCHEDULER' => $outputs['verify']['scheduler'],
        'RECOVERED_HEALTH' => $outputs['verify']['health'],
        'RECOVERED_PREVIOUS' => $outputs['verify']['previous'],
        'OFFSITE_WRITES' => $outputs['verify']['offsite_writes'],
        'VALIDATE_RESULT' => 'success',
        'VALUES_RESULT' => 'success',
        'BINDING_RESULT' => 'success',
        'PREFLIGHT_RESULT' => 'success',
        'DEPLOY_IDENTITY_RESULT' => 'success',
        'PREPARATION_RESULT' => 'success',
        'RECOVER_RESULT' => 'success',
        'BUILD_RESULT' => 'success',
        'DEPLOY_RESULT' => 'success',
        'RESUME_RESULT' => 'success',
        'VERIFY_RESULT' => 'success',
        'OBSERVABILITY_RESULT' => 'failure',
    ]);

    $rendered = (string) file_get_contents($summary);
    unlink($summary);

    expect($status)->toBe(0, "{$file}: a failed marker turned a verified recovery into a failed run")
        ->and($rendered)->toContain('Recovered — the final contract, as verified on the host')
        ->and($rendered)->toContain('| Deployment marker | `failure` |');
})->with('recover workflows');

it('will not head a summary "as verified on the host" over a host that carries a previous release', function (
    string $file,
    string $case,
    string $previous,
    string $expected,
) {
    // A recovered host has had exactly one deployment — the controlled
    // recovery deployment, which leaves no rollback target on purpose. The
    // server refuses to verify one that has a `previous`, and the action
    // refuses to relay a result that says otherwise; this is the last of the
    // three, and the one that would otherwise print the contract as fact.
    //
    // An EMPTY value is a verification that did not report the field, which is
    // exactly as unusable as one reporting the wrong thing: the summary will
    // not fill a gap with the value the contract hopes for.
    [$workflow] = recoverWorkflow($file);
    $report = data_get($workflow, 'jobs.report');
    $summary = tempnam(sys_get_temp_dir(), 'rateguru-recovery-summary-');

    $outputs = recoverWorkflowStageOutputs('start');
    $env = array_fill_keys(array_keys((array) data_get($report, 'steps.0.env', [])), '');

    $status = runRecoverWorkflowStep($file, 'report', 'Summarize the recovery', [
        ...$env,
        'GITHUB_STEP_SUMMARY' => $summary,
        'MODE' => 'start',
        'BACKUP' => $outputs['validate']['backup'],
        'REPLACEMENT_HOST' => $outputs['validate']['replacement_host'],
        'REPLACEMENT_PORT' => $outputs['validate']['replacement_port'],
        'STATUS' => $outputs['recover']['status'],
        'OPERATION' => $outputs['recover']['operation'],
        'REQUIRED_SOURCE_SHA' => $outputs['recover']['required_source_sha'],
        'RECOVERED_RELEASE' => $outputs['verify']['current_release'],
        'RECOVERED_SOURCE_SHA' => $outputs['verify']['source_sha'],
        'RECOVERED_QUEUE' => $outputs['verify']['queue'],
        'RECOVERED_SCHEDULER' => $outputs['verify']['scheduler'],
        'RECOVERED_HEALTH' => $outputs['verify']['health'],
        'RECOVERED_PREVIOUS' => $previous,
        'OFFSITE_WRITES' => $outputs['verify']['offsite_writes'],
        'VALIDATE_RESULT' => 'success',
        'VALUES_RESULT' => 'success',
        'BINDING_RESULT' => 'success',
        'PREFLIGHT_RESULT' => 'success',
        'DEPLOY_IDENTITY_RESULT' => 'success',
        'PREPARATION_RESULT' => 'success',
        'RECOVER_RESULT' => 'success',
        'BUILD_RESULT' => 'success',
        'DEPLOY_RESULT' => 'success',
        'RESUME_RESULT' => 'success',
        'VERIFY_RESULT' => 'success',
        'OBSERVABILITY_RESULT' => 'success',
    ]);

    $rendered = (string) file_get_contents($summary);
    unlink($summary);

    expect($status)->not->toBe(0, "{$file}: {$case} was announced as a completed recovery")
        ->and($rendered)->toContain($expected)
        ->and($rendered)->not->toContain('Recovered — the final contract, as verified on the host');
})->with(recoverWorkflowFiles())->with([
    'a previous release link' => ['a previous release link', 'present', 'the verified host carries a previous release link'],
    'an unreported previous' => ['an unreported previous', '', 'the final verification reported an incomplete contract'],
]);

it('gives staging and production one marker contract', function () {
    [$staging] = recoverWorkflow('recover-staging.yml');
    [$production] = recoverWorkflow('recover-production.yml');

    // Two named buttons over one implementation. The gate that decides whether
    // a recovered host is recorded must not be able to drift between them, and
    // neither may the graph that gate is read against.
    expect(data_get($production, 'jobs.observability.if'))
        ->toBe(data_get($staging, 'jobs.observability.if'))
        ->and(data_get($production, 'jobs.observability.needs'))
        ->toBe(data_get($staging, 'jobs.observability.needs'));

    foreach (array_keys((array) data_get($staging, 'jobs')) as $job) {
        expect(data_get($production, "jobs.{$job}.needs"))
            ->toBe(data_get($staging, "jobs.{$job}.needs"), "the two recoveries disagree about what {$job} waits for");

        expect(preg_replace('/\s+/', ' ', (string) data_get($production, "jobs.{$job}.if")))
            ->toBe(preg_replace('/\s+/', ' ', (string) data_get($staging, "jobs.{$job}.if")), "the two recoveries disagree about when {$job} runs");
    }

    // And the same is true of what actually runs, on both operator paths.
    foreach (['start', 'continue-held'] as $mode) {
        $outputs = recoverWorkflowStageOutputs($mode);

        expect(githubWorkflowJobResults($production, outputs: $outputs))
            ->toBe(githubWorkflowJobResults($staging, outputs: $outputs), "the two recoveries run different stages on {$mode}");
    }
});

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
        'validate', 'values', 'binding', 'preflight', 'deploy-identity', 'prepare', 'recover', 'build', 'deploy', 'resume', 'verify', 'observability',
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

    // A recovery finished by the server but lost in transport must not be
    // re-run: there is no held operation left to continue.
    expect($run)
        ->toContain('if [[ "${RESUME_RESULT}" != "success" ]]; then')
        ->toContain('That is the lost-runner case: the server finished the recovery')
        ->toContain('The host is complete. Do NOT re-run this workflow');

    expect($run)
        ->toContain('Recovery remains held on the replacement host.')
        ->toContain('Re-run "'.$name.'" with:')
        ->toContain('echo "mode=continue-held"')
        ->toContain('echo "operation=${operation}"')
        ->toContain('echo "replacement-host=${REPLACEMENT_HOST}"')
        // Nothing is cleaned up to make a run look green.
        ->toContain('Nothing was cleaned up to make this run look green');

    // Identity only. No credential is READ (no secrets or vars context at
    // all — there is no GitHub Environment above to read one from), and no
    // fingerprint, size or digest is computed. The operator guidance may
    // NAME a GitHub value an operator has to configure; it never holds one.
    $encoded = json_encode($report);

    foreach ([
        'secrets.', 'vars.', 'sha256sum', 'md5sum', 'wc -c', 'ssh-keygen', 'cat ',
    ] as $forbidden) {
        expect(str_contains($encoded, $forbidden))->toBeFalse("{$file}: the report must never {$forbidden}");
    }

    // Every value the report job reads is a job output or a job result.
    foreach ((array) data_get($report, 'steps.0.env') as $name => $value) {
        expect($value)->toMatch('/^\$\{\{ needs\.[a-z-]+\.(outputs\.[a-z_]+|result) \}\}$/', "{$file}: report env {$name} must come from a job, never from an environment");
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

    // The rehearsal rule that protects the real backup namespace: the
    // server-side hold, and the rule against faking a clean namespace.
    // Asserted against whitespace-flattened prose, because the sentences are
    // long enough to wrap and a rewrap is not a change in what they say.
    expect(preg_replace('/\s+/', ' ', $runbook))
        ->toContain('offsite-write hold')
        ->toContain('OFFSITE WRITES: HELD')
        ->toContain('Nothing releases the hold')
        ->toContain('Do not delete or reuse the real staging backup namespace to make a rehearsal look clean.');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/github-recover.md')
        ->toContain('runbooks/recover-host.md');

    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)
        ->toContain('runbooks/github-recover.md')
        ->toContain('7.7 GitHub Recover + clean-host rehearsal');

    // Accepted on a real replacement machine, on BOTH operator paths — and
    // the roadmap has to keep saying which parts of that were real. The
    // marker fix in particular was proved by executing the job graph, not by
    // re-running a recovery on a VPS, and a roadmap that blurred those two
    // would be claiming an acceptance nobody performed.
    $flattened = preg_replace('/\s+/', ' ', $roadmap);

    expect($flattened)
        ->toContain('ACCEPTED on a real replacement VPS')
        ->toContain('Uninterrupted clean-host recovery — PASS')
        ->toContain('Interrupted recovery, continued — PASS')
        ->toContain('it was NOT re-run on a real VPS, and nothing here claims it was');

    // And no RPO or RTO is claimed anywhere, because none was measured.
    expect($flattened)
        ->toContain('No RPO or RTO is claimed')
        ->toContain('the recovery duration was not instrumented during the 7.7 rehearsals');
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
        './.github/actions/recovery-host-preflight',
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

// =============================================================================
// The clean-host proof runs before anything is prepared
// =============================================================================

it('proves the replacement host is a clean, supported machine before it prepares it', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $preflight = data_get($workflow, 'jobs.preflight');

    expect($preflight)->not->toBeNull("{$file} must run the clean-host preflight");

    // After the request, the lifecycle and the binding proofs — and only on
    // the START path: a continuation addresses a machine the recovery owns.
    expect(data_get($preflight, 'needs'))->toBe(['validate', 'binding'])
        ->and(data_get($preflight, 'if'))->toBe("\${{ needs.validate.outputs.mode == 'start' }}")
        ->and(data_get($preflight, 'environment'))->toBe($environment);

    $jobs = array_keys($workflow['jobs']);
    expect(array_search('preflight', $jobs, true))->toBeLessThan(array_search('prepare', $jobs, true));

    $steps = recoverWorkflowStepsByName($workflow, 'preflight');
    expect(data_get($steps['Checkout trusted recovery tooling'], 'with.ref'))->toBe('develop');

    $step = collect($steps)->first(static fn (array $step): bool => data_get($step, 'uses') === './.github/actions/recovery-host-preflight');

    expect($step)->not->toBeNull("{$file} must preflight through the shared action");
    expect(data_get($step, 'with.deployment-target'))->toBe($target)
        ->and(data_get($step, 'with.recovery-host'))->toBe('${{ needs.validate.outputs.replacement_host }}')
        ->and(data_get($step, 'with.recovery-port'))->toBe('${{ needs.validate.outputs.replacement_port }}')
        ->and(data_get($step, 'with.bootstrap-user'))->toBe('${{ vars.RECOVERY_BOOTSTRAP_USER }}')
        ->and(data_get($step, 'with.bootstrap-ssh-key'))->toBe('${{ secrets.RECOVERY_BOOTSTRAP_SSH_KEY }}')
        ->and(data_get($step, 'with.bootstrap-known-hosts'))->toBe('${{ secrets.RECOVERY_KNOWN_HOSTS }}');

    // The exact backup this start is for, as IDENTITY: it is compared with
    // the backup an earlier preparation of this same recovery recorded on the
    // machine, so a start that already prepared this host is recognised as one
    // to converge rather than refused as dirty. Nothing is read from the
    // backup here, and nothing else is passed that could make the preflight
    // anything but a proof.
    expect(data_get($step, 'with.recovery-backup'))->toBe('${{ needs.validate.outputs.backup }}');

    foreach (['backup-id', 'laravel-env', 'rclone-config', 'command', 'material-dir'] as $input) {
        expect(data_get($step, "with.{$input}"))->toBeNull();
    }

    // And the state it found is reported, so the summary can say which of the
    // two accepted states the machine was in.
    expect(data_get($preflight, 'outputs.state'))->toBe('${{ steps.preflight.outputs.state }}');

    // Prepare Host never runs after a refused preflight: it needs the job
    // and carries no always()/!cancelled() escape around that dependency.
    $prepare = data_get($workflow, 'jobs.prepare');
    expect(data_get($prepare, 'needs'))->toContain('preflight');
    expect((string) data_get($prepare, 'if'))->not->toContain('always()')
        ->not->toContain('cancelled()')
        ->not->toContain('preflight');

    // Production still stops at lifecycle=planned in the validate job, which
    // holds no environment — long before this job could connect anywhere.
    expect(data_get($workflow, 'jobs.validate.environment'))->toBeNull();
})->with('recover workflows');

it('tells the operator where a failed run stopped, what to do and what to re-run, and the whole final contract on success', function (
    string $file,
    string $name,
    string $target,
) {
    [$workflow] = recoverWorkflow($file);
    $run = (string) data_get($workflow, 'jobs.report.steps.0.run');

    // The one operator-facing refusal format, rendered from the cause a
    // stage reported, with the stage and the START/CONTINUE recommendation.
    expect($run)
        ->toContain('RECOVERY ACTION REQUIRED')
        ->toContain('Cause: ${cause}')
        ->toContain('What this means: ${meaning}')
        ->toContain('Then: ${then_action}')
        ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
        ->toContain('Stopped at: ${stage:-unknown} — recommendation: ${recommendation}')
        ->toContain('recommendation="CONTINUE"')
        ->toContain('recommendation="START"')
        ->toContain('mode=continue-held, operation=${operation}')
        ->toContain('| Clean-host preflight (read-only) |');

    // Every cause a stage can report has its own guidance.
    foreach ([
        'preflight:unsupported-os:', 'preflight:unsupported-architecture:', 'preflight:host-not-clean:',
        'preflight:host-unreachable:', 'preflight:known-hosts-mismatch:', 'preflight:ssh-authentication-failed:',
        'preflight:passwordless-sudo-missing:', 'backup-not-recovery-capable', 'backup-not-found',
        'recovery-material-invalid', 'rclone-config-unavailable', 'offsite-hold-missing', 'awaiting-code',
        'guard-present', 'build:*', 'deploy:*', 'resume:*', 'verify:*', 'binding:*', 'deploy-identity:*',
    ] as $cause) {
        expect($run)->toContain($cause);
    }

    // The success summary states the whole final contract.
    foreach ([
        '| Exact backup |', '| Exact source_sha |', '| Queue |', '| Scheduler |', '| Health |',
        '| Guards | none', 'OFFSITE WRITES: HELD', '| DNS | unchanged |', '| DEPLOY_HOST | unchanged |',
        '| Migrations run | none |', "| Target | \\`{$target}\\` |",
    ] as $fact) {
        expect($run)->toContain($fact);
    }
})->with('recover workflows');

// =============================================================================
// The environment's values are proven present before any connection
// =============================================================================

it('refuses to start without the recovery values the environment must hold, naming the value and its kind, before any connection', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow, $source] = recoverWorkflow($file);
    $values = data_get($workflow, 'jobs.values');

    expect($values)->not->toBeNull("{$file} must prove the environment's values before anything else");
    expect(data_get($values, 'needs'))->toBe('validate')
        ->and(data_get($values, 'environment'))->toBe($environment)
        ->and(data_get($values, 'outputs.cause'))->toBe('${{ steps.values.outputs.cause }}');

    // Everything that could connect anywhere waits for it, through binding.
    expect(data_get($workflow, 'jobs.binding.needs'))->toBe(['validate', 'values']);

    $jobs = array_keys($workflow['jobs']);
    expect(array_search('values', $jobs, true))->toBeLessThan(array_search('binding', $jobs, true));

    $step = data_get($values, 'steps.0');
    expect(data_get($step, 'id'))->toBe('values');

    // Presence only: every value reaches the step as a `!= ''` boolean, and
    // exactly these five — the four recovery-only values and the existing
    // deploy key the deploy identity is derived from.
    $env = (array) data_get($step, 'env');

    expect($env)->toBe([
        'MODE' => '${{ needs.validate.outputs.mode }}',
        'BACKUP' => '${{ needs.validate.outputs.backup }}',
        'RECOVERY_BOOTSTRAP_USER_PRESENT' => "\${{ vars.RECOVERY_BOOTSTRAP_USER != '' }}",
        'RECOVERY_BOOTSTRAP_SSH_KEY_PRESENT' => "\${{ secrets.RECOVERY_BOOTSTRAP_SSH_KEY != '' }}",
        'RECOVERY_KNOWN_HOSTS_PRESENT' => "\${{ secrets.RECOVERY_KNOWN_HOSTS != '' }}",
        'RECOVERY_RCLONE_CONFIG_PRESENT' => "\${{ secrets.RECOVERY_RCLONE_CONFIG != '' }}",
        'DEPLOY_SSH_KEY_PRESENT' => "\${{ secrets.DEPLOY_SSH_KEY != '' }}",
    ]);

    // The offsite credential is a SECRET, read as one, and never a variable —
    // here and everywhere else in the workflow.
    expect($source)->toContain("secrets.RECOVERY_RCLONE_CONFIG != ''");
    expect(str_contains($source, 'vars.RECOVERY_RCLONE_CONFIG'))->toBeFalse("{$file} must never read RECOVERY_RCLONE_CONFIG as a variable");
    expect(substr_count($source, 'secrets.RECOVERY_RCLONE_CONFIG'))->toBe(2);

    // The refusal that actually happened once, spelled out for the operator.
    $run = (string) data_get($step, 'run');

    expect($run)
        ->toContain('action_required rclone-config-secret-missing \\')
        ->toContain("\"the {$environment} GitHub Environment has no RECOVERY_RCLONE_CONFIG secret\"")
        ->toContain("\"Settings -> Environments -> {$environment} -> Environment secrets\"")
        ->toContain('"create RECOVERY_RCLONE_CONFIG"')
        ->toContain('"paste the complete contents of the recovery rclone configuration file"')
        ->toContain('"do not create it as an Environment variable"')
        ->toContain("then_action=\"re-run \\\"{$name}\\\" with mode=start and the same exact backup (backup=\${BACKUP})\"")
        ->toContain('echo "Runbook: infrastructure/runbooks/clean-host-recovery.md"')
        ->toContain('echo "cause=${word}" >> "${GITHUB_OUTPUT}"');

    foreach (['bootstrap-user-variable-missing', 'bootstrap-ssh-key-secret-missing', 'known-hosts-secret-missing', 'deploy-ssh-key-secret-missing'] as $cause) {
        expect($run)->toContain("action_required {$cause} \\");
    }

    // The variable is named as a variable, every secret as a secret.
    expect($run)
        ->toContain("\"Settings -> Environments -> {$environment} -> Environment variables\"")
        ->toContain('"it is an Environment variable, not a secret"');

    // A continuation needs no offsite credential: it prepares nothing.
    expect($run)->toContain('if [[ "${MODE}" == "start" ]] && [[ "${RECOVERY_RCLONE_CONFIG_PRESENT}" != "true" ]]; then');

    // No value is ever read, so none can be printed: the step's environment
    // holds booleans and two request fields, and the run echoes only its
    // own words.
    expect($run)->not->toMatch('/echo[^\n]*\$\{(RECOVERY|DEPLOY)_[A-Z_]+\}/');

    // And the final summary renders the same guidance from the cause.
    $report = (string) data_get($workflow, 'jobs.report.steps.0.run');

    expect($report)
        ->toContain('values:rclone-config-secret-missing)')
        ->toContain('"do not create it as an Environment variable"')
        ->toContain('| Recovery values present |')
        ->toContain('"values:${VALUES_RESULT}"');
    expect(data_get($workflow, 'jobs.report.steps.0.env.VALUES_CAUSE'))->toBe('${{ needs.values.outputs.cause }}');
})->with('recover workflows');

// =============================================================================
// The summary states what was reported, and nothing else
// =============================================================================

it('never turns an unreported final-contract fact into a successful one', function (
    string $file,
    string $name,
    string $target,
) {
    [$workflow] = recoverWorkflow($file);
    $run = (string) data_get($workflow, 'jobs.report.steps.0.run');

    // Every fact under "as verified on the host" is one the server-side
    // verification reported. A default THERE would be this summary inventing
    // the answer the contract hopes for. (Elsewhere a fallback is honest:
    // the overview table says "not verified", and a refusal says "<empty>".)
    $success = mb_substr($run, mb_strpos($run, 'if [[ "${VERIFY_RESULT}" == "success" ]]; then'));

    foreach (['RECOVERED_QUEUE', 'RECOVERED_SCHEDULER', 'RECOVERED_HEALTH', 'OFFSITE_WRITES', 'RECOVERED_SOURCE_SHA'] as $fact) {
        expect(str_contains($success, '${'.$fact.':-'))
            ->toBeFalse("{$file}: the success summary must not default {$fact}");
    }

    foreach (['running', 'present', 'pass', 'held'] as $optimistic) {
        expect(str_contains($success, ":-{$optimistic}}"))
            ->toBeFalse("{$file}: the success summary must not fall back to {$optimistic}");
    }

    expect($run)
        ->toContain('missing=()')
        ->toContain('[[ -n "${RECOVERED_QUEUE}" ]]       || missing+=("queue")')
        ->toContain('[[ -n "${RECOVERED_SCHEDULER}" ]]   || missing+=("scheduler")')
        ->toContain('[[ -n "${RECOVERED_HEALTH}" ]]      || missing+=("health")')
        ->toContain('[[ -n "${OFFSITE_WRITES}" ]]        || missing+=("offsite_writes")')
        ->toContain('Recovery NOT confirmed — the final verification reported an incomplete contract')
        ->toContain('this summary will not')
        ->toContain('exit 1');

    // The verification job is where those values come from, and the action is
    // required to report them for the verify mode.
    expect(data_get($workflow, 'jobs.verify.outputs.queue'))->toBe('${{ steps.verify.outputs.queue }}')
        ->and(data_get($workflow, 'jobs.verify.outputs.scheduler'))->toBe('${{ steps.verify.outputs.scheduler }}');
})->with('recover workflows');

it('tells a values job that judged nothing apart from one that found a value missing', function (
    string $file,
    string $name,
    string $target,
    string $environment,
) {
    [$workflow] = recoverWorkflow($file);
    $run = (string) data_get($workflow, 'jobs.report.steps.0.run');

    // An approval that was rejected or timed out, or a cancelled run, leaves
    // the values job with a result and no cause. Guidance about a missing
    // GitHub value would be a guess.
    expect($run)
        ->toContain('if [[ "${stage}" == "values" ]] && [[ -z "${VALUES_CAUSE}" ]]; then')
        ->toContain('if [[ "${VALUES_RESULT}" == "cancelled" ]]; then')
        ->toContain("the run was cancelled before the {$environment} environment's recovery values were judged")
        ->toContain("the {$environment} environment's approval was rejected or timed out")
        ->toContain('read the values job\'s own log and its environment approval');

    // The missing-value guidance stays for the case it was written for.
    expect($run)->toContain('values:rclone-config-secret-missing)');
})->with('recover workflows');
