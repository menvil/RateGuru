<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * activate-mail-outbound: the request it acts on and every check of it, its
 * command line, and the workflows that run it. What it proves, applies,
 * verifies and undoes is in the MailOutboundActivation*Test files beside this
 * one.
 *
 * Every run here uses the REAL script from a bundle of its own
 * (mailActivationHost() in tests/Pest.php): the real mail-routing and
 * mail-identity judge the documents — readiness and verify-dns against a real
 * key and stubbed public DNS — and the owners of host state are stubs that read
 * the documents of the bundle they sit in, so the pre-activation bundle the
 * script derives is judged by exactly what it holds.
 */

/**
 * transition_problems, sourced from the shipped script, for the pre-activation
 * documents against the committed request changed by CHANGE.
 */
function mailActivationTransitionProblems(string $change): string
{
    $scratch = makeScratchDir('mail-activation-transition');
    $request = mailActivationRequest();
    $pre = mailPreActivationPolicy();

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

// =============================================================================
// THE COMMITTED REQUEST: TITS-GURU OUTBOUND, NOTHING ELSE MOVED
// =============================================================================

it('requests direct outbound delivery for tits-guru in the committed configuration, and moves nothing else', function () {
    $routing = json_decode(File::get(base_path('infrastructure/config/mail-routing.json')), true);

    // tits-guru: outbound by direct delivery, its identity and endpoint as
    // they were.
    expect($routing['targets']['tits-guru'])->toBe([
        'submission' => ['host' => '127.0.0.1', 'port' => 2526],
        'delivery_mode' => 'outbound',
        'outbound' => ['kind' => 'direct'],
        'mail_domain' => 'tits.guru',
        'default_from' => 'noreply@tits.guru',
        'bounce_domain' => 'bounce.tx.tits.guru',
        'reply_domain' => 'reply.tits.guru',
    ]);

    // Staging still captures into its own Mailpit.
    expect($routing['targets']['staging-main'])->toBe([
        'submission' => ['host' => '127.0.0.1', 'port' => 2525],
        'delivery_mode' => 'capture',
        'allowed_from_domain' => 'staging.invalid',
        'capture' => ['host' => '127.0.0.1', 'port' => 1025],
    ]);
    expect($routing['schema_version'])->toBe(2);

    // The host contract allows direct delivery under the reviewed name.
    $outbound = json_decode(File::get(base_path('infrastructure/config/mail-outbound.json')), true);
    expect($outbound)->toBe(['schema_version' => 1, 'direct' => ['enabled' => true, 'mta_hostname' => 'mta1.tits.guru']]);

    // The target is still planned: requesting outbound mail is not a launch.
    $registry = collect(json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'])
        ->keyBy('id');
    expect($registry['tits-guru']['lifecycle'])->toBe('planned');

    // No production application mail transport is set: the templates still
    // leave it to the operation before the first deploy.
    foreach (['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS'] as $key) {
        expect(envFileValues('infrastructure/templates/environment/tits-guru.env.example')[$key])->toBe('');
    }
    expect(envFileValues('infrastructure/templates/environment/production.env.example')['MAIL_MAILER'])->toBe('');

    // And it is exactly the activation request every activation test runs.
    expect(mailActivationRequest())->toBe(['routing' => $routing, 'outbound' => $outbound]);
});

it('refuses every mode from a bundle that does not request the activation, before it changes anything', function (string $mode) {
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

it('derives the pre-activation documents from the committed request by undoing exactly the three changes', function () {
    $source = File::get(base_path('infrastructure/scripts/activate-mail-outbound'));

    // The derivation is these two programs and nothing else; the
    // pre-activation fixture is what they give back from the committed request.
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

    expect(json_decode($routing, true))->toBe(mailPreActivationPolicy()['routing']);
    expect(json_decode($outbound, true))->toBe(mailPreActivationPolicy()['outbound']);

    // And the fixture differs from the committed request in exactly those
    // three places: tits-guru held, no route, direct delivery disabled.
    $pre = mailPreActivationPolicy();
    $committed = mailCommittedPolicy();
    expect($pre['routing']['targets']['tits-guru'])->toBe([
        'submission' => ['host' => '127.0.0.1', 'port' => 2526],
        'delivery_mode' => 'held',
        'mail_domain' => 'tits.guru',
        'default_from' => 'noreply@tits.guru',
        'bounce_domain' => 'bounce.tx.tits.guru',
        'reply_domain' => 'reply.tits.guru',
    ]);
    expect(Arr::except($pre['routing'], ['targets.tits-guru']))->toBe(Arr::except($committed['routing'], ['targets.tits-guru']));
    expect(Arr::except($pre['routing']['targets']['tits-guru'], ['delivery_mode']))
        ->toBe(Arr::except($committed['routing']['targets']['tits-guru'], ['delivery_mode', 'outbound']));
    expect($pre['outbound'])->toBe(['schema_version' => 1, 'direct' => ['enabled' => false, 'mta_hostname' => 'mta1.tits.guru']]);
    expect(Arr::except($pre['outbound'], ['direct.enabled']))->toBe(Arr::except($committed['outbound'], ['direct.enabled']));
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

// =============================================================================
// THE PRE-ACTIVATION PROOF: NOTHING CHANGES UNTIL ALL OF IT HOLDS
// =============================================================================

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
        ->toContain('Revert the activation change to `mail-routing.json` and `mail-outbound.json` with a pull request into develop, promoted to main, before the next Prepare or Verify: until then Verify reports the difference, and Prepare refuses to cross back to outbound — that takes another guarded activation.');

    // Prepare never crosses the boundary, so nothing tells an operator it would.
    foreach (['.github/actions/activate-rateguru-mail-outbound/action.yml', '.github/workflows/rollback-tits-guru-mail-activation.yml', 'infrastructure/scripts/activate-mail-outbound'] as $path) {
        $text = preg_replace('/\s+/', ' ', File::get(base_path($path)));
        expect($text)
            ->not->toContain('Prepare will activate')
            ->not->toContain('Prepare will render the activation')
            ->not->toContain('Prepare would otherwise render')
            ->not->toContain('Prepare host renders the gateway it describes');
    }

    $rollback = preg_replace('/\s+/', ' ', preg_replace('/^#\s?/m', '', File::get(base_path('.github/workflows/rollback-tits-guru-mail-activation.yml'))));
    expect($rollback)->toContain('Until it is, Verify reports the difference and Prepare refuses to cross back to outbound; only another guarded activation does.');

    $last = array_slice($action['runs']['steps'], -2);
    expect(array_column($last, 'name'))->toBe(['Remove the remote infrastructure bundle', 'Remove temporary local files']);
    foreach ($last as $step) {
        expect($step['if'])->toBe('${{ always() }}');
    }
});

// =============================================================================
// THE RECORD: IMPLEMENTED, NOT ACTIVATED
// =============================================================================

it('records the signing foundation as accepted, and the activation the policy requested as performed on the host and production-accepted', function () {
    $roadmap = preg_replace('/\s+/', ' ', File::get(base_path('infrastructure/ROADMAP.md')));

    expect($roadmap)
        ->toContain('**8.4B.4.2a DKIM signing foundation — PRODUCTION-ACCEPTED.**')
        ->toContain('Verify production infrastructure run `37634818870` PASS')
        ->toContain('Verify production mail signing run `37639732203` PASS')
        ->toContain('**8.4B.4.2b Guarded outbound activation and the first real delivery — IMPLEMENTED and PRODUCTION-ACCEPTED 2026-10-08.**')
        ->toContain('*The activation was a separate, explicit operator cutover,* performed on 2026-10-08 and recorded in 8.4B.4.2 below:')
        // The activation change takes the ordinary path: develop, CI, promotion.
        ->toContain('a separate two-file activation pull request into `develop`')
        ->toContain('reaches `main` by the ordinary promotion — never a pull request directly into `main`, and no synchronization back from `main` into `develop`')
        ->toContain('Between its merge into `develop` and the activation, Prepare and Verify staging do not run')
        ->toContain('the canary, the operator\'s inspection of the received message\'s raw headers (SPF, DKIM and DMARC PASS, `d=tits.guru s=rg1`, from `213.199.41.241` as `mta1.tits.guru`), then Verify staging infrastructure')
        ->not->toContain('directly against `main`')
        ->toContain('Accepted only once a real canary had been received and its headers inspected')
        ->not->toContain('Not accepted until a real canary has been received')
        // The tooling's acceptance on the shared host, with its runs.
        ->toContain('*Tooling accepted on the shared host on 2026-10-08,* with `tits-guru` still held and direct delivery disabled: Prepare staging host run `37779425683` and Verify staging infrastructure run `37780411751` (`develop` `92252559`), Verify production infrastructure run `37780729123` and Verify production mail signing run `37781550331` (`main` `771268e2`), all PASS')
        // The policy change: requested in the repository, not on the host.
        ->toContain('**8.4B.4.2c Production outbound activation policy — IMPLEMENTED, activated on the host and PRODUCTION-ACCEPTED 2026-10-08.**')
        ->toContain('*Code and policy:* once promoted to `main`, the repository requests outbound delivery. *The real server:* held until **Activate tits.guru outbound mail** runs, whatever the repository requests')
        ->toContain('a committed outbound policy is never `OUTBOUND READY: YES` on a held host')
        ->toContain('*Production accepted:* only after Activate, Verify production infrastructure (`OUTBOUND READY: YES`), and a real canary received with its raw headers inspected.')
        ->toContain('Nothing was activated or sent by this change; the three states have agreed since 2026-10-08, when the host was activated and the delivery accepted')
        // The acceptance itself, from the real runs.
        ->toContain('**8.4B.4.2 Production outbound activation — PRODUCTION-ACCEPTED 2026-10-08.**')
        ->toContain('The repeated canary to Gmail, run `37821815403` SUCCESS, arrived as `From: TitsGuru <noreply@tits.guru>` with SPF PASS, DKIM PASS (`d=tits.guru`, `s=rg1`) and DMARC PASS, over TLS 1.3, from `213.199.41.241` as `mta1.tits.guru`')
        ->not->toContain('host activation pending')
        ->not->toContain('production activation pending')
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
        // Three states, never confused.
        ->toContain('The committed configuration now **requests** `tits-guru`\'s direct outbound delivery. Requesting it changes no host')
        ->toContain('1. **Code and policy** — what the repository requests.')
        ->toContain('2. **The real server** — what the host applies and records. It stays held until Activate runs, whatever the repository requests.')
        ->toContain('3. **Production accepted** — recorded only after Activate, **Verify production infrastructure** reporting `OUTBOUND READY: YES`, and a real canary received and its raw headers inspected.')
        ->toContain('| `tits-guru` mail — **committed policy** | **Outbound requested**')
        ->toContain('| `tits-guru` mail — **real host** | **Outbound**, activated on 2026-10-08 by **Activate tits.guru outbound mail** (run `37813433328`)')
        ->toContain('| Production acceptance | **Accepted** on 2026-10-08')
        ->toContain('| Run **Send tits.guru production mail canary** to Gmail again. | `37821815403` | SUCCESS |')
        ->not->toContain('production activation **pending**')
        ->toContain('*activation is not requested by this trusted bundle*')
        ->toContain('The activation change is an ordinary pull request **into `develop`**.')
        ->toContain('reaches `main` by the ordinary promotion. A pull request directly into `main`, and a synchronization back from `main` into `develop`, are not part of this rollout.')
        ->toContain('**From here until step 11, run neither Prepare staging host nor Verify staging infrastructure.**')
        ->toContain('It must refuse held → outbound without the activation\'s one-use authorization, and it does: the gateway fails closed and the host stays held.')
        ->toContain('2. Return the policy to held with a separate pull request into `develop` — reverting the activation change — through CI, promoted to `main`.')
        ->toContain('3. Only once the committed policy and the host\'s applied policy match again run ordinary Verify and Prepare.')
        ->not->toContain('Merging this tooling activates nothing.')
        ->not->toContain('directly against `main`')
        ->not->toContain('synchronize `main` → `develop`')
        ->toContain('Prepare converges a state but cannot cross that boundary, and a Prepare from a stale branch fails closed instead of activating or deactivating mail')
        ->toContain('so production mail no longer carries `mail-gateway.rateguru.invalid` in its `Received` hop')
        ->toContain('must be reverted — a pull request into `develop`, promoted to `main` — before the next Prepare or Verify')
        ->toContain('It is the local Postfix\'s record that the **remote MX accepted** the message. It is not SPF, DKIM or DMARC acceptance at the receiver')
        ->toContain('| Sending source IP | `213.199.41.241` |')
        ->toContain('| Sending MTA / HELO | `mta1.tits.guru` |')
        ->toContain('| PTR of the source IP | `mta1.tits.guru` |')
        ->toContain('`MAIL_PORT=` the target\'s reviewed submission port (`2526` for `tits-guru`)')
        ->toContain('the production `shared/.env` (`/home/www/rateguru/production/tits-guru/shared/.env`), GitHub `LARAVEL_ENV`, and the production environment template defaults');

    // The rollout, in order: the tooling's, done, then the activation's.
    $steps = [
        '| Merge the tooling pull request into `develop`. | — | merged |',
        '| Run **Prepare staging host** (`develop` `92252559`). | `37779425683` | PASS |',
        '| Run **Verify staging infrastructure** (`develop` `92252559`). | `37780411751` | PASS |',
        '| Promote `develop` → `main`. | — | promoted |',
        '| Run **Verify production infrastructure** (`main` `771268e2`). | `37780729123` | PASS |',
        '| Run **Verify production mail signing** (`main` `771268e2`). | `37781550331` | PASS |',
        '1. The activation pull request into `develop`: the new policy, the tests and the documents.',
        '2. The complete CI passes.',
        '3. Merge it into `develop`.',
        '4. **From here until step 11, run neither Prepare staging host nor Verify staging infrastructure.**',
        '5. Promote `develop` → `main` the ordinary way.',
        '6. Run **Activate tits.guru outbound mail** by hand, from `main`.',
        '7. Run **Verify production infrastructure**. It must report full outbound readiness — `OUTBOUND READY: YES`',
        '8. Add `MAIL_CANARY_RECIPIENT` to the `production-tits-guru` GitHub Environment.',
        '9. Run **Send tits.guru production mail canary**',
        '10. Inspect the real received message and its raw headers against the table above.',
        '11. Run **Verify staging infrastructure**.',
        '12. Only then record the activation and the first delivery as production-accepted, from those actual results.',
        '1. Run **Rollback tits.guru outbound mail activation**. The runtime returns to held',
    ];
    $position = -1;
    foreach ($steps as $step) {
        $next = strpos($runbook, $step, $position + 1);
        expect($next)->not->toBeFalse("the rollout does not say: {$step}");
        expect($next)->toBeGreaterThan($position, "the rollout says \"{$step}\" out of order");
        $position = $next;
    }
});
