<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The clean-host preflight transport action.
 *
 * It carries exactly one read-only script to a replacement machine, proves
 * privileged access on the way, runs one fixed argv, reads one
 * machine-readable result and cleans up after itself. Every check lives in
 * infrastructure/scripts/recovery-host-preflight; nothing here reimplements,
 * relaxes or second-guesses any of it.
 */
function preflightActionPath(): string
{
    return base_path('.github/actions/recovery-host-preflight/action.yml');
}

function preflightAction(): array
{
    return Yaml::parseFile(preflightActionPath());
}

function preflightActionSource(): string
{
    return File::get(preflightActionPath());
}

/** One step's `run:` body, by step name. */
function preflightActionStep(string $name): string
{
    foreach (preflightAction()['runs']['steps'] as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step['run'] ?? '';
        }
    }

    throw new RuntimeException("no step named {$name}");
}

/** Every `run:` body, comment lines removed. */
function preflightActionRuns(): string
{
    return executableSourceLines(implode("\n", array_map(
        static fn (array $step): string => $step['run'] ?? '',
        preflightAction()['runs']['steps'],
    )));
}

it('is transport only: one read-only script to the bootstrap home, one fixed argv, and nothing else carried', function () {
    $action = preflightAction();

    $inputs = array_keys($action['inputs']);
    sort($inputs);

    expect($inputs)->toBe([
        'bootstrap-known-hosts',
        'bootstrap-ssh-key',
        'bootstrap-user',
        'deployment-target',
        'environment',
        'recovery-backup',
        'recovery-host',
        'recovery-port',
    ]);
    expect($action['inputs']['recovery-port']['default'])->toBe('22');

    $run = preflightActionStep('Run the read-only preflight on the replacement host');

    expect($run)
        ->toContain('script="${GITHUB_WORKSPACE}/infrastructure/scripts/recovery-host-preflight"')
        ->toContain('test -x "${script}"')
        ->toContain('remote_dir=".rateguru-preflight-${RUN_ID}-${RUN_ATTEMPT}"')
        ->toContain('"${BOOTSTRAP_USER}@${RECOVERY_HOST}:${remote_dir}/recovery-host-preflight"')
        ->toContain("remote_command=(\n  \${RATEGURU_PRIVILEGED_PREFIX}\n  bash\n  \"\${remote_dir}/recovery-host-preflight\"\n  --check\n  --target \"\${DEPLOYMENT_TARGET}\"\n  --bootstrap-user \"\${BOOTSTRAP_USER}\"\n  --environment \"\${ENVIRONMENT}\"\n)")
        // Identity only, and only when the caller has one: the exact backup
        // this start is for, compared with the one an earlier preparation
        // recorded on the machine. Nothing is read from the backup itself.
        ->toContain('remote_command+=(--recovery-backup "${RECOVERY_BACKUP}")')
        ->toContain('"${remote_command[@]@Q}"')
        ->toContain("grep '^RATEGURU_RECOVERY_PREFLIGHT='");

    // No bundle, no material, no backup, no other primitive, no free text.
    $runs = preflightActionRuns();

    foreach ([
        'tar ', 'bundle', '--material-dir', '--backup ', 'rclone',
        'prepare-host', 'recover-host', 'build-rateguru', 'deploy-rateguru', 'eval', 'bash -c',
    ] as $forbidden) {
        expect(str_contains($runs, $forbidden))->toBeFalse("the preflight action must never carry or invoke: {$forbidden}");
    }
});

it('proves privileged access first and names each transport failure in the shared operator format', function () {
    $access = preflightActionStep('Verify privileged access on the replacement host');

    expect($access)
        ->toContain("probe=\"$(ssh_bootstrap 'id -u' 2>&1)\"")
        ->toContain("ssh_bootstrap 'sudo -n true'")
        ->toContain('echo "RECOVERY ACTION REQUIRED"')
        ->toContain('echo "Cause: ${cause}"')
        ->toContain('echo "What this means: ${meaning}"')
        ->toContain('echo "Do:"')
        ->toContain('echo "Then: ${then}"')
        ->toContain('echo "Runbook: infrastructure/runbooks/clean-host-recovery.md"')
        ->toContain('echo "cause=${word}" >> "${GITHUB_OUTPUT}"');

    foreach (['known-hosts-mismatch', 'ssh-authentication-failed', 'host-unreachable', 'passwordless-sudo-missing'] as $cause) {
        expect($access)->toContain("action_required {$cause} \\");
    }

    // OpenSSH's own words decide which failure it was; nothing is
    // reinterpreted from an exit status alone.
    expect($access)
        ->toContain('host key verification failed|remote host identification has changed')
        ->toContain('permission denied|no supported authentication');

    // Two refusal branches, and each one ends the step before anything is
    // uploaded: the transport classification above, and the passwordless-sudo
    // branch below it. Asserted as branches rather than as a bare count.
    $transport = mb_substr($access, mb_strpos($access, 'if (( probe_status != 0 )); then'));
    $sudo = mb_substr($access, mb_strpos($access, 'action_required passwordless-sudo-missing'));

    expect($transport)->toContain('exit 1');
    expect($sudo)->toContain('exit 1');
    expect(substr_count($access, 'exit 1'))->toBe(2);

    // The helper reports and records the cause; the branch that called it is
    // what ends the step. A helper that exited would make every refusal look
    // identical to the one before it.
    $helper = mb_substr($access, mb_strpos($access, 'action_required() {'), mb_strpos($access, 'rm -f "${RUNNER_TEMP}/rateguru_preflight_action_required"') - mb_strpos($access, 'action_required() {'));

    expect(str_contains($helper, 'exit '))->toBeFalse('the helper reports; the caller exits');
    expect($helper)->toContain('echo "cause=${word}" >> "${GITHUB_OUTPUT}"');

    // The step's own diagnostic never carries key material.
    expect($access)->toContain("grep -viE 'private|BEGIN|END' >&2");
});

it('never weakens host key checking, never scans a key, never prints the credential', function () {
    $source = preflightActionSource();

    $invocations = count(array_filter(
        preg_split('/\R/', $source),
        static fn (string $line): bool => (bool) preg_match('/^\s*(if )?(ssh|scp) \\\\$/', $line),
    ));

    expect($invocations)->toBe(4);

    // Counted as option lines of an invocation, not as words: the operator
    // guidance legitimately spells out an ssh command for the operator to run.
    $optionLines = static fn (string $option): int => count(array_filter(
        preg_split('/\R/', $source),
        static fn (string $line): bool => trim($line) === "-o {$option} \\",
    ));

    expect($optionLines('StrictHostKeyChecking=yes'))->toBe($invocations)
        ->and($optionLines('BatchMode=yes'))->toBe($invocations)
        ->and($optionLines('IdentitiesOnly=yes'))->toBe($invocations)
        ->and($optionLines('UserKnownHostsFile="${RATEGURU_PREFLIGHT_KNOWN_HOSTS_PATH}"'))->toBe($invocations);

    // The same keepalives the preparation and recovery transports carry, on
    // every ssh (scp copies one small file and needs none).
    expect($optionLines('ServerAliveInterval=30'))->toBe(3)
        ->and($optionLines('ServerAliveCountMax=10'))->toBe(3);

    foreach ([
        'StrictHostKeyChecking=no', 'StrictHostKeyChecking=accept-new', 'ssh-keyscan', 'sshpass',
        'PasswordAuthentication=yes', 'cat "${key_path}"', 'echo "${BOOTSTRAP_SSH_KEY}"', 'DEPLOY_SSH_KEY',
    ] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse("the preflight action must never contain: {$forbidden}");
    }

    $configure = preflightActionStep('Configure bootstrap SSH');

    expect($configure)
        ->toContain('install -m 0600 /dev/null "${key_path}"')
        ->toContain('install -m 0600 /dev/null "${known_hosts_path}"')
        ->toContain('if ! ssh-keygen -y -f "${key_path}" >/dev/null; then')
        ->toContain('rm -f "${key_path}" "${known_hosts_path}"');

    // The private key is read from the input exactly once, into the
    // environment of the step that writes it to a 0600 file.
    expect(substr_count($source, 'inputs.bootstrap-ssh-key'))->toBe(2);

    $cleanup = preflightActionStep('Remove temporary local files');

    expect($cleanup)
        ->toContain('"${RATEGURU_PREFLIGHT_SSH_KEY_PATH:-}"')
        ->toContain('"${RATEGURU_PREFLIGHT_KNOWN_HOSTS_PATH:-}"')
        ->toContain('"${RUNNER_TEMP}/rateguru_preflight_action_required"');
});

it('asks the lifecycle question on the runner before it connects anywhere, and orders its steps so a refusal changes nothing', function () {
    $steps = preflightAction()['runs']['steps'];

    expect(array_map(static fn (array $step): string => $step['name'], $steps))->toBe([
        'Validate fixed caller inputs',
        'Validate the target lifecycle before anything is uploaded',
        'Configure bootstrap SSH',
        'Verify privileged access on the replacement host',
        'Run the read-only preflight on the replacement host',
        'Remove the uploaded preflight',
        'Remove temporary local files',
    ]);

    expect($steps[5]['if'])->toBe('${{ always() }}')
        ->and($steps[6]['if'])->toBe('${{ always() }}');

    $validate = preflightActionStep('Validate fixed caller inputs');

    expect($validate)
        ->toContain("target_regex='^[a-z0-9]+(-[a-z0-9]+)*$'")
        ->toContain('10#${RECOVERY_PORT}')
        ->toContain('staging|production)')
        ->toContain('refuses to fall back to the deployment key');

    $lifecycle = preflightActionStep('Validate the target lifecycle before anything is uploaded');

    expect($lifecycle)
        ->toContain('registry="${GITHUB_WORKSPACE}/infrastructure/config/deployment-targets.json"')
        ->toContain('"${targets_cli}" show --target "${DEPLOYMENT_TARGET}" --file "${registry}"')
        ->toContain('if [[ "${lifecycle}" != "active" ]]; then')
        ->toContain('exit 1');

    // The remote cleanup removes only what this run uploaded, by its own name.
    expect(preflightActionStep('Remove the uploaded preflight'))
        ->toContain("printf -v cleanup_command 'rm -rf %q' \"\${RATEGURU_PREFLIGHT_REMOTE_DIR}\"")
        ->toContain('Nothing was uploaded; no remote cleanup required.');
});

it('exposes a result and a closed cause, read only from its own steps', function () {
    $outputs = preflightAction()['outputs'];

    expect(array_keys($outputs))->toBe(['result', 'state', 'cause']);
    expect($outputs['result']['value'])->toBe('${{ steps.preflight.outputs.result }}');
    expect($outputs['state']['value'])->toBe('${{ steps.preflight.outputs.state }}');
    expect($outputs['cause']['value'])->toBe('${{ steps.access.outputs.cause || steps.preflight.outputs.cause }}');

    $run = preflightActionStep('Run the read-only preflight on the replacement host');

    // A pass is the ONLY outcome without a cause. A transport that died before
    // the script printed a verdict leaves no machine-readable line at all, and
    // that must still reach the workflow as a word it can act on.
    expect($run)
        ->toContain('echo "result=pass"')
        ->toContain('echo "cause="')
        ->toContain('echo "result=refused"')
        ->toContain('echo "cause=${cause:-preflight-failed}"')
        ->toContain('echo "state=${state:-pristine}"')
        ->toContain('echo "state=${state:-unknown}"')
        ->toContain('if (( preflight_status == 0 )) && [[ "${result}" == "pass" ]]; then')
        // The summary carries the script's own REFUSED lines and its action
        // block — names of files, accounts and units — never the whole log.
        ->toContain("awk '/^RECOVERY PREFLIGHT: REFUSED/ { collecting = 1 } collecting && !/^RATEGURU_RECOVERY_PREFLIGHT=/ { print }'")
        ->toContain('## Recovery host preflight — REFUSED before any change')
        ->toContain('## Recovery host preflight — PASS');
});

it('refuses a bootstrap user that is the target\'s own identity, on the runner, before any connection', function () {
    $lifecycle = preflightActionStep('Validate the target lifecycle before anything is uploaded');

    expect($lifecycle)
        ->toContain("runtime_user=\"\$(printf '%s' \"\${target_json}\" | jq -r '.runtime_user // empty')\"")
        ->toContain("deploy_user=\"\$(printf '%s' \"\${target_json}\" | jq -r '.deploy_user // empty')\"")
        ->toContain('if [[ "${BOOTSTRAP_USER}" == "${runtime_user}" ]] || [[ "${BOOTSTRAP_USER}" == "${deploy_user}" ]]; then')
        ->toContain('Nothing was connected to.');

    // Asked here as well as on the host, because a clean machine cannot read
    // the registry at all — and the answer decides which identity the
    // host-side check is allowed to treat as expected.
    expect(data_get(preflightAction(), 'runs.steps.1.env.BOOTSTRAP_USER'))->toBe('${{ inputs.bootstrap-user }}');
});

it('reports which of the two accepted states a passing machine was in', function () {
    $run = preflightActionStep('Run the read-only preflight on the replacement host');

    expect($run)
        ->toContain('state="$(jq -r \'.state // empty\' <<<"${result_json:-{\}}" 2>/dev/null || true)"')
        ->toContain('if [[ "${state}" == "recovery-preparation-retry" ]]; then')
        ->toContain('the machine an earlier start of this recovery already prepared for')
        ->toContain('Prepare Host converges it again');
});
