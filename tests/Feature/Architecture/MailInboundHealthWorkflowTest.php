<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The automatic inbound mail health check's operator surface:
 *
 *   - Check tits.guru inbound mail health, which reaches the host only through
 *     the monitor's own read-only key, from an Environment of its own that
 *     needs no approval, and keeps one GitHub issue open while it fails;
 *   - Schedule tits.guru inbound mail health, which every six hours asks for
 *     one run of it on main, holding no secret and reaching no host itself;
 *   - Install tits.guru inbound mail monitor, which installs that read-only
 *     access once, behind the production Environment's approval.
 *
 * The runner's own steps are run here as the runner would, with gh and ssh
 * stood in for.
 */

/** @return array<string, mixed> */
function mailInboundHealthWorkflow(string $file = 'check-tits-guru-inbound-mail-health.yml'): array
{
    return Yaml::parse(File::get(base_path(".github/workflows/{$file}")));
}

/**
 * Run SCRIPT as the runner would, with gh and ssh stood in for: gh records
 * every call and answers a listing of open issues with ISSUES; ssh records its
 * arguments, prints SSH_OUTPUT and exits SSH_STATUS. Returns the exit status,
 * the output, the calls, the step's outputs and its summary.
 *
 * @param  array<string, string>  $env
 * @return array{status: int, output: string, calls: string, outputs: array<string, string>, summary: string}
 */
function mailInboundHealthStep(string $script, array $env, string $issues = '[]', string $sshOutput = '', int $sshStatus = 0): array
{
    $scratch = makeScratchDir('inbound-health-step');

    try {
        mkdir($scratch.'/bin');
        mkdir($scratch.'/temp');
        file_put_contents($scratch.'/bin/gh', <<<'STUB'
            #!/bin/bash
            printf '%s\n' "$*" >> "${STUB_CALLS}"
            if [[ "$1" == api && "$2" == repos/*/issues\?state=open* ]]; then printf '%s\n' "${STUB_ISSUES}"; fi
            exit 0
            STUB."\n");
        file_put_contents($scratch.'/bin/ssh', <<<'STUB'
            #!/bin/bash
            printf 'ssh %s\n' "$*" >> "${STUB_CALLS}"
            printf '%s' "${STUB_SSH_OUTPUT}"
            exit "${STUB_SSH_STATUS}"
            STUB."\n");
        chmod($scratch.'/bin/gh', 0o755);
        chmod($scratch.'/bin/ssh', 0o755);
        foreach (['calls', 'outputs', 'summary'] as $file) {
            touch($scratch.'/'.$file);
        }

        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
            ...$env,
            'PATH' => $scratch.'/bin:'.getenv('PATH'),
            'RUNNER_TEMP' => $scratch.'/temp',
            'GITHUB_OUTPUT' => $scratch.'/outputs',
            'GITHUB_STEP_SUMMARY' => $scratch.'/summary',
            'STUB_CALLS' => $scratch.'/calls',
            'STUB_ISSUES' => $issues,
            'STUB_SSH_OUTPUT' => $sshOutput,
            'STUB_SSH_STATUS' => (string) $sshStatus,
        ]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        $outputs = [];
        foreach (preg_split('/\R/', (string) file_get_contents($scratch.'/outputs')) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $outputs[$key] = $value;
            }
        }

        expect(glob($scratch.'/temp/*') ?: [])->toBe([], 'the monitor key outlived the step');

        return ['status' => $status, 'output' => $output, 'calls' => (string) file_get_contents($scratch.'/calls'), 'outputs' => $outputs, 'summary' => (string) file_get_contents($scratch.'/summary')];
    } finally {
        removeScratchDir($scratch);
    }
}

/** The monitor's result line for STATUS, as the monitor prints it. */
function mailInboundMonitorReport(string $status, ?array $queue, string $target = 'tits-guru'): string
{
    return "rateguru inbound mail monitor — {$target} (read-only)\n  PASS something\nRATEGURU_MAIL_INBOUND_MONITOR_RESULT="
        .json_encode(['target' => $target, 'mode' => 'monitor', 'status' => $status, 'state' => 'installed-enabled', 'gate' => 'DUNNO', 'queue' => $queue])."\n";
}

it('checks from main only, through the monitor\'s own key, from an Environment of its own — never the bootstrap credential', function () {
    $source = File::get(base_path('.github/workflows/check-tits-guru-inbound-mail-health.yml'));
    $workflow = mailInboundHealthWorkflow();

    expect($workflow['name'])->toBe('Check tits.guru inbound mail health');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-mail-inbound-health', 'cancel-in-progress' => false]);
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'monitor', 'report']);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    // The monitor's Environment, which the runbook creates without reviewers
    // and for main alone — never production-tits-guru, which holds the
    // bootstrap credential behind its approval.
    $monitor = $workflow['jobs']['monitor'];
    expect($monitor['needs'])->toBe(['validate-ref']);
    expect($monitor['environment'])->toBe('production-tits-guru-monitor');
    expect($source)->not->toContain('production-tits-guru'."\n")->not->toContain('BOOTSTRAP')->not->toContain('DEPLOY_SSH_KEY');
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['MAIL_MONITOR_SSH_KEY', 'MAIL_MONITOR_KNOWN_HOSTS']);

    // Nothing of this repository runs on the host: no checkout, no action, no upload.
    expect(count($monitor['steps']))->toBe(1);
    expect(json_encode($workflow['jobs']))->not->toContain('actions/checkout')->not->toContain('uses')->not->toContain('scp')->not->toContain('activate-mail-inbound');

    // The fixed account, strict host checking, and no remote command at all.
    $run = $monitor['steps'][0]['run'];
    expect($run)
        ->toContain('"rateguru-mail-monitor@${MONITOR_HOST}" 2>&1 </dev/null)"')
        ->toContain('-o BatchMode=yes')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile="${known_hosts}"');
    expect($monitor['outputs'])->toBe([
        'status' => '${{ steps.monitor.outputs.status }}',
        'waiting' => '${{ steps.monitor.outputs.waiting }}',
        'risk' => '${{ steps.monitor.outputs.risk }}',
        'oldest' => '${{ steps.monitor.outputs.oldest }}',
    ]);
});

it('says PASS only for a monitor that ran, said pass, exited 0 and found nothing waiting — anything else is FAILED or UNKNOWN', function (string $sshOutput, int $sshStatus, array $env, string $status, int $exit, string $summary) {
    $script = mailInboundHealthWorkflow()['jobs']['monitor']['steps'][0]['run'];
    $env = [...['MONITOR_HOST' => 'host.example', 'MONITOR_PORT' => '22', 'MONITOR_SSH_KEY' => "-----BEGIN OPENSSH PRIVATE KEY-----\nmonitor\n-----END OPENSSH PRIVATE KEY-----", 'MONITOR_KNOWN_HOSTS' => 'host.example ssh-ed25519 AAAA'], ...$env];

    $run = mailInboundHealthStep($script, $env, '[]', $sshOutput, $sshStatus);

    expect($run['status'])->toBe($exit, $run['output']);
    expect($run['outputs']['status'] ?? null)->toBe($status);
    expect($run['summary'])->toContain('**'.strtoupper($status).'** — ')->toContain($summary)->not->toContain('monitor@');
    if ($run['calls'] !== '') {
        // ssh was asked for nothing but a session: the key's own command is all it can run.
        expect(trim($run['calls']))->toEndWith('-T rateguru-mail-monitor@host.example');
    }
})->with([
    'passing' => [mailInboundMonitorReport('pass', ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]), 0, [], 'pass', 0, 'the store takes mail and no accepted inbound mail waits'],
    'mail at risk' => [mailInboundMonitorReport('fail', ['inbound' => 2, 'waiting' => 2, 'at_risk' => 1, 'oldest_seconds' => 93600, 'queues' => ['deferred' => 2]]), 1, [], 'fail', 1, 'accepted inbound mail is AT RISK OF LOSS: 1 message(s)'],
    'the store refused' => [mailInboundMonitorReport('fail', ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]), 1, [], 'fail', 1, 'the inbound store, or accepted inbound mail, needs attention'],
    'ssh refused' => ['Permission denied (publickey).', 255, [], 'unknown', 1, 'the monitor did not report (ssh exited 255; 0 result line(s))'],
    'a shell instead of the monitor' => ["Welcome\n$ ", 0, [], 'unknown', 1, 'the monitor did not report (ssh exited 0; 0 result line(s))'],
    'pass, but a failing exit' => [mailInboundMonitorReport('pass', ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]), 1, [], 'unknown', 1, 'the monitor said pass, but exited 1'],
    'pass, without reading the queue' => [mailInboundMonitorReport('pass', null), 0, [], 'unknown', 1, 'the monitor said pass, but exited 0 or left its queue reading out'],
    'another target\'s report' => [mailInboundMonitorReport('pass', ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []], 'staging-main'), 0, [], 'unknown', 1, 'not a report of the tits-guru inbound mail monitor'],
    'two reports' => [str_repeat(mailInboundMonitorReport('pass', ['inbound' => 0, 'waiting' => 0, 'at_risk' => 0, 'oldest_seconds' => 0, 'queues' => []]), 2), 0, [], 'unknown', 1, '2 result line(s)'],
    'no key in the Environment' => ['', 0, ['MONITOR_SSH_KEY' => ''], 'unknown', 1, 'the production-tits-guru-monitor Environment does not hold the monitor\'s host, key and known host'],
]);

it('opens one issue while the check fails or cannot judge, adds to it, and closes it once it passes', function () {
    $script = mailInboundHealthWorkflow()['jobs']['report']['steps'][0]['run'];
    $env = ['GH_TOKEN' => 'token', 'REPOSITORY' => 'owner/repo', 'RUN_URL' => 'https://github.com/owner/repo/actions/runs/1'];
    $open = json_encode([['number' => 7, 'title' => 'tits-guru inbound mail health check failed'], ['number' => 9, 'title' => 'another issue']]);

    // At risk, nothing open yet: one issue, counts and ages only.
    $run = mailInboundHealthStep($script, [...$env, 'MONITOR_STATUS' => 'fail', 'WAITING' => '3', 'RISK' => '2', 'OLDEST' => '93600']);
    expect($run['status'])->toBe(0, $run['output']);
    expect($run['calls'])->toContain('api repos/owner/repo/issues -f title=tits-guru inbound mail health check failed -f body=AT RISK OF LOSS: 3 accepted inbound message(s) wait in the shared queue, 2 of them accepted more than a day ago; the oldest has waited 26 h.');
    expect($run['calls'])->not->toContain('@');

    // Still failing: the open issue is added to, never a second one opened.
    $run = mailInboundHealthStep($script, [...$env, 'MONITOR_STATUS' => 'fail', 'WAITING' => '1', 'RISK' => '0', 'OLDEST' => '600'], $open);
    expect($run['calls'])->toContain('api repos/owner/repo/issues/7/comments -f body=1 accepted inbound message(s) wait in the shared queue instead of reaching the store; the oldest has waited 0 h.');
    expect($run['calls'])->not->toContain('-f title=');

    // A monitor that could not judge — unreachable, no report, cancelled — is UNKNOWN, never a pass.
    foreach (['unknown', ''] as $status) {
        $run = mailInboundHealthStep($script, [...$env, 'MONITOR_STATUS' => $status, 'WAITING' => '', 'RISK' => '', 'OLDEST' => ''], $open);
        expect($run['calls'])->toContain('UNKNOWN: the monitor did not report, or could not read the shared queue')->not->toContain('state=closed');
    }

    // Passing again: the issue is closed, with a note.
    $run = mailInboundHealthStep($script, [...$env, 'MONITOR_STATUS' => 'pass', 'WAITING' => '0', 'RISK' => '0', 'OLDEST' => '0'], $open);
    expect($run['calls'])->toContain('api repos/owner/repo/issues/7/comments -f body=The inbound mail health check passes again')
        ->toContain('api -X PATCH repos/owner/repo/issues/7 -f state=closed -f state_reason=completed')
        ->not->toContain('issues/9');

    // Passing with nothing open: nothing is written.
    $run = mailInboundHealthStep($script, [...$env, 'MONITOR_STATUS' => 'pass', 'WAITING' => '0', 'RISK' => '0', 'OLDEST' => '0']);
    expect(trim($run['calls']))->toBe('api repos/owner/repo/issues?state=open&per_page=100');

    // The issue job runs whenever the monitor job ran, whatever its result, and holds no Environment and no secret.
    $report = mailInboundHealthWorkflow()['jobs']['report'];
    expect($report['if'])->toBe("\${{ always() && needs.validate-ref.result == 'success' && needs.monitor.result != 'skipped' }}");
    expect($report)->not->toHaveKey('environment');
    expect($report['permissions'])->toBe(['issues' => 'write']);
    expect(json_encode($report))->not->toContain('secrets.')->not->toContain('uses');
    expect($report['steps'][0]['run'])->not->toContain('gh issue');
});

it('installs the monitor\'s access once, from main, behind the production approval, judging the key before any secret', function () {
    $source = File::get(base_path('.github/workflows/install-tits-guru-inbound-mail-monitor.yml'));
    $workflow = mailInboundHealthWorkflow('install-tits-guru-inbound-mail-monitor.yml');

    expect($workflow['name'])->toBe('Install tits.guru inbound mail monitor');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect(array_keys($workflow['on']['workflow_dispatch']['inputs']))->toBe(['public_key']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'validate-key', 'install']);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    $validate = $workflow['jobs']['validate-key'];
    expect($validate['needs'])->toBe(['validate-ref']);
    expect($validate)->not->toHaveKey('environment');
    expect(json_encode($validate))->not->toContain('secrets.');
    $judge = static function (string $key) use ($validate): int {
        $process = proc_open(['bash', '-c', $validate['steps'][0]['run']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PUBLIC_KEY' => $key, 'PATH' => getenv('PATH')]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    };
    expect($judge(MAIL_MONITOR_KEY))->toBe(0);
    foreach (['', 'command="/bin/sh" '.MAIL_MONITOR_KEY, "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----", 'ssh-rsa AAAAB3NzaC1yc2E', MAIL_MONITOR_KEY."\nssh-ed25519 AAAA"] as $wrong) {
        expect($judge($wrong))->not->toBe(0, "accepted \"{$wrong}\"");
    }

    $install = $workflow['jobs']['install'];
    expect($install['needs'])->toBe(['validate-ref', 'validate-key']);
    expect($install['environment'])->toBe('production-tits-guru');
    expect($install['steps'][1]['with'])->toBe([
        'operation' => 'install-monitor',
        'deployment-target' => 'tits-guru',
        'monitor-public-key' => '${{ inputs.public_key }}',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);
    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);
});

it('schedules a check every six hours, holding no secret and reaching no host — it only dispatches the check on main', function () {
    $source = File::get(base_path('.github/workflows/schedule-tits-guru-inbound-mail-health.yml'));
    $workflow = mailInboundHealthWorkflow('schedule-tits-guru-inbound-mail-health.yml');

    expect($workflow['name'])->toBe('Schedule tits.guru inbound mail health');
    expect($workflow['on'])->toBe(['schedule' => [['cron' => '41 */6 * * *']]]);
    expect($workflow['permissions'])->toBe([]);
    expect(array_keys($workflow['jobs']))->toBe(['dispatch']);

    $dispatch = $workflow['jobs']['dispatch'];
    expect($dispatch['if'])->toBe("\${{ vars.RATEGURU_MAIL_INBOUND_HEALTH_SCHEDULE == 'enabled' }}");
    expect($dispatch['permissions'])->toBe(['actions' => 'write']);
    expect($dispatch)->not->toHaveKey('environment');
    expect(count($dispatch['steps']))->toBe(1);
    expect($source)->not->toContain('secrets.')->not->toContain('uses:')->not->toContain('ssh ')->not->toContain('activate-mail-inbound');

    $run = mailInboundHealthStep($dispatch['steps'][0]['run'], ['GH_TOKEN' => 'token', 'REPOSITORY' => 'owner/repo']);
    expect($run['status'])->toBe(0, $run['output']);
    expect(trim($run['calls']))->toBe('api -X POST repos/owner/repo/actions/workflows/check-tits-guru-inbound-mail-health.yml/dispatches -f ref=main');
});

it('is the only workflow that starts the health check, and nothing else runs on a schedule', function () {
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));
        $triggers = array_keys((array) ($workflow['on'] ?? $workflow[true] ?? []));
        $name = basename($path);

        expect(in_array('schedule', $triggers, true))->toBe($name === 'schedule-tits-guru-inbound-mail-health.yml', "{$name} runs on a schedule");
        if ($name !== 'schedule-tits-guru-inbound-mail-health.yml') {
            expect(File::get($path))->not->toContain('check-tits-guru-inbound-mail-health.yml/dispatches');
        }
    }
});

it('gives the monitor\'s Environment, in the runbook, no reviewer, main alone and the monitor key alone', function () {
    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-inbound.md')));

    expect($runbook)
        ->toContain('**Name** `production-tits-guru-monitor`')
        ->toContain('**Required reviewers**: none')
        ->toContain('**Deployment branches and tags**: Selected branches and tags — the branch `main`, and nothing else')
        ->toContain('**Secrets**: `MAIL_MONITOR_SSH_KEY` — the private half of the monitor key; `MAIL_MONITOR_KNOWN_HOSTS`')
        ->toContain('Never `BOOTSTRAP_SSH_KEY`, `DEPLOY_SSH_KEY` or any other key');
});
