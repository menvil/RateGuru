<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The operator surface of the release of a recovered machine's held outbound
 * mail: Release recovered tits.guru outbound mail, and its composite action.
 *
 * It runs from main only, behind the byte-identical main-only gate; the
 * request — and, for an apply, the operator's typed confirmation for the exact
 * address — is judged in a job that holds no GitHub Environment, before any
 * secret exists. The action runs release-recovered-outbound and nothing else;
 * the decisions are that script's, guarded by ReleaseRecoveredOutboundTest.
 */
function releaseRecoveredOutboundWorkflow(): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/release-tits-guru-recovered-outbound.yml')));
}

/**
 * The request job's one step, run as the runner would, with these inputs.
 *
 * @return array{0: int, 1: string, 2: string} status, stderr, summary
 */
function releaseRecoveredOutboundRequest(array $inputs): array
{
    $script = releaseRecoveredOutboundWorkflow()['jobs']['validate-request']['steps'][0]['run'];
    $summary = tempnam(sys_get_temp_dir(), 'release-request-summary-');

    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'GITHUB_STEP_SUMMARY' => $summary];
    foreach (['MODE' => 'mode', 'OPERATION' => 'operation', 'ADDRESS' => 'address', 'CONFIRM' => 'confirm'] as $variable => $input) {
        if (($inputs[$input] ?? '') !== '') {
            $env[$variable] = $inputs[$input];
        }
    }

    $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $written = (string) file_get_contents($summary);
    unlink($summary);

    return [$status, $stderr, $written];
}

it('runs from main only, for tits-guru only, with the request judged before the Environment', function () {
    $workflow = releaseRecoveredOutboundWorkflow();

    expect($workflow['name'])->toBe('Release recovered tits.guru outbound mail');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect(array_keys($workflow['on']['workflow_dispatch']['inputs']))->toBe(['mode', 'operation', 'address', 'confirm']);
    expect($workflow['on']['workflow_dispatch']['inputs']['mode'])->toMatchArray(['type' => 'choice', 'options' => ['check', 'apply', 'verify'], 'default' => 'check']);
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'validate-request', 'release']);

    // Only the release job holds the Environment, and it waits for both gates.
    expect($workflow['jobs']['validate-request'])->not->toHaveKey('environment');
    expect($workflow['jobs']['validate-request']['needs'])->toBe(['validate-ref']);

    $release = $workflow['jobs']['release'];
    expect($release['needs'])->toBe(['validate-ref', 'validate-request']);
    expect($release['environment'])->toBe('production-tits-guru');
    expect($release['runs-on'])->toBe('ubuntu-24.04');
    expect($release['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($release['steps'][1]['uses'])->toBe('./.github/actions/release-rateguru-recovered-outbound');
    expect($release['steps'][1]['with'])->toBe([
        'operation' => '${{ inputs.mode }}',
        'deployment-target' => 'tits-guru',
        'recovery-operation' => '${{ inputs.operation }}',
        'sending-address' => '${{ inputs.address }}',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);
});

it('accepts an apply only with the confirmation typed exactly for the address being released', function (array $inputs, bool $accepted, string $expected) {
    [$status, $stderr, $summary] = releaseRecoveredOutboundRequest($inputs);

    expect($status === 0)->toBe($accepted, $stderr);
    expect($accepted ? $summary : $stderr)->toContain($expected);
})->with([
    'a check' => [['mode' => 'check', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10'], true, '| Mode | check |'],
    'a verify' => [['mode' => 'verify'], true, '| Mode | verify |'],
    'an apply, confirmed' => [['mode' => 'apply', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'confirm' => 'release tits-guru outbound mail on 203.0.113.10'], true, '| Confirmed | yes |'],
    'an apply, unconfirmed' => [['mode' => 'apply', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10'], false, 'Type exactly: release tits-guru outbound mail on 203.0.113.10'],
    'an apply confirmed for another address' => [['mode' => 'apply', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'confirm' => 'release tits-guru outbound mail on 198.51.100.7'], false, 'Type exactly: release tits-guru outbound mail on 203.0.113.10'],
    'an apply confirmed for another target' => [['mode' => 'apply', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'confirm' => 'release staging-main outbound mail on 203.0.113.10'], false, 'Type exactly'],
    'a check with a confirmation' => [['mode' => 'check', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'confirm' => 'release tits-guru outbound mail on 203.0.113.10'], false, 'confirm belongs to an apply alone'],
    'no operation' => [['mode' => 'check', 'address' => '203.0.113.10'], false, 'operation must be the exact recovery operation'],
    'an operation that is not one' => [['mode' => 'check', 'operation' => 'latest', 'address' => '203.0.113.10'], false, 'operation must be the exact recovery operation'],
    'no address' => [['mode' => 'check', 'operation' => '20261020-120000-abcdef'], false, 'address must be the IPv4 address'],
    'an address that is not one' => [['mode' => 'check', 'operation' => '20261020-120000-abcdef', 'address' => 'mta1.tits.guru'], false, 'address must be the IPv4 address'],
    'a verify with an operation' => [['mode' => 'verify', 'operation' => '20261020-120000-abcdef'], false, 'verify takes no operation and no address'],
    'a mode that is not one' => [['mode' => 'release'], false, 'Invalid mode: release'],
]);

it('runs exactly release-recovered-outbound through its action, and nothing that could move the DNS, the binding or the held mail', function () {
    $action = Yaml::parse(File::get(base_path('.github/actions/release-rateguru-recovered-outbound/action.yml')));
    $steps = collect($action['runs']['steps'])->keyBy('name');

    $run = $steps['Run the release']['run'];
    expect($run)
        ->toContain('"${RATEGURU_REMOTE_ROOT}/infrastructure/scripts/release-recovered-outbound"')
        ->toContain('remote_command+=(--confirm-release)')
        ->toContain("grep -c '^RATEGURU_RECOVERED_OUTBOUND_RESULT='");

    // The confirmation only ever travels with an apply.
    expect(preg_match('/if \[\[ "\$\{OPERATION\}" == apply \]\]; then\s+remote_command\+=\(--confirm-release\)/', $run))->toBe(1);

    $source = executableSourceLines(File::get(base_path('.github/actions/release-rateguru-recovered-outbound/action.yml')))
        .executableSourceLines(File::get(base_path('.github/workflows/release-tits-guru-recovered-outbound.yml')));

    foreach (['postsuper', 'postqueue', 'nsupdate', 'DEPLOY_HOST=', 'gh variable', 'gh api', 'offsite-write-hold', 'activate-mail-outbound', 'certbot', 'nginx -'] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse("the release surface must never reach {$forbidden}");
    }

    // Everything uploaded is removed, on every path.
    expect($steps['Remove the remote infrastructure bundle']['if'])->toBe('${{ always() }}');
    expect($steps['Remove temporary local files']['if'])->toBe('${{ always() }}');
});

it('judges the release result before it shows it, and fails a run whose result does not mean what its mode promises', function (array $result, int $remoteStatus, bool $passes) {
    $scratch = makeScratchDir('release-action');

    try {
        $env = actionStepEnv($scratch, [
            'BOOTSTRAP_HOST' => '203.0.113.10',
            'BOOTSTRAP_PORT' => '22',
            'BOOTSTRAP_USER' => 'root',
            'DEPLOYMENT_TARGET' => 'tits-guru',
            'OPERATION' => $result['mode'],
            'RECOVERY_OPERATION' => '20261020-120000-abcdef',
            'SENDING_ADDRESS' => '203.0.113.10',
            'RATEGURU_REMOTE_ROOT' => '/root/rateguru-recovered-outbound-1-1',
            'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $scratch.'/key',
            'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $scratch.'/known_hosts',
            'RGTEST_SSH_STDOUT' => "  PASS something\nRATEGURU_RECOVERED_OUTBOUND_RESULT=".json_encode($result),
            'RGTEST_SSH_EXIT' => (string) $remoteStatus,
        ]);

        $run = runActionStep('.github/actions/release-rateguru-recovered-outbound/action.yml', 'Run the release', $env);

        expect($run['exit'] === 0)->toBe($passes, $run['output']);

        $remote = (string) file_get_contents($scratch.'/ssh.log');
        expect(str_contains($remote, '--confirm-release'))->toBe($result['mode'] === 'apply');
        expect(str_contains($remote, "'--operation' '20261020-120000-abcdef' '--address' '203.0.113.10'"))->toBe($result['mode'] !== 'verify');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a passing check' => [['target' => 'tits-guru', 'mode' => 'check', 'status' => 'pass', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'released' => false, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => false, 'held_messages' => 2], 0, true],
    'a passing release' => [['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'released' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => true, 'held_messages' => 2], 0, true],
    'a release that claims a pass without readiness' => [['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'released' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => false, 'held_messages' => 2], 0, false],
    'a release for another target' => [['target' => 'staging-main', 'mode' => 'apply', 'status' => 'pass', 'operation' => null, 'address' => null, 'released' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => true, 'held_messages' => 0], 0, false],
    'a release rolled back' => [['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'fail', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'released' => false, 'changed' => true, 'rolled_back' => true, 'outbound_ready' => false, 'held_messages' => 2], 1, false],
    'a pass that exited non-zero' => [['target' => 'tits-guru', 'mode' => 'verify', 'status' => 'pass', 'operation' => '20261020-120000-abcdef', 'address' => '203.0.113.10', 'released' => true, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => true, 'held_messages' => 0], 1, false],
]);
