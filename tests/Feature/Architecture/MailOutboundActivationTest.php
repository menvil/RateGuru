<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * activate-mail-outbound: the guarded switch of a planned production target's
 * mail from held to direct outbound delivery, its rollback, and the workflows
 * that run them.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

/** What the simulated host's postconf reads back: `-P SPEC`, or `-M`. */
function mailActivationPostconf(array $host, string ...$arguments): string
{
    $process = proc_open([$host['scratch'].'/bin/postconf', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $host['scratch'], $host['env']);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

// =============================================================================
// THE COMMITTED STATE IS INERT
// =============================================================================

it('keeps tits-guru held, direct delivery disabled and the target planned in the committed configuration', function () {
    $routing = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);
    expect($routing['targets']['tits-guru']['delivery_mode'])->toBe('held');
    expect($routing['targets']['tits-guru'])->not->toHaveKey('outbound');

    $outbound = json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true);
    expect($outbound['direct'])->toBe(['enabled' => false, 'mta_hostname' => 'mta1.tits.guru']);

    $registry = collect(json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'])
        ->keyBy('id');
    expect($registry['tits-guru']['lifecycle'])->toBe('planned');

    // No production application mail transport is set by this tooling: the
    // templates still leave it to the operation before the first deploy.
    foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS'] as $key) {
        expect(envFileValues('infrastructure/templates/environment/tits-guru.env.example')[$key])->toBe('');
    }
    expect(envFileValues('infrastructure/templates/environment/production.env.example')['MAIL_MAILER'])->toBe('');
});

it('refuses every mode from the committed configuration before it changes anything: activation is not requested', function (string $mode) {
    $host = mailActivationHost(['requested' => false]);

    try {
        [$status, $output] = mailActivationRun($host, ["--{$mode}", '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain('activation is not requested by this trusted bundle');

        $result = mailActivationResult($output);
        expect($result)->toMatchArray(['target' => 'tits-guru', 'mode' => $mode, 'status' => 'fail', 'requested' => false, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => false]);

        // Nothing was applied, no probe was submitted, no capsule was written.
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
})->with(['check', 'apply', 'verify', 'rollback']);

// =============================================================================
// THE ONE TRANSITION
// =============================================================================

/**
 * transition_problems, sourced from the shipped script, for the committed
 * pre-activation documents against a request changed by CHANGE.
 */
function mailActivationTransitionProblems(string $change): string
{
    $scratch = makeScratchDir('mail-activation-transition');
    $request = mailActivationRequest();
    $pre = [
        'routing' => json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true),
        'outbound' => json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true),
    ];

    match ($change) {
        'none' => null,
        'another target' => $request['routing']['targets']['staging-main']['submission']['port'] = 2599,
        'its submission' => $request['routing']['targets']['tits-guru']['submission']['port'] = 2599,
        'its mail domain' => $request['routing']['targets']['tits-guru']['mail_domain'] = 'mail.tits.guru',
        'its sender' => $request['routing']['targets']['tits-guru']['default_from'] = 'hello@tits.guru',
        'its bounce domain' => $request['routing']['targets']['tits-guru']['bounce_domain'] = 'bounces.tits.guru',
        'its reply domain' => $request['routing']['targets']['tits-guru']['reply_domain'] = 'replies.tits.guru',
        'a relay transport' => $request['routing']['targets']['tits-guru']['outbound'] = ['kind' => 'relay'],
        'an extra route property' => $request['routing']['targets']['tits-guru']['outbound']['host'] = 'smtp.example.net',
        'still held' => [$request['routing']['targets']['tits-guru']['delivery_mode'] = 'held', $request['routing']['targets']['tits-guru'] = array_diff_key($request['routing']['targets']['tits-guru'], ['outbound' => 1])],
        'direct still disabled' => $request['outbound']['direct']['enabled'] = false,
        'another MTA hostname' => $request['outbound']['direct']['mta_hostname'] = 'mta2.tits.guru',
        'another host field' => $request['outbound']['direct']['relayhost'] = 'smtp.example.net',
        'the schema' => $request['routing']['schema_version'] = 3,
        'a pre-activation that was not held' => $pre['routing']['targets']['tits-guru']['delivery_mode'] = 'outbound',
        'a pre-activation with direct enabled' => $pre['outbound']['direct']['enabled'] = true,
    };

    foreach (['pre-routing' => $pre['routing'], 'requested-routing' => $request['routing'], 'pre-outbound' => $pre['outbound'], 'requested-outbound' => $request['outbound']] as $name => $data) {
        file_put_contents("{$scratch}/{$name}.json", mailRoutingJson($data));
    }

    $command = 'set -Eeuo pipefail; source '.escapeshellarg(base_path('infrastructure/scripts/activate-mail-outbound'))
        .'; TARGET_ID=tits-guru; transition_problems pre-routing.json requested-routing.json pre-outbound.json requested-outbound.json';
    $process = proc_open(['bash', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $scratch);
    $problems = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $errors);
    removeScratchDir($scratch);

    return $problems;
}

it('accepts exactly held to outbound by direct delivery, and direct delivery disabled to enabled', function () {
    expect(mailActivationTransitionProblems('none'))->toBe('');
});

it('refuses any request that is not exactly that transition', function (string $change, string $problem) {
    expect(mailActivationTransitionProblems($change))->toContain($problem);
})->with([
    'another target' => ['another target', 'the mail routing of staging-main differs — an activation changes tits-guru alone'],
    'its submission' => ['its submission', "tits-guru's submission differ between the pre-activation and the requested state"],
    'its mail domain' => ['its mail domain', "tits-guru's mail_domain differ"],
    'its sender' => ['its sender', "tits-guru's default_from differ"],
    'its bounce domain' => ['its bounce domain', "tits-guru's bounce_domain differ"],
    'its reply domain' => ['its reply domain', "tits-guru's reply_domain differ"],
    'a relay transport' => ['a relay transport', "tits-guru's requested route must be exactly {\"kind\": \"direct\"}, got {\"kind\":\"relay\"}"],
    'an extra route property' => ['an extra route property', "tits-guru's requested route must be exactly {\"kind\": \"direct\"}"],
    'still held' => ['still held', "the requested state does not deliver tits-guru's mail outbound"],
    'direct still disabled' => ['direct still disabled', 'the requested host contract does not enable direct delivery'],
    'another MTA hostname' => ['another MTA hostname', 'the MTA hostname differs: "mta1.tits.guru" before, "mta2.tits.guru" requested'],
    'another host field' => ['another host field', 'the host outbound contract differs in more than direct.enabled (direct)'],
    'the schema' => ['the schema', 'the mail routing policy differs outside its targets (schema_version)'],
    'a pre-activation that was not held' => ['a pre-activation that was not held', "before activation tits-guru's mail must be held"],
    'a pre-activation with direct enabled' => ['a pre-activation with direct enabled', 'before activation direct delivery must be disabled'],
]);

it('derives the pre-activation documents by undoing exactly the three changes', function () {
    $source = File::get(base_path('infrastructure/scripts/activate-mail-outbound'));

    // The derivation is these two programs and nothing else; the committed
    // documents are what they give back from the activation request.
    expect($source)
        ->toContain("PRE_ROUTING_PROGRAM='.targets[\$t].delivery_mode = \"held\" | .targets[\$t] |= del(.outbound)'")
        ->toContain("PRE_OUTBOUND_PROGRAM='.direct.enabled = false'");

    $scratch = makeScratchDir('mail-activation-derive');
    $request = mailActivationRequest();
    file_put_contents($scratch.'/routing.json', mailRoutingJson($request['routing']));
    file_put_contents($scratch.'/outbound.json', mailRoutingJson($request['outbound']));

    $routing = shell_exec('jq --arg t tits-guru '.escapeshellarg('.targets[$t].delivery_mode = "held" | .targets[$t] |= del(.outbound)').' '.escapeshellarg($scratch.'/routing.json'));
    $outbound = shell_exec('jq '.escapeshellarg('.direct.enabled = false').' '.escapeshellarg($scratch.'/outbound.json'));
    removeScratchDir($scratch);

    expect(json_decode($routing, true))->toBe(json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true));
    expect(json_decode($outbound, true))->toBe(json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true));
});

it('refuses a request whose transport the routing policy does not implement before anything else', function () {
    $request = mailActivationRequest();
    $request['routing']['targets']['tits-guru']['outbound'] = ['kind' => 'relay'];
    $host = mailActivationHost(['routing' => $request['routing']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain('outbound.kind must be one of direct, got "relay"')
            ->toContain("the trusted bundle's mail routing policy is not valid — nothing was changed");
        expect(mailActivationCalls($host))->toBe([]);
    } finally {
        mailActivationCleanup($host);
    }
});

it('refuses a request the host cannot witness as its own pre-activation state', function (string $change, string $problem) {
    $request = mailActivationRequest();

    match ($change) {
        // Another target changed alongside: the derived pre-activation
        // gateway is not the one installed.
        'another target' => $request['routing']['targets']['staging-main']['submission']['port'] = 2599,
        // The MTA hostname changed: public DNS names the reviewed one.
        'the MTA hostname' => $request['outbound']['direct']['mta_hostname'] = 'mta2.tits.guru',
    };

    $host = mailActivationHost(['routing' => $request['routing'], 'outbound' => $request['outbound']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('the pre-activation proof failed');
        expect(mailActivationResult($output))->toMatchArray(['status' => 'fail', 'requested' => true, 'changed' => false, 'rolled_back' => false]);
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(mailActivationInstalledMode($host))->toBe('held');
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'another target' => ['another target', 'FAIL the installed gateway is not exactly the pre-activation render'],
    'the MTA hostname' => ['the MTA hostname', 'FAIL public DNS or the key does not verify for tits-guru'],
]);

it('refuses an activation that also changes an identity field the host accepted, before anything changes', function (string $field, string $value) {
    // The legitimate transition, plus one more change of tits-guru's identity
    // that Postfix never renders: the derived pre-activation state is then a
    // policy the host never accepted, and its own record says so.
    $request = mailActivationRequest();
    $request['routing']['targets']['tits-guru'][$field] = $value;
    $host = mailActivationHost(['routing' => $request['routing']]);

    try {
        foreach (['--check', '--apply'] as $mode) {
            [$status, $output] = mailActivationRun($host, [$mode, '--target', 'tits-guru']);

            expect($status)->toBe(1, $output);
            expect($output)
                ->toContain("DRIFT    file:/var/lib/rateguru-mail-gateway/applied-plan.json — the recorded policy differs from the one this bundle requests (listeners.tits-guru.sender.{$field})")
                ->toContain('FAIL the installed gateway is not exactly the pre-activation render');
            expect(mailActivationResult($output))->toMatchArray(['status' => 'fail', 'changed' => false, 'rolled_back' => false]);
        }

        // No probe, no authorization, no capsule, no change.
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e') || str_contains($call, '--policy-digest')))->toBe([]);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();
        expect(mailActivationInstalledMode($host))->toBe('held');
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'default_from' => ['default_from', 'hello@tits.guru'],
    'bounce_domain' => ['bounce_domain', 'bounces.tits.guru'],
    'reply_domain' => ['reply_domain', 'replies.tits.guru'],
]);

it('leaves an ordinary Prepare unable to cross the boundary again after the initial-launch rollback', function () {
    $host = mailActivationHost();

    try {
        [$activated] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        expect($activated)->toBe(0);
        [$rolledBack] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);
        expect($rolledBack)->toBe(0);
        expect(mailActivationInstalledMode($host))->toBe('held');

        // main still requests outbound; Prepare applies the gateway from it.
        [$status, $output] = mailActivationRun($host, ['--apply'], script: 'install-mail-gateway');
        expect($status)->toBe(1, $output);
        expect($output)->toContain("this bundle moves tits-guru's mail from held to outbound — the activation boundary, which only activate-mail-outbound crosses");
        expect(mailActivationInstalledMode($host))->toBe('held');

        // Only another guarded activation crosses it again.
        [$again, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        expect($again)->toBe(0, $output);
        expect(mailActivationInstalledMode($host))->toBe('outbound');
        expect(substr_count(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'), ' activate tits-guru '))->toBe(2);
    } finally {
        mailActivationCleanup($host);
    }
});

it('crosses back with its own rollback authorization only when the gateway recorded the outbound policy, and leaves none behind', function (string $toggle, bool $recordedOutbound) {
    $host = mailActivationHost(['toggles' => [$toggle]]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect(mailActivationResult($output))->toMatchArray(['rolled_back' => true]);
        expect(mailActivationInstalledMode($host))->toBe('held');

        $ledger = (string) @file_get_contents($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations');
        if ($recordedOutbound) {
            // The activation was consumed, then the rollback.
            expect($ledger)->toContain(' activate tits-guru ')->toContain(' rollback tits-guru ');
            expect($output)->toContain('authorized the rollback of tits-guru');
        } else {
            // The gateway never ran: nothing was consumed, nothing needed
            // authorizing on the way back, and the unused activation was withdrawn.
            expect($ledger)->toBe('');
            expect($output)->not->toContain('authorized the rollback')->toContain('withdrew the unused transition-authorization.json');
        }

        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'the installer refused' => ['gateway-apply-fails-outbound', false],
    'the installer failed after installing' => ['gateway-apply-breaks-outbound', true],
    'readiness is not YES' => ['readonly-signing-fails', true],
]);

// =============================================================================
// THE PRE-ACTIVATION PROOF: NOTHING CHANGES UNTIL ALL OF IT HOLDS
// =============================================================================

it('changes nothing when any part of the pre-activation proof fails', function (string $case, string $problem, bool $probed) {
    $options = match ($case) {
        'bad DNS', 'missing key', 'weak key' => [],
        'signer down' => ['toggles' => ['signer-down']],
        'foreign From accepted' => ['toggles' => ['e2e-foreign-accepted']],
        'held mail' => ['queue' => ["0123ABCDEF\thold\tsomeone@example.net", "FOREIGN0001\tdeferred\tsomeone@example.net"]],
        'public SMTP' => [],
        'gateway not held' => ['installed' => 'drifted', 'listeners' => ['127.0.0.1:1025', '127.0.0.1:1026']],
    };

    if (($options['installed'] ?? null) === 'drifted') {
        $drifted = [
            'routing' => json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true),
            'outbound' => json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true),
        ];
        $drifted['routing']['targets']['staging-main']['capture']['port'] = 1026;
        $options['installed'] = $drifted;
    }

    $host = mailActivationHost($options);

    try {
        if ($case === 'bad DNS') {
            mailIdentityDnsHost($host['scratch'], [
                ...mailIdentityGoodDns(mailIdentityPublicKey($host['key'])),
                'TXT _dmarc.tits.guru' => ['"v=DMARC1; p=none"'],
            ]);
        }

        if ($case === 'public SMTP') {
            file_put_contents($host['state'].'/listeners', "0.0.0.0:25\n", FILE_APPEND);
        }

        if ($case === 'missing key') {
            unlink($host['key']);
        }

        if ($case === 'weak key') {
            copy(mailIdentityKey('rsa1024'), $host['key']);
        }

        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('nothing was changed');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'apply', 'status' => 'fail', 'requested' => true, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => false]);

        $calls = mailActivationCalls($host);
        expect(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply')))->toBe([]);
        expect(in_array('verify-mail-signing --e2e --target tits-guru [held]', $calls, true))->toBe($probed);
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();

        // Held mail is named by its queue ID alone, and never touched.
        if ($case === 'held mail') {
            expect($output)->not->toContain('someone@example.net');
            expect(File::get($host['state'].'/queue'))->toContain("0123ABCDEF\thold");
            expect(File::get($host['log'].'/queue.log'))->toBe("postqueue -j\n");
        }
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'bad DNS' => ['bad DNS', 'FAIL public DNS or the key does not verify for tits-guru', false],
    'signer down' => ['signer down', 'FAIL the signer is not verified for tits-guru', false],
    'missing key' => ['missing key', '/etc/opendkim/keys/tits-guru/rg1.private is not usable: it does not exist', false],
    'weak key' => ['weak key', 'below the reviewed minimum of 2048 bits', false],
    'foreign From accepted' => ['foreign From accepted', 'FAIL the signing acceptance did not pass', true],
    'held mail' => ['held mail', 'FAIL the HOLD queue is not empty (0123ABCDEF)', true],
    'public SMTP' => ['public SMTP', 'something listens on 0.0.0.0:25 — no SMTP service may listen on port 25', false],
    'gateway not held' => ['gateway not held', 'FAIL the installed gateway is not exactly the pre-activation render', false],
]);

it('refuses to activate, or roll back, a target that is not planned', function () {
    // The registry itself refuses an active tits-guru today, so from the CLI
    // that refusal comes first and nothing is changed.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
    foreach ($registry['targets'] as $index => $target) {
        if ($target['id'] === 'tits-guru') {
            $registry['targets'][$index]['lifecycle'] = 'active';
        }
    }
    $host = mailActivationHost();
    file_put_contents($host['bundle'].'/infrastructure/config/deployment-targets.json', mailRoutingJson($registry));

    try {
        foreach (['--apply', '--rollback'] as $mode) {
            [$status, $output] = mailActivationRun($host, [$mode, '--target', 'tits-guru']);

            expect($status)->toBe(1, $output);
            expect($output)->toContain('nothing was changed');
        }
        expect(mailActivationCalls($host))->toBe([]);
    } finally {
        mailActivationCleanup($host);
    }

    // And the activation's own guards, for the day a registry admits it: each
    // refuses anything but a planned production target.
    $guard = static function (string $function, string $environment, string $lifecycle): array {
        $command = 'source '.escapeshellarg(base_path('infrastructure/scripts/activate-mail-outbound'))
            ."; TARGET_ID=tits-guru; T_ENVIRONMENT={$environment}; T_LIFECYCLE={$lifecycle}; {$function}";
        exec('bash -c '.escapeshellarg($command).' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    };

    expect($guard('require_planned_production', 'production', 'planned'))->toBe([0, '']);
    expect($guard('require_initial_launch', 'production', 'planned'))->toBe([0, '']);

    [$status, $output] = $guard('require_planned_production', 'production', 'active');
    expect($status)->toBe(1);
    expect($output)->toContain('tits-guru is lifecycle=active, not planned — this is the initial activation of a target that has not gone live, and it is never used on a live one');

    [$status, $output] = $guard('require_planned_production', 'staging', 'planned');
    expect($status)->toBe(1);
    expect($output)->toContain("only a production target's mail is ever delivered outbound");

    [$status, $output] = $guard('require_initial_launch', 'production', 'active');
    expect($status)->toBe(1);
    expect($output)->toContain("the initial-launch rollback exists only while a target is planned, and a live target's mail is never stopped this way");

    // Both are called by the modes that change anything.
    $source = File::get(base_path('infrastructure/scripts/activate-mail-outbound'));
    expect(executableSourceLines(shellFunctionBody($source, 'run_apply')))->toContain('require_planned_production');
    expect(executableSourceLines(shellFunctionBody($source, 'run_rollback')))->toContain('require_initial_launch');
});

it('refuses while another operation holds the host infrastructure lock', function () {
    $host = mailActivationHost();

    // The same flock(2) lock prepare-host, provision, configure and repair take.
    $holder = fopen($host['scratch'].'/run/host-infrastructure.lock', 'c');
    expect(flock($holder, LOCK_EX))->toBeTrue();

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain("another operation is already mutating this host's shared infrastructure");
        expect(mailActivationCalls($host))->toBe([]);
    } finally {
        flock($holder, LOCK_UN);
        fclose($holder);
        mailActivationCleanup($host);
    }
});

it('proves everything --apply would and submits nothing in --check', function () {
    $host = mailActivationHost();

    try {
        [$status, $output] = mailActivationRun($host, ['--check', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION CHECK: READY');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'check', 'status' => 'pass', 'changed' => false]);
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect(is_dir(mailActivationCapsule($host)))->toBeFalse();
    } finally {
        mailActivationCleanup($host);
    }
});

// =============================================================================
// APPLY
// =============================================================================

it('activates exactly the requested gateway behind the whole proof, with a capsule to return to', function () {
    $host = mailActivationHost();

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'pass', 'requested' => true, 'changed' => true, 'rolled_back' => false, 'outbound_ready' => true]);
        expect($output)
            ->toContain('PASS the installed gateway is exactly the pre-activation render')
            ->toContain("PASS tits-guru's listener holds its mail, no direct route exists, nothing listens on a public SMTP port")
            ->toContain('PASS public DNS — A, PTR, SPF, DKIM and DMARC — and the key verify for tits-guru')
            ->toContain('PASS the signing acceptance passed: a foreign From refused before it was queued, a valid probe signed, held and removed')
            ->toContain('PASS the HOLD queue is empty')
            ->toContain('OUTBOUND READY: YES')
            ->toContain('PASS mail-identity readiness: every condition PASS — OUTBOUND READY: YES')
            ->toContain("PASS tits-guru's listener alone owns its direct route, capture listeners still capture, nothing listens on a public SMTP port")
            ->toContain('ACTIVATION: DONE');

        // The proof from the pre-activation bundle, then the one change from
        // the trusted bundle, then its own proof.
        $calls = mailActivationCalls($host);
        expect($calls)->toBe([
            'install-mail-gateway --verify [outbound]',
            'install-mail-gateway --verify [held]',
            'install-mail-signing --verify --target tits-guru',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
            // The one-use authorization, bound to the digests the gateway
            // itself reports, then the one change it permits.
            'install-mail-gateway --policy-digest [outbound]',
            'install-mail-gateway --apply [outbound]',
            'install-mail-gateway --verify [outbound]',
            'verify-mail-signing --read-only --target tits-guru [outbound]',
            'install-mail-gateway --verify [outbound]',
            'install-mail-signing --verify --target tits-guru',
        ]);

        // The gateway consumed it: no authorization is left, and the ledger has it.
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toMatch('/^[0-9a-f]{64} [0-9a-f]{32} activate tits-guru /');

        // The host: tits-guru delivers through its own direct transport, and
        // staging still captures.
        expect(mailActivationInstalledMode($host))->toBe('outbound');
        expect(mailActivationPostconf($host, '-P', '127.0.0.1:2526/inet/content_filter'))->toBe("127.0.0.1:2526/inet/content_filter = rateguru-outbound-tits-guru:\n");
        expect(mailActivationPostconf($host, '-P', '127.0.0.1:2525/inet/content_filter'))->toBe("127.0.0.1:2525/inet/content_filter = rateguru-capture-staging-main:[127.0.0.1]:1025\n");
        expect(collect(explode("\n", trim(mailActivationPostconf($host, '-M'))))->filter(fn (string $line): bool => str_ends_with($line, ' smtp'))->map(fn (string $line): string => strtok($line, ' '))->values()->all())
            ->toBe(['rateguru-capture-staging-main', 'rateguru-outbound-tits-guru']);

        // The capsule: root-only, the two pre-activation documents — exactly
        // the committed ones — and a non-secret record.
        $capsule = mailActivationCapsule($host);
        expect(fileperms($capsule) & 0o777)->toBe(0o700);
        expect(collect(File::files($capsule))->map->getFilename()->sort()->values()->all())->toBe(['capsule.json', 'mail-outbound.json', 'mail-routing.json']);
        foreach (File::files($capsule) as $file) {
            expect(fileperms($file->getPathname()) & 0o777)->toBe(0o600);
            expectNoKeyMaterial(File::get($file->getPathname()), $host['key']);
        }
        expect(json_decode(File::get($capsule.'/mail-routing.json'), true))->toBe(json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true));
        expect(json_decode(File::get($capsule.'/mail-outbound.json'), true))->toBe(json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true));

        $record = json_decode(File::get($capsule.'/capsule.json'), true);
        expect(array_keys($record))->toBe(['kind', 'schema_version', 'target', 'state', 'recorded_at', 'pre_activation', 'requested']);
        expect($record)->toMatchArray(['kind' => 'rateguru-mail-outbound-activation', 'schema_version' => 1, 'target' => 'tits-guru', 'state' => 'activated']);
        expect($record['pre_activation']['mail-routing.json'])->toBe(hash_file('sha256', $capsule.'/mail-routing.json'));
        expect($record['requested']['mail-outbound.json'])->toBe(hash_file('sha256', $host['bundle'].'/infrastructure/config/mail-outbound.json'));

        // No key in the output, no temporary bundle left behind, the trusted
        // documents untouched, the queue untouched.
        expectNoKeyMaterial($output, $host['key']);
        expect(glob($host['scratch'].'/tmp/*'))->toBe([]);
        expect(json_decode(File::get($host['bundle'].'/infrastructure/config/mail-routing.json'), true))->toBe(mailActivationRequest()['routing']);
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
    } finally {
        mailActivationCleanup($host);
    }
});

it('is an idempotent no-op once the host is activated and ready, and verifies it read-only', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        @unlink($host['host'].'/calls.log');
        $capsule = File::get(mailActivationCapsule($host).'/capsule.json');

        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('ACTIVATION: ALREADY ACTIVE');
        expect(mailActivationResult($output))->toMatchArray(['status' => 'pass', 'changed' => false, 'rolled_back' => false, 'outbound_ready' => true]);
        expect(array_filter(mailActivationCalls($host), static fn (string $call): bool => str_contains($call, '--apply') || str_contains($call, '--e2e')))->toBe([]);
        expect(File::get(mailActivationCapsule($host).'/capsule.json'))->toBe($capsule);

        [$status, $output] = mailActivationRun($host, ['--verify', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)->toContain('OUTBOUND READY: YES');
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'verify', 'status' => 'pass', 'requested' => true, 'changed' => false, 'rolled_back' => false, 'outbound_ready' => true]);
    } finally {
        mailActivationCleanup($host);
    }
});

it('verifies an activated target as not ready when anything it depends on fails', function () {
    $host = mailActivationHost(['installed' => 'outbound', 'toggles' => ['readonly-signing-fails']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--verify', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain('FAIL mail-identity readiness is not YES')->toContain('OUTBOUND READY: NO');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'verify', 'status' => 'fail', 'outbound_ready' => false]);
    } finally {
        mailActivationCleanup($host);
    }
});

// =============================================================================
// FAILURE AFTER THE GATEWAY CHANGED: BACK TO HELD
// =============================================================================

it('returns the host to held on any failure once the gateway started changing', function (string $toggle, string $problem) {
    $host = mailActivationHost(['toggles' => [$toggle]]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)
            ->toContain($problem)
            ->toContain('returning tits-guru to its pre-activation state from the rollback capsule')
            ->toContain("ROLLED BACK: tits-guru's listener holds its mail again and no direct route exists");
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'apply', 'status' => 'fail', 'requested' => true, 'changed' => true, 'rolled_back' => true, 'outbound_ready' => false]);

        // The pre-activation gateway re-applied through its own installer, then
        // proved: its verify, and the signing acceptance on the held listener.
        $calls = mailActivationCalls($host);
        $applies = array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply')));
        expect($applies)->toBe(['install-mail-gateway --apply [outbound]', 'install-mail-gateway --apply [held]']);
        expect(array_slice($calls, array_search('install-mail-gateway --apply [held]', $calls, true)))->toBe([
            'install-mail-gateway --apply [held]',
            'install-mail-gateway --verify [held]',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
        ]);
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect(File::get($host['fs'].'/etc/postfix/master.cf'))->not->toContain('rateguru-outbound-');

        // The queue: read, never changed — the unrelated entry still there.
        expect(File::get($host['state'].'/queue'))->toBe("FOREIGN0001\tdeferred\tsomeone@example.net\n");
        expect(array_unique(array_filter(explode("\n", File::get($host['log'].'/queue.log')))))->toBe(['postqueue -j']);

        expect(json_decode(File::get(mailActivationCapsule($host).'/capsule.json'), true)['state'])->toBe('rolled-back');
        expect(glob($host['scratch'].'/tmp/*'))->toBe([]);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'the installer refused' => ['gateway-apply-fails-outbound', 'ERROR: install-mail-gateway --apply refused or failed'],
    'the installer failed half-way' => ['gateway-apply-breaks-outbound', 'ERROR: install-mail-gateway --apply refused or failed'],
    'the gateway does not verify' => ['gateway-verify-fails-outbound', 'FAIL the installed gateway is not exactly the requested render'],
    'readiness is not YES' => ['readonly-signing-fails', 'FAIL mail-identity readiness is not YES (exit 1; not PASS: signing)'],
    'a route leaked to staging' => ['leak-direct-route', 'staging-main (127.0.0.1:2525) routes to a direct delivery transport (rateguru-outbound-tits-guru:) — only tits-guru may'],
]);

it('reports CRITICAL, and attempts nothing broader, when the return to held cannot be proved', function () {
    $host = mailActivationHost(['toggles' => ['gateway-verify-fails-outbound', 'gateway-apply-fails-held']]);

    try {
        [$status, $output] = mailActivationRun($host, ['--apply', '--target', 'tits-guru']);

        expect($status)->toBe(2, $output);
        expect($output)
            ->toContain('CRITICAL: the activation of tits-guru failed AND its return to held could not be proved. Nothing broader was attempted.')
            ->toContain('the signing acceptance is not run: the gateway is not proved to hold tits-guru\'s mail');
        expect(mailActivationResult($output))->toMatchArray(['status' => 'critical', 'changed' => true, 'rolled_back' => false, 'outbound_ready' => false]);

        // One attempt at the pre-activation gateway, and no probe into a
        // gateway that is not proved to hold.
        $calls = mailActivationCalls($host);
        expect(array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, '--apply'))))
            ->toBe(['install-mail-gateway --apply [outbound]', 'install-mail-gateway --apply [held]']);
        expect(count(array_filter($calls, static fn (string $call): bool => str_contains($call, '--e2e'))))->toBe(1);
    } finally {
        mailActivationCleanup($host);
    }
});

it('never flushes, releases, requeues or deletes queued mail, and never reads a key', function () {
    $code = executableSourceLines(File::get(base_path('infrastructure/scripts/activate-mail-outbound')));

    foreach (['postsuper', 'POSTSUPER', 'postqueue -f', '" -f', 'postcat', ' -H ', 'sendmail', '/dev/tcp', 'opendkim/keys', '.private"', 'curl', 'wget', 'eval '] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("activate-mail-outbound uses {$forbidden}");
    }

    // The only queue access is the shared library's read of what is held.
    expect($code)->toContain('source "${SCRIPT_DIR}/smtp-submission"');
    expect(substr_count($code, 'queue_ids_in'))->toBe(1);
    expect($code)->toContain('queue_ids_in hold')->not->toContain('queue_state')->not->toContain('queue_ids_for')->not->toContain('smtp_submit');
});

// =============================================================================
// THE INITIAL-LAUNCH ROLLBACK
// =============================================================================

it('returns an activated planned target to held from its capsule, and leaves the committed configuration alone', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        @unlink($host['host'].'/calls.log');
        $documents = [
            File::get($host['bundle'].'/infrastructure/config/mail-routing.json'),
            File::get($host['bundle'].'/infrastructure/config/mail-outbound.json'),
        ];

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(0, $output);
        expect($output)
            ->toContain("RUNTIME HELD AGAIN: tits-guru's listener holds its mail, no direct route exists, and the signing acceptance passed")
            ->toContain('The committed configuration STILL requests outbound delivery for tits-guru: revert the activation change');
        expect(mailActivationResult($output))->toBe(['target' => 'tits-guru', 'mode' => 'rollback', 'status' => 'pass', 'requested' => true, 'changed' => true, 'rolled_back' => true, 'outbound_ready' => false]);

        expect(mailActivationCalls($host))->toBe([
            // The gateway records outbound, so the way back needs its own
            // one-use authorization.
            'install-mail-gateway --policy-digest [held]',
            'install-mail-gateway --policy-digest [held]',
            'install-mail-gateway --apply [held]',
            'install-mail-gateway --verify [held]',
            'verify-mail-signing --e2e --target tits-guru [held]',
            'install-mail-gateway --verify [held]',
        ]);
        expect(file_exists($host['fs'].'/var/lib/rateguru-mail-gateway/transition-authorization.json'))->toBeFalse();
        expect(File::get($host['fs'].'/var/lib/rateguru-mail-gateway/consumed-transition-authorizations'))->toContain(' rollback tits-guru ');
        expect(mailActivationInstalledMode($host))->toBe('held');
        expect([
            File::get($host['bundle'].'/infrastructure/config/mail-routing.json'),
            File::get($host['bundle'].'/infrastructure/config/mail-outbound.json'),
        ])->toBe($documents);
        expect(json_decode(File::get(mailActivationCapsule($host).'/capsule.json'), true)['state'])->toBe('rolled-back');
    } finally {
        mailActivationCleanup($host);
    }
});

it('refuses a rollback without its own, current, untampered capsule', function (string $case, string $problem) {
    $host = mailActivationHost();

    try {
        if ($case !== 'no capsule') {
            mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        }

        $capsule = mailActivationCapsule($host);

        match ($case) {
            'no capsule' => null,
            'a foreign file' => file_put_contents($capsule.'/notes.txt', "mine\n"),
            'another kind' => file_put_contents($capsule.'/capsule.json', json_encode(['kind' => 'something-else', 'target' => 'tits-guru'])),
            'a tampered document' => file_put_contents($capsule.'/mail-outbound.json', File::get($capsule.'/mail-outbound.json').' '),
            'a changed request' => (function () use ($host): void {
                $request = mailActivationRequest();
                $request['routing']['targets']['staging-main']['capture']['port'] = 1026;
                file_put_contents($host['bundle'].'/infrastructure/config/mail-routing.json', mailRoutingJson($request['routing']));
            })(),
        };

        @unlink($host['host'].'/calls.log');
        $before = is_dir($capsule) ? collect(File::files($capsule))->mapWithKeys(fn ($file) => [$file->getFilename() => File::get($file->getPathname())])->all() : [];

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(1, $output);
        expect($output)->toContain($problem)->toContain('the rollback capsule cannot be used — nothing was changed');
        expect(mailActivationCalls($host))->toBe([]);
        expect(is_dir($capsule) ? collect(File::files($capsule))->mapWithKeys(fn ($file) => [$file->getFilename() => File::get($file->getPathname())])->all() : [])->toBe($before);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'no capsule' => ['no capsule', 'there is no rollback capsule for tits-guru'],
    'a foreign file' => ['a foreign file', 'is not an activation capsule of tits-guru — it is never used or removed'],
    'another kind' => ['another kind', 'is not an activation capsule of tits-guru'],
    'a tampered document' => ['a tampered document', "the capsule's pre-activation mail-outbound.json is not the one it recorded"],
    'a changed request' => ['a changed request', 'the configuration changed since the activation, so this capsule is stale'],
]);

it('reports CRITICAL when an explicit rollback cannot be proved', function () {
    $host = mailActivationHost();

    try {
        mailActivationRun($host, ['--apply', '--target', 'tits-guru']);
        touch($host['host'].'/toggles/gateway-apply-fails-held');

        [$status, $output] = mailActivationRun($host, ['--rollback', '--target', 'tits-guru']);

        expect($status)->toBe(2, $output);
        expect($output)->toContain('CRITICAL: the rollback of tits-guru could not be proved. Nothing broader was attempted.');
        expect(mailActivationResult($output))->toMatchArray(['mode' => 'rollback', 'status' => 'critical', 'rolled_back' => false]);
    } finally {
        mailActivationCleanup($host);
    }
});

// =============================================================================
// THE INTERFACE
// =============================================================================

it('takes a mode and a target and nothing else, and runs as root', function (array $arguments, string $problem) {
    $host = mailActivationHost();

    try {
        [$status, $output] = mailActivationRun($host, $arguments, $arguments === ['--apply', '--target', 'tits-guru'] ? ['RATEGURU_MAILACTIVATE_EUID' => '1000'] : []);

        expect($status)->not->toBe(0);
        expect($output)->toContain($problem);
        expect(mailActivationCalls($host))->toBe([]);
    } finally {
        mailActivationCleanup($host);
    }
})->with([
    'no mode' => [['--target', 'tits-guru'], 'a mode is required'],
    'two modes' => [['--apply', '--rollback', '--target', 'tits-guru'], 'mode given more than once'],
    'no target' => [['--apply'], '--apply requires --target'],
    'a routing file' => [['--apply', '--target', 'tits-guru', '--routing', '/tmp/x.json'], 'unknown argument: --routing'],
    'a port' => [['--apply', '--target', 'tits-guru', '--port', '25'], 'unknown argument: --port'],
    'a bad target' => [['--apply', '--target', '../etc'], 'invalid target ID'],
    'not root' => [['--apply', '--target', 'tits-guru'], 'must run as root'],
]);

it('is repository tooling that runs from the trusted bundle, beside the library it shares', function () {
    expect(repositoryOnlyScriptNames())->toContain('activate-mail-outbound')->toContain('send-mail-canary');
    expect(requiredCliManifestNames())->not->toContain('activate-mail-outbound')->not->toContain('send-mail-canary');
    expect(sourcedLibraryNames())->toContain('smtp-submission');

    // Nothing in ordinary preparation, repair or verification runs the
    // activation or sends a canary — they may only name it as the one way
    // across the boundary.
    foreach (['prepare-host', 'install-bootstrap-services', 'repair-target', 'configure-target', 'provision-target', 'verify-infrastructure', 'install-mail-gateway'] as $script) {
        $code = executableSourceLines(File::get(base_path("infrastructure/scripts/{$script}")));
        expect($code)->not->toContain('/activate-mail-outbound')->not->toContain('activate-mail-outbound --')->not->toContain('send-mail-canary');
    }
});

// =============================================================================
// THE WORKFLOWS: ACTIVATE AND ROLL BACK
// =============================================================================

it('runs the activation and its rollback from main only, for tits-guru only, with nothing an operator can choose', function (string $file, string $name, string $job, string $operation) {
    $source = File::get(base_path(".github/workflows/{$file}"));
    $workflow = Yaml::parse($source);

    expect($workflow['name'])->toBe($name);
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'])->toBeNull();
    expect($workflow['permissions'])->toBe(['contents' => 'read']);
    expect($workflow['concurrency'])->toBe(['group' => 'rateguru-staging-deployment', 'cancel-in-progress' => false]);

    $infrastructure = Yaml::parse(File::get(base_path('.github/workflows/verify-production-infrastructure.yml')));
    expect(array_keys($workflow['jobs']))->toBe(['validate-ref', $job]);
    expect($workflow['jobs']['validate-ref'])->toBe($infrastructure['jobs']['validate-ref']);

    $run = $workflow['jobs'][$job];
    expect($run['needs'])->toBe(['validate-ref']);
    expect($run['environment'])->toBe('production-tits-guru');
    expect($run['runs-on'])->toBe('ubuntu-24.04');
    expect($run['steps'][0]['with'])->toBe(['ref' => 'main', 'fetch-depth' => 1, 'persist-credentials' => false]);
    expect($run['steps'][1]['uses'])->toBe('./.github/actions/activate-rateguru-mail-outbound');
    expect($run['steps'][1]['with'])->toBe([
        'operation' => $operation,
        'deployment-target' => 'tits-guru',
        'bootstrap-host' => '${{ vars.DEPLOY_HOST }}',
        'bootstrap-port' => '${{ vars.DEPLOY_PORT }}',
        'bootstrap-user' => '${{ vars.BOOTSTRAP_USER }}',
        'bootstrap-ssh-key' => '${{ secrets.BOOTSTRAP_SSH_KEY }}',
        'bootstrap-known-hosts' => '${{ secrets.BOOTSTRAP_KNOWN_HOSTS }}',
    ]);

    preg_match_all('/secrets\.([A-Z_]+)/', $source, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['BOOTSTRAP_SSH_KEY', 'BOOTSTRAP_KNOWN_HOSTS']);
    expect($source)->not->toContain('MAIL_DKIM_PRIVATE_KEY')->not->toContain('DEPLOY_SSH_KEY')->not->toContain('LARAVEL_ENV');
})->with([
    'activate' => ['activate-tits-guru-mail.yml', 'Activate tits.guru outbound mail', 'activate', 'apply'],
    'rollback' => ['rollback-tits-guru-mail-activation.yml', 'Rollback tits.guru outbound mail activation', 'rollback', 'rollback'],
]);

it('runs exactly activate-mail-outbound in the mode its workflow fixed, judges its result and removes its bundle on every path', function () {
    $source = File::get(base_path('.github/actions/activate-rateguru-mail-outbound/action.yml'));
    $action = Yaml::parse($source);
    $code = executableSourceLines($source);

    expect(array_keys($action['inputs']))->toBe(['operation', 'deployment-target', 'bootstrap-host', 'bootstrap-port', 'bootstrap-user', 'bootstrap-ssh-key', 'bootstrap-known-hosts']);

    // A closed set of operations, refused before anything else.
    expect($code)->toContain("case \"\${OPERATION}\" in\n          apply|rollback) ;;");
    expect($code)->toMatch('#remote_command=\(\s+\$\{RATEGURU_PRIVILEGED_PREFIX:-\}\s+"\$\{RATEGURU_REMOTE_ROOT\}/infrastructure/scripts/activate-mail-outbound"\s+"--\$\{OPERATION\}"\s+--target "\$\{DEPLOYMENT_TARGET\}"\s+\)#');

    foreach (['MAIL_DKIM_PRIVATE_KEY', 'MAIL_CANARY_RECIPIENT', 'mail-routing.json"', 'send-mail-canary', 'prepare-host', 'postsuper', 'postqueue', 'sendmail', '--e2e'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("the activation action uses {$forbidden}");
    }

    expect($code)
        ->toContain("grep -c '^RATEGURU_MAIL_OUTBOUND_ACTIVATION_RESULT='")
        ->toContain('and ([.requested, .changed, .rolled_back, .outbound_ready] | all(type == "boolean"))')
        ->toContain('or ($mode == "apply" and .requested and .outbound_ready and (.rolled_back | not))')
        ->toContain('or ($mode == "rollback" and .rolled_back and (.outbound_ready | not)))')
        ->toContain('| OUTBOUND READY |')
        ->toContain('Activation is not requested by this trusted bundle')
        ->toContain('**This is the pre-go-live initial activation rollback.**')
        ->toContain('Revert the activation change to `mail-routing.json` and `mail-outbound.json` on main before the next Prepare or Verify');

    $last = array_slice($action['runs']['steps'], -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }
});

// =============================================================================
// THE RECORD: IMPLEMENTED, NOT ACTIVATED
// =============================================================================

it('records the signing foundation as accepted and the activation as implemented but not yet performed', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4B.4.2a DKIM signing foundation — PRODUCTION-ACCEPTED.**')
        ->toContain('Verify production infrastructure run `37634818870` PASS')
        ->toContain('Verify production mail signing run `37639732203` PASS')
        ->toContain('**8.4B.4.2b Guarded outbound activation and the first real delivery — IMPLEMENTED — production activation pending.**')
        ->toContain('*The actual activation is a later, explicit operator cutover:*')
        ->toContain('Not accepted until a real canary has been received and its headers inspected')
        // The application's mail transport moved to before the first deploy.
        ->toContain('the production application\'s mail transport (`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=2526`, `MAIL_FROM_ADDRESS=noreply@tits.guru`, no SMTP credentials) is set before the first production deploy in 8.6, not here')
        ->toContain('Before the first production deploy, a separately reviewed operation sets and verifies the application\'s mail transport from the reviewed mail routing plan')
        // The recovery requirements stay where they belong.
        ->toContain('production backup and recovery must carry the active DKIM signing private key')
        ->toContain('a recovery-time outbound fence')
        ->toContain('A, PTR and SPF re-accepted for the replacement host\'s address before mail resumes')
        ->toContain('a host-scoped mail topology once staging and production run on separate machines');

    $runbook = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/runbooks/mail-outbound-activation.md')));

    expect($runbook)
        ->toContain('Merging this tooling activates nothing.')
        ->toContain('*activation is not requested by this trusted bundle*')
        ->toContain('A separate, tiny **activation pull request directly against `main`** changes exactly two files and nothing else')
        ->toContain('the same change in `develop` would let an ordinary **Prepare staging host** change the real production gateway before the controlled cutover')
        ->toContain('A **Prepare production host** in between cannot activate anything: the gateway refuses held → outbound without the activation\'s authorization, and the host stays held.')
        ->toContain('A **Prepare staging host** in that window cannot deactivate production mail: the gateway refuses outbound → held without a rollback authorization, and production keeps delivering.')
        ->toContain('Prepare converges a state but cannot cross that boundary, and a Prepare from a stale branch fails closed instead of activating or deactivating mail')
        ->toContain('so production mail no longer carries `mail-gateway.rateguru.invalid` in its `Received` hop')
        ->toContain('must be reverted on `main` before the next Prepare or Verify')
        ->toContain('Only after that acceptance, synchronize `main` → `develop`')
        ->toContain('It is the local Postfix\'s record that the **remote MX accepted** the message. It is not SPF, DKIM or DMARC acceptance at the receiver')
        ->toContain('| Sending source IP | `213.199.41.241` |')
        ->toContain('| Sending MTA / HELO | `mta1.tits.guru` |')
        ->toContain('| PTR of the source IP | `mta1.tits.guru` |')
        ->toContain('`MAIL_PORT=` the target\'s reviewed submission port (`2526` for `tits-guru`)')
        ->toContain('the production `shared/.env` (`/home/www/rateguru/production/tits-guru/shared/.env`), GitHub `LARAVEL_ENV`, and the production environment template defaults');

    // The rollout, in order.
    $steps = [
        'Merge the tooling pull request into `develop`.',
        'Run **Prepare staging host**.',
        'Run **Verify staging infrastructure**.',
        'Promote `develop` → `main`.',
        'Run **Verify production infrastructure**.',
        'Run **Verify production mail signing**.',
        'run **Activate tits.guru outbound mail**.',
        'Add `MAIL_CANARY_RECIPIENT` to the `production-tits-guru` GitHub Environment.',
        'Run **Send tits.guru production mail canary**.',
        'Inspect the received message\'s raw headers against the table above.',
        'synchronize `main` → `develop`',
    ];
    $position = -1;
    foreach ($steps as $step) {
        $next = strpos($runbook, $step, $position + 1);
        expect($next)->not->toBeFalse("the rollout does not say: {$step}");
        expect($next)->toBeGreaterThan($position, "the rollout says \"{$step}\" out of order");
        $position = $next;
    }
});
