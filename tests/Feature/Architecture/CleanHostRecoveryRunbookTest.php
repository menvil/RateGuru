<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The clean-host recovery runbook is the one document an operator needs, and
 * it must describe the operation exactly as it exists: the workflow's real
 * inputs, the GitHub values it really reads, the real hostname and names from
 * the registry, and the real final contract. Everything asserted here is
 * derived from the source of truth the runbook documents, never restated by
 * hand — so the runbook cannot drift from the code without a test saying so.
 */
function cleanHostRunbook(): string
{
    return File::get(base_path('infrastructure/runbooks/clean-host-recovery.md'));
}

/** The compact guide the preflight prints from a real checkout. */
function cleanHostOperatorGuide(): string
{
    [$exit, $output] = runInfraScript(
        base_path('infrastructure/scripts/recovery-host-preflight'),
        ['--operator-guide', '--target', 'staging-main'],
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp'],
    );

    expect($exit)->toBe(0, $output);

    return $output;
}

function stagingRegistryTarget(): array
{
    return json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets']['staging-main'];
}

it('is self-contained — every section an operator needs, in order — and every recovery surface points at it', function () {
    // Any lettered section matches, so a section appended past the end is
    // caught by the sequence below rather than quietly skipped by the pattern.
    preg_match_all('/^## ([A-Z])\. (.+)$/m', cleanHostRunbook(), $headings);

    expect($headings[1])->toBe(range('A', 'M'));
    expect($headings[2])->toBe([
        'What to prepare on the new VPS',
        'Prove bootstrap access from your own machine',
        'Record the SSH host key, verified out of band',
        'GitHub Environment values',
        'Which backup qualifies',
        'Run it',
        'What the workflow does by itself',
        'Verify the recovered host',
        'Check the site without changing DNS',
        'What counts as success',
        'When a run stops half way',
        'Never',
        'What a prepared, never-deployed host looks like',
    ]);

    foreach ([
        'infrastructure/README.md',
        'infrastructure/ROADMAP.md',
        'infrastructure/runbooks/github-recover.md',
        'infrastructure/runbooks/recover-host.md',
        'infrastructure/runbooks/prepare-host.md',
    ] as $document) {
        expect(File::get(base_path($document)))->toContain('clean-host-recovery.md');
    }

    // The three scripts an operator meets name the runbook in their --help,
    // and none of them duplicates it.
    foreach (['recover-host', 'prepare-host', 'recovery-host-preflight'] as $script) {
        expect(shellFunctionBody(File::get(base_path('infrastructure/scripts/'.$script)), 'usage'))
            ->toContain('infrastructure/runbooks/clean-host-recovery.md');
    }

    // One refusal format and one runbook pointer, wherever a recovery surface
    // refuses: the shared helper in common, the two clean-host scripts, the
    // preflight action, and the report both workflows end with.
    foreach ([
        'infrastructure/scripts/common',
        'infrastructure/scripts/fetch-recovery-material',
        'infrastructure/scripts/recovery-host-preflight',
        '.github/actions/recovery-host-preflight/action.yml',
        '.github/workflows/recover-staging.yml',
        '.github/workflows/recover-production.yml',
    ] as $path) {
        expect(File::get(base_path($path)))
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Runbook: ')
            ->toContain('infrastructure/runbooks/clean-host-recovery.md');
    }

    // The bundle scripts refuse through the one helper; they define no second
    // format of their own.
    expect(File::get(base_path('infrastructure/scripts/recover-host')))
        ->toContain('operator_action_required \\')
        ->not->toContain('action_required() {');
});

it('documents exactly the inputs the recovery workflow is dispatched with', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/recover-staging.yml'));

    expect($workflow['name'])->toBe('Recover staging host');

    $inputs = (array) data_get($workflow, 'on.workflow_dispatch.inputs');

    expect(array_keys($inputs))->toBe(['mode', 'backup', 'operation', 'replacement-host', 'replacement-port']);
    expect(data_get($inputs, 'mode.options'))->toBe(['start', 'continue-held']);
    expect((string) data_get($inputs, 'replacement-port.default'))->toBe('22');

    $runbook = cleanHostRunbook();

    expect($runbook)
        ->toContain('Actions → **Recover staging host** → Run workflow')
        ->toContain('mode             = start')
        ->toContain('backup           = YYYYMMDD-HHMMSS')
        ->toContain('replacement-host = <IP>')
        ->toContain('replacement-port = 22')
        ->toContain('mode             = continue-held')
        ->toContain('operation        = <the operation ID from the summary>');

    // Every input the workflow has is named, and no input it lacks.
    preg_match_all('/^\s*([a-z-]+)\s+= /m', $runbook, $named);
    $named = array_values(array_unique($named[1]));

    expect($named)->toEqualCanonicalizing(array_keys($inputs));

    expect(cleanHostOperatorGuide())
        ->toContain('mode=start   backup=YYYYMMDD-HHMMSS   replacement-host=<IP>   replacement-port=22')
        ->toContain('mode=continue-held');
});

it('documents exactly the GitHub Environment values the recovery workflow reads, and no PREPARE_* value', function () {
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

    foreach (['runbook' => cleanHostRunbook(), 'operator guide' => cleanHostOperatorGuide()] as $label => $document) {
        preg_match_all('/\b((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', $document, $named);
        $named = array_values(array_unique($named[1]));
        sort($named);

        expect($named)->toBe($read, "the {$label} must name exactly the values the workflow reads");
        expect($document)->not->toMatch('/PREPARE_[A-Z]/');
    }

    expect(cleanHostRunbook())
        ->toContain('No `PREPARE_*` value is read by a recovery')
        ->toContain('`DEPLOY_HOST` is not changed')
        ->toContain('DNS is not changed');

    expect(cleanHostOperatorGuide())
        ->toContain('NO PREPARE_* value is read by a recovery. Do NOT change DEPLOY_HOST. Do NOT change DNS.');

    // And the workflows' executable lines read no PREPARE_* value either:
    // the documentation describes the code, it does not promise it.
    foreach (['recover-staging.yml', 'recover-production.yml'] as $workflow) {
        expect(executableSourceLines(File::get(base_path('.github/workflows/'.$workflow))))->not->toContain('PREPARE_');
    }
});

it('names the real target: the hostname, the paths and the service names come from the registry', function () {
    $target = stagingRegistryTarget();

    expect($target['public_hostnames'])->toBe(['rateguru.staging.myprojects.pp.ua']);

    $hostname = $target['public_hostnames'][0];
    $runbook = cleanHostRunbook();

    expect($runbook)
        ->toContain("The public hostname of `staging-main` is `{$hostname}`")
        ->toContain("curl --resolve {$hostname}:443:<IP> -fsS https://{$hostname}/up")
        ->toContain("<IP>  {$hostname}")
        ->toContain($target['application_root'].'/current')
        ->toContain($target['application_root'].'/previous')
        ->toContain($target['application_root'].'/shared/storage/app')
        ->toContain("supervisorctl status {$target['supervisor']['program']}:*")
        ->toContain("/etc/cron.d/{$target['scheduler']['name']}")
        ->toContain("-H 'Host: {$target['health']['host_header']}' http://127.0.0.1/up")
        ->toContain("rateguru-b2:rateguru-database-backups/rateguru/{$target['backup']['namespace']}/");

    expect(cleanHostOperatorGuide())
        ->toContain("curl --resolve {$hostname}:443:<IP> https://{$hostname}/up");

    // The remote, the bucket and the schema the runbook states are the ones
    // fetch-recovery-material actually reads.
    expect(File::get(base_path('infrastructure/scripts/fetch-recovery-material')))
        ->toContain('RATEGURU_RCLONE_REMOTE rateguru-b2)')
        ->toContain('RATEGURU_RCLONE_BUCKET rateguru-database-backups)')
        ->toContain('REQUIRED_MANIFEST_SCHEMA=3');

    expect($runbook)
        ->toContain('**Schema 3**')
        ->toContain('`YYYYMMDD-HHMMSS`')
        ->toContain('`recovery-material.tar.gz`')
        ->toContain('full 40-character')
        ->toContain('passed the offsite restore test');
});

it('states the final contract the run summary reports', function () {
    $runbook = cleanHostRunbook();
    $report = (string) data_get(Yaml::parseFile(base_path('.github/workflows/recover-staging.yml')), 'jobs.report.steps.0.run');

    foreach (['OFFSITE WRITES: HELD', 'DEPLOY_HOST', 'DNS', 'source_sha'] as $fact) {
        expect($runbook)->toContain($fact);
        expect($report)->toContain($fact);
    }

    expect($runbook)
        ->toContain('No migration ran')
        ->toContain('`previous` is absent')
        ->toContain('`OFFSITE WRITES: HELD` on the recovered machine')
        ->toContain('`DEPLOY_HOST` unchanged, DNS unchanged');

    expect($report)
        ->toContain('| Migrations run | none |')
        ->toContain('| DNS | unchanged |')
        ->toContain('| DEPLOY_HOST | unchanged |')
        ->toContain('OFFSITE WRITES: HELD');

    // When a run stops half way, the runbook's advice is the workflow's own
    // START/CONTINUE recommendation, and it names both safe stages.
    expect($runbook)
        ->toContain('`awaiting-code`')
        ->toContain('`ready-to-resume`')
        ->toContain('RECOVERY ACTION REQUIRED');

    expect($report)
        ->toContain('recommendation="CONTINUE"')
        ->toContain('recommendation="START"');
});

it('separates Environment variables from Environment secrets exactly as the workflow reads them, and never offers the offsite credential as a variable', function () {
    ['vars' => $vars, 'secrets' => $secrets] = recoveryValuesRead('recover-staging.yml');

    expect($vars)->toBe(['DEPLOY_HOST', 'DEPLOY_INCOMING', 'DEPLOY_ROOT', 'DEPLOY_USER', 'DEPLOY_WRAPPER', 'RECOVERY_BOOTSTRAP_USER']);
    expect($secrets)->toBe(['DEPLOY_KNOWN_HOSTS', 'DEPLOY_SSH_KEY', 'RECOVERY_BOOTSTRAP_SSH_KEY', 'RECOVERY_KNOWN_HOSTS', 'RECOVERY_RCLONE_CONFIG']);
    expect(array_intersect($vars, $secrets))->toBe([], 'no value is read both ways');

    // The runbook's section D: a variables table and a secrets table, each
    // naming exactly the values the workflow reads that way.
    $runbook = cleanHostRunbook();

    // Each boundary is asserted before it is used: a false offset would slice
    // a misleading section out of the document and assert against that.
    $offset = static function (string $marker, string $haystack): int {
        $at = strpos($haystack, $marker);

        expect($at)->not->toBeFalse("the runbook has no \"{$marker}\" to read the value tables from");

        return (int) $at;
    };

    $sectionD = substr($runbook, $offset('## D. ', $runbook), $offset('## E. ', $runbook) - $offset('## D. ', $runbook));
    $variablesTable = substr($sectionD, $offset('**Environment variables**', $sectionD), $offset('**Environment secrets**', $sectionD) - $offset('**Environment variables**', $sectionD));
    $secretsTable = substr($sectionD, $offset('**Environment secrets**', $sectionD), $offset('The workflow proves all of them present', $sectionD) - $offset('**Environment secrets**', $sectionD));

    foreach ($vars as $name) {
        expect($variablesTable)->toContain("`{$name}`");
        expect(str_contains($secretsTable, "`{$name}`"))->toBeFalse("{$name} is a variable and must not be listed as a secret");
    }

    foreach ($secrets as $name) {
        expect($secretsTable)->toContain("`{$name}`");
        expect(str_contains($variablesTable, "`{$name}`"))->toBeFalse("{$name} is a secret and must not be listed as a variable");
    }

    expect($variablesTable)->toContain('Settings → Environments → staging → Environment variables');
    expect($secretsTable)
        ->toContain('Settings → Environments → staging → Environment secrets')
        ->toContain('A secret, never a variable');

    // The one refusal that happened for real is spelled out, verbatim to the
    // workflow's own guidance.
    expect($sectionD)
        ->toContain('Cause: the staging GitHub Environment has no RECOVERY_RCLONE_CONFIG secret')
        ->toContain('1. Settings -> Environments -> staging -> Environment secrets')
        ->toContain('2. create RECOVERY_RCLONE_CONFIG')
        ->toContain('3. paste the complete contents of the recovery rclone configuration file (rclone.conf)')
        ->toContain('4. do not create it as an Environment variable')
        ->toContain('Then: re-run "Recover staging host" with mode=start and the same exact backup');

    // The compact guide draws the same line.
    $guide = cleanHostOperatorGuide();
    $guideVariables = substr($guide, $offset('Environment variables (Settings', $guide), $offset('Environment secrets (Settings', $guide) - $offset('Environment variables (Settings', $guide));
    $guideSecrets = substr($guide, $offset('Environment secrets (Settings', $guide), $offset('NO PREPARE_*', $guide) - $offset('Environment secrets (Settings', $guide));

    foreach ($vars as $name) {
        expect($guideVariables)->toContain($name);
        expect(str_contains($guideSecrets, $name))->toBeFalse("{$name} is a variable and must not be listed as a secret in the guide");
    }

    foreach ($secrets as $name) {
        expect($guideSecrets)->toContain($name);
        expect(str_contains($guideVariables, $name))->toBeFalse("{$name} is a secret and must not be listed as a variable in the guide");
    }

    expect($guideSecrets)->toContain('a secret, never a variable');

    // Nowhere does anything read the offsite credential as a variable.
    foreach (glob(base_path('.github/workflows/*.yml')) as $path) {
        expect(str_contains(File::get($path), 'vars.RECOVERY_RCLONE_CONFIG'))->toBeFalse(basename($path).' reads RECOVERY_RCLONE_CONFIG as a variable');
    }

    // The actions take the credential as an input and read no environment
    // of their own; naming the value in a refusal is exactly what they do.
    foreach (glob(base_path('.github/actions/*/action.yml')) as $path) {
        $action = File::get($path);

        expect(str_contains($action, 'vars.RECOVERY_RCLONE_CONFIG') || str_contains($action, 'secrets.RECOVERY_RCLONE_CONFIG'))
            ->toBeFalse(basename(dirname($path)).' must take the credential as an input, never read the environment');
    }
});

it('describes the PRE_DEPLOY state the recovery requires exactly as recover-host judges it', function () {
    $runbook = cleanHostRunbook();
    $section = substr($runbook, strpos($runbook, '## M. '));
    $recover = File::get(base_path('infrastructure/scripts/recover-host'));

    // Every line of the contract an operator is told to expect.
    foreach ([
        '| `current` | **absent** |',
        '| `previous` | **absent** |',
        '| `releases/` | **empty** |',
        '**0 tables** in the `public` schema',
        '| `shared/storage/app` | **absent**, or present and empty',
        '| `shared/.env` | **present**',
        '| queue program configuration | **installed and valid**',
        '| Supervisor runtime group | **may be absent**',
        '| queue worker | definitely **not RUNNING** |',
        '| offsite-write hold | **present** |',
        '| restore guard, recovery guard | **absent** |',
    ] as $row) {
        expect($section)->toContain($row);
    }

    // And the reason it may be absent, stated the way the primitive states
    // it. Flattened, because these sentences wrap and a rewrap is not a change
    // in what they say.
    expect(preg_replace('/\s+/', ' ', $section))
        ->toContain('ERROR (no such group)')
        ->toContain('exit status 4')
        ->toContain('the program configuration installed on the machine')
        ->toContain('Restore Target Data still fails closed on it');

    expect($recover)
        ->toContain('recovery_queue_group_not_loaded')
        ->toContain('RECOVERY_SUPERVISOR_RC_UNKNOWN_NAME=4')
        ->toContain('ERROR (no such group)');
});

it('sends an operator diagnosing a refused precondition to the trusted bundle, never to a command that cannot work', function () {
    $runbook = cleanHostRunbook();

    expect($runbook)
        ->toContain('does not satisfy the prepared/EMPTY recovery contract')
        ->toContain('sudo <checkout>/infrastructure/scripts/recover-host \\')
        ->toContain('--check --target staging-main --backup YYYYMMDD-HHMMSS')
        ->toContain('**Delete nothing until the failed precondition is known.**');

    expect(preg_replace('/\s+/', ' ', $runbook))
        ->toContain('the installed operational bundle carries no `prepare-host`');

    // The one command the documentation must never OFFER: the installed copy
    // has no prepare-host beside it and refuses --check for that reason. It is
    // named once, as the thing not to run.
    expect($runbook)->toContain('Not `/home/www/rateguru/bin/recover-host --check`');

    preg_match_all('/```(?:bash|text)?\n(.*?)```/s', $runbook, $blocks);

    expect($blocks[1])->not->toBeEmpty();

    foreach ($blocks[1] as $block) {
        expect(str_contains($block, '/home/www/rateguru/bin/recover-host --check'))
            ->toBeFalse('the runbook must never give an operator a command that cannot work');
    }

    foreach (['infrastructure/runbooks/github-recover.md', 'infrastructure/runbooks/recover-host.md'] as $document) {
        expect(str_contains(File::get(base_path($document)), '/home/www/rateguru/bin/recover-host --check'))
            ->toBeFalse("{$document} must not offer the installed --check either");
    }

    // The installed copy still answers the modes that need no bootstrap
    // tooling, and the runbook keeps using it for them.
    expect($runbook)->toContain('sudo /home/www/rateguru/bin/recover-host --verify --target staging-main');

    // recover-host says the same thing itself, so an operator who runs it
    // rather than reading is told, not left guessing.
    $usage = shellFunctionBody(File::get(base_path('infrastructure/scripts/recover-host')), 'usage');

    expect($usage)
        ->toContain('run from the trusted')
        ->toContain('--inspect, --resume and --verify need no bootstrap tooling');
});

it('tells an operator what a stopped run left behind, stage by stage, and never to remove the hold', function () {
    $runbook = cleanHostRunbook();
    $section = substr($runbook, strpos($runbook, '## K. '), strpos($runbook, '## L. ') - strpos($runbook, '## K. '));
    $flat = preg_replace('/\s+/', ' ', $section);

    // Stage 1: nothing was touched.
    expect($flat)
        ->toContain('Stopped before Prepare Host ran')
        ->toContain('nothing on the machine was touched');

    // Stage 2: the hold IS there, it stays, and mode=start re-enters.
    expect($flat)
        ->toContain('Stopped after Prepare Host started, with no operation ID in the summary')
        ->toContain('it **is** carrying the offsite-write hold Prepare Host places')
        ->toContain('That hold stays where it is')
        ->toContain('Re-run with **`mode=start`, the same target and the same exact backup**')
        ->toContain('Prepare Host is convergent and converges it again')
        ->toContain('A **different** backup or a **different** target is refused');

    // Stage 3: an operation exists, so it is continued.
    expect($flat)
        ->toContain('Stopped after `recover-host --apply` started')
        ->toContain('mode = continue-held');

    // And the instruction that must never appear, stated as a prohibition.
    expect($flat)
        ->toContain('Never remove the offsite-write hold to make a re-run pass')
        ->toContain('Nothing in a recovery ever asks you to');

    // The claim this section used to make, which the first real acceptance
    // proved false, is gone.
    expect(str_contains($flat, 'nothing is held on the machine'))->toBeFalse('a preparation that ran leaves the hold behind');

    // The two states a start may enter from are documented where the workflow
    // is described, and where the PRE_DEPLOY contract is stated.
    expect(preg_replace('/\s+/', ' ', $runbook))
        ->toContain('It accepts a machine in exactly one of two states')
        ->toContain('a preparation of this same recovery, to converge')
        ->toContain('proven by the offsite-write hold it placed');
});

it('documents the bootstrap access a clean replacement host necessarily already has', function () {
    $flat = preg_replace('/\s+/', ' ', cleanHostRunbook());

    expect($flat)
        ->toContain('It may be named `rateguru-*`; it must never be the target\'s own runtime or deploy account')
        ->toContain('That account, its own group and a sudoers grant named for it are the only RateGuru-shaped things the preflight expects to find')
        ->toContain('The **recovery bootstrap account** (§A) is the one identity that may already exist')
        ->toContain('Any other `rateguru-*` account or grant is still refused');

    // The script enforces exactly that, and only that.
    $preflight = File::get(base_path('infrastructure/scripts/recovery-host-preflight'));

    expect($preflight)
        ->toContain('users="$(grep -vxF -- "${BOOTSTRAP_USER}" <<<"${users}" || true)"')
        ->toContain('groups="$(grep -vxF -- "${BOOTSTRAP_USER}" <<<"${groups}" || true)"')
        ->toContain('sudoers="$(grep -vxF -- "${BOOTSTRAP_USER}" <<<"${sudoers}" || true)"')
        ->toContain('assert_bootstrap_identity');
});

it('names the workflow of the environment it is talking about, everywhere guidance is printed', function () {
    // A production host told to re-run "Recover staging host" is told to run
    // the wrong workflow against the wrong environment.
    foreach ([
        'infrastructure/scripts/recover-host',
        'infrastructure/scripts/fetch-recovery-material',
        'infrastructure/scripts/recovery-host-preflight',
    ] as $script) {
        $source = File::get(base_path($script));

        expect($source)
            ->toContain('recovery_workflow_name() {')
            ->toContain("staging)    printf 'Recover staging host\\n' ;;")
            ->toContain("production) printf 'Recover production host\\n' ;;");

        // The name appears exactly once — inside that function. Every piece of
        // guidance calls it.
        expect(substr_count($source, 'Recover staging host'))->toBe(1, "{$script} still hardcodes a workflow name");
        expect($source)->toContain('$(recovery_workflow_name)');
    }

    // The transport guidance in the action names the environment it was given.
    $action = File::get(base_path('.github/actions/recovery-host-preflight/action.yml'));

    expect($action)->toContain('"re-run \"Recover ${ENVIRONMENT} host\" with mode=start"');
    expect(substr_count($action, 'Recover staging host'))->toBe(0);
});

it('never derives a hostname from a sentence explaining that it has none', function () {
    $preflight = base_path('infrastructure/scripts/recovery-host-preflight');

    // With no readable registry the guide has no hostname, and says so with a
    // placeholder rather than pasting prose into a curl command.
    [$exit, $output] = runInfraScript($preflight, ['--operator-guide'], [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_RECOVERYPREFLIGHT_SOURCE_REGISTRY' => '/nonexistent/deployment-targets.json',
    ]);

    expect($exit)->toBe(0, $output);
    expect($output)
        ->toContain('curl --resolve <public-hostname>:443:<IP> https://<public-hostname>/up')
        ->toContain('(public hostnames of this target: unknown here (registry: public_hostnames))');

    expect(str_contains($output, 'curl --resolve the:'))->toBeFalse('a sentence is not a hostname');
});

it('opens with the whole operation in one screen, and the compact guide opens with the same one', function () {
    $runbook = cleanHostRunbook();
    $guide = cleanHostOperatorGuide();

    // An operator reaching for this document has usually lost a machine. The
    // first thing they meet is the entire operation — what to have ready, what
    // to dispatch, what to do if the run is interrupted, and what success is —
    // with everything below it as the explanation rather than the instruction.
    $summary = strpos($runbook, '## In one screen');
    $firstSection = strpos($runbook, '## A. ');

    expect($summary)->not->toBeFalse('the runbook has no one-screen summary')
        ->and($firstSection)->toBeGreaterThan($summary, 'the summary is not the first thing an operator reads');

    $box = substr($runbook, $summary, $firstSection - $summary);

    foreach (['**BEFORE RUN**', '**RUN**', '**IF INTERRUPTED', '**SUCCESS**'] as $part) {
        expect($box)->toContain($part);
    }

    // The dispatch is stated in full, in both modes, and the continuation is
    // conditional on an operation existing — a continue-held with no held
    // operation is the one re-run that cannot work.
    expect($box)
        ->toContain('mode             = start')
        ->toContain('mode             = continue-held')
        ->toContain('operation        = <the operation ID from the summary>')
        ->toContain('Leave `backup` empty');

    // And the compact guide the preflight prints leads with the same box, so
    // an operator who runs the script and an operator who opens the runbook
    // are told the same four things in the same order.
    $guideBox = substr($guide, (int) strpos($guide, 'IN ONE SCREEN'), (int) strpos($guide, '1. The new server') - (int) strpos($guide, 'IN ONE SCREEN'));

    foreach (['BEFORE RUN', 'RUN ', 'INTERRUPTED', 'SUCCESS'] as $part) {
        expect($guideBox)->toContain($part);
    }

    // The final contract is the same list of facts in both, and it is the one
    // the run summary reports.
    $report = (string) data_get(Yaml::parseFile(base_path('.github/workflows/recover-staging.yml')), 'jobs.report.steps.0.run');

    foreach (['queue', 'scheduler', 'health', 'offsite writes', 'DEPLOY_HOST', 'DNS'] as $fact) {
        expect(strtolower($box))->toContain(strtolower($fact));
        expect(strtolower($guideBox))->toContain(strtolower($fact));
    }

    expect($report)->toContain('OFFSITE WRITES: HELD');

    // The runbook names the script that prints the compact form, so the two
    // cannot drift apart without one of them saying where the other is.
    expect($box)->toContain('recovery-host-preflight --operator-guide --target staging-main');
});
