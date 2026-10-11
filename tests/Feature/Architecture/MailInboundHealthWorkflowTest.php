<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The inbound mail health check's operator surface: Check tits.guru inbound
 * mail health, which runs activate-mail-inbound --health from main behind the
 * shared gate and keeps one GitHub issue open while it fails, and Schedule
 * tits.guru inbound mail health, which every six hours asks for one run of it
 * on main — holding no secret and reaching no host itself.
 */

/** @return array<string, mixed> */
function mailInboundHealthWorkflow(string $file = 'check-tits-guru-inbound-mail-health.yml'): array
{
    return Yaml::parse(File::get(base_path(".github/workflows/{$file}")));
}

/**
 * Run SCRIPT as the runner would, with a gh that records every call and
 * answers a listing of open issues with ISSUES. Returns its exit status, its
 * output and the gh calls, one per line.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string, 2: string}
 */
function mailInboundHealthStep(string $script, array $env, string $issues = '[]'): array
{
    $scratch = makeScratchDir('inbound-health-step');

    try {
        mkdir($scratch.'/bin');
        file_put_contents($scratch.'/bin/gh', <<<'STUB'
            #!/bin/bash
            printf '%s\n' "$*" >> "${GH_CALLS}"
            if [[ "$1" == api && "$2" == repos/*/issues\?state=open* ]]; then printf '%s\n' "${GH_ISSUES}"; fi
            exit 0
            STUB."\n");
        chmod($scratch.'/bin/gh', 0o755);
        touch($scratch.'/calls');

        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            ...$env,
            'PATH' => $scratch.'/bin:'.getenv('PATH'),
            'GH_CALLS' => $scratch.'/calls',
            'GH_ISSUES' => $issues,
        ]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $status = proc_close($process);

        return [$status, $output, (string) file_get_contents($scratch.'/calls')];
    } finally {
        removeScratchDir($scratch);
    }
}

it('checks from main only, for tits-guru only, behind the shared gate, with the bootstrap credential alone', function () {
    $source = File::get(base_path('.github/workflows/check-tits-guru-inbound-mail-health.yml'));
    $workflow = mailInboundHealthWorkflow();

    expect($workflow['name'])->toBe('Check tits.guru inbound mail health');
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    // A group of its own: it never displaces a pending production operation.
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-mail-inbound-health', 'cancel-in-progress' => false]);
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', 'health', 'report']);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    $health = $workflow['jobs']['health'];
    expect($health['needs'])->toBe(['validate-ref']);
    expect($health['environment'])->toBe('production-tits-guru');
    expect($health['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($health['steps'][1]['uses'])->toBe('./.github/actions/activate-rateguru-mail-inbound');
    expect($health['steps'][1]['with'])->toBe([
        'operation' => 'health',
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);
    expect($health['outputs'])->toBe([
        'status' => '${{ steps.inbound.outputs.status }}',
        'waiting' => '${{ steps.inbound.outputs.waiting }}',
        'risk' => '${{ steps.inbound.outputs.risk }}',
        'oldest' => '${{ steps.inbound.outputs.oldest }}',
    ]);

    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);

    // The issue job holds no Environment and no secret, and may only write issues.
    $report = $workflow['jobs']['report'];
    expect($report)->not->toHaveKey('environment');
    expect($report['needs'])->toBe(['validate-ref', 'health']);
    expect($report['permissions'])->toBe(['issues' => 'write']);
    expect($report['if'])->toBe("\${{ always() && needs.validate-ref.result == 'success' && (needs.health.result == 'success' || needs.health.result == 'failure') }}");
    expect(json_encode($report))->not->toContain('secrets.')->not->toContain('uses');
    // REST only: gh's GraphQL issue commands are not used.
    expect($report['steps'][0]['run'])->not->toContain('gh issue');
});

it('opens one issue when the check fails, adds to it while it keeps failing, and closes it once it passes', function () {
    $script = mailInboundHealthWorkflow()['jobs']['report']['steps'][0]['run'];
    $env = ['GH_TOKEN' => 'token', 'REPOSITORY' => 'owner/repo', 'RUN_URL' => 'https://github.com/owner/repo/actions/runs/1'];
    $open = json_encode([['number' => 7, 'title' => 'tits-guru inbound mail health check failed'], ['number' => 9, 'title' => 'another issue']]);

    // At risk, nothing open yet: one issue, counts and ages only.
    [$status, $output, $calls] = mailInboundHealthStep($script, [...$env, 'HEALTH_RESULT' => 'failure', 'WAITING' => '3', 'RISK' => '2', 'OLDEST' => '93600']);
    expect($status)->toBe(0, $output);
    expect($calls)->toContain('api repos/owner/repo/issues -f title=tits-guru inbound mail health check failed -f body=AT RISK OF LOSS: 3 accepted inbound message(s) wait in the shared queue, 2 of them accepted more than a day ago; the oldest has waited 26 h.');
    expect($calls)->not->toContain('@');

    // Still failing: the open issue is added to, never a second one opened.
    [$status, , $calls] = mailInboundHealthStep($script, [...$env, 'HEALTH_RESULT' => 'failure', 'WAITING' => '1', 'RISK' => '0', 'OLDEST' => '600'], $open);
    expect($status)->toBe(0);
    expect($calls)->toContain('api repos/owner/repo/issues/7/comments -f body=1 accepted inbound message(s) wait in the shared queue instead of reaching the store; the oldest has waited 0 h.');
    expect($calls)->not->toContain('-f title=');

    // A run that could not judge the queue says so.
    [, , $calls] = mailInboundHealthStep($script, [...$env, 'HEALTH_RESULT' => 'failure', 'WAITING' => '', 'RISK' => '', 'OLDEST' => ''], $open);
    expect($calls)->toContain('whether accepted inbound mail is at risk is unknown');

    // Passing again: the issue is closed, with a note.
    [$status, , $calls] = mailInboundHealthStep($script, [...$env, 'HEALTH_RESULT' => 'success', 'WAITING' => '0', 'RISK' => '0', 'OLDEST' => '0'], $open);
    expect($status)->toBe(0);
    expect($calls)->toContain('api repos/owner/repo/issues/7/comments -f body=The inbound mail health check passes again')
        ->toContain('api -X PATCH repos/owner/repo/issues/7 -f state=closed -f state_reason=completed')
        ->not->toContain('issues/9');

    // Passing with nothing open: nothing is written.
    [, , $calls] = mailInboundHealthStep($script, [...$env, 'HEALTH_RESULT' => 'success', 'WAITING' => '0', 'RISK' => '0', 'OLDEST' => '0']);
    expect(trim($calls))->toBe('api repos/owner/repo/issues?state=open&per_page=100');
});

it('schedules a check every six hours, holding no secret and reaching no host — it only dispatches the check on main', function () {
    $source = File::get(base_path('.github/workflows/schedule-tits-guru-inbound-mail-health.yml'));
    $workflow = mailInboundHealthWorkflow('schedule-tits-guru-inbound-mail-health.yml');

    expect($workflow['name'])->toBe('Schedule tits.guru inbound mail health');
    expect($workflow['on'])->toBe(['schedule' => [['cron' => '41 */6 * * *']]]);
    expect($workflow['permissions'])->toBe([]);
    expect(array_keys($workflow['jobs']))->toBe(['dispatch']);

    $dispatch = $workflow['jobs']['dispatch'];
    // Nothing until an operator turns it on, after the activation.
    expect($dispatch['if'])->toBe("\${{ vars.RATEGURU_MAIL_INBOUND_HEALTH_SCHEDULE == 'enabled' }}");
    expect($dispatch['permissions'])->toBe(['actions' => 'write']);
    expect($dispatch)->not->toHaveKey('environment');
    expect(count($dispatch['steps']))->toBe(1);
    expect($source)->not->toContain('secrets.')->not->toContain('uses:')->not->toContain('ssh')->not->toContain('activate-mail-inbound');

    [$status, $output, $calls] = mailInboundHealthStep($dispatch['steps'][0]['run'], ['GH_TOKEN' => 'token', 'REPOSITORY' => 'owner/repo']);
    expect($status)->toBe(0, $output);
    expect(trim($calls))->toBe('api -X POST repos/owner/repo/actions/workflows/check-tits-guru-inbound-mail-health.yml/dispatches -f ref=main');
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
