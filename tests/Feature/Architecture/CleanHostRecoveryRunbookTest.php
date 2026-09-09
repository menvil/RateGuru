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

/** @return list<string> */
function recoveryValuesRead(string $workflow): array
{
    preg_match_all('/\b(?:vars|secrets)\.((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', File::get(base_path('.github/workflows/'.$workflow)), $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

it('is self-contained — every section an operator needs, in order — and every recovery surface points at it', function () {
    preg_match_all('/^## ([A-L])\. (.+)$/m', cleanHostRunbook(), $headings);

    expect($headings[1])->toBe(range('A', 'L'));
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
    $read = recoveryValuesRead('recover-staging.yml');

    expect(recoveryValuesRead('recover-production.yml'))->toBe($read);
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
    $workflow = File::get(base_path('.github/workflows/recover-staging.yml'));

    preg_match_all('/\bvars\.((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', $workflow, $vars);
    preg_match_all('/\bsecrets\.((?:RECOVERY|DEPLOY)_[A-Z_]+)\b/', $workflow, $secrets);

    $vars = array_values(array_unique($vars[1]));
    $secrets = array_values(array_unique($secrets[1]));
    sort($vars);
    sort($secrets);

    expect($vars)->toBe(['DEPLOY_HOST', 'DEPLOY_INCOMING', 'DEPLOY_ROOT', 'DEPLOY_USER', 'DEPLOY_WRAPPER', 'RECOVERY_BOOTSTRAP_USER']);
    expect($secrets)->toBe(['DEPLOY_KNOWN_HOSTS', 'DEPLOY_SSH_KEY', 'RECOVERY_BOOTSTRAP_SSH_KEY', 'RECOVERY_KNOWN_HOSTS', 'RECOVERY_RCLONE_CONFIG']);
    expect(array_intersect($vars, $secrets))->toBe([], 'no value is read both ways');

    // The runbook's section D: a variables table and a secrets table, each
    // naming exactly the values the workflow reads that way.
    $runbook = cleanHostRunbook();
    $sectionD = substr($runbook, strpos($runbook, '## D. '), strpos($runbook, '## E. ') - strpos($runbook, '## D. '));
    $variablesTable = substr($sectionD, strpos($sectionD, '**Environment variables**'), strpos($sectionD, '**Environment secrets**') - strpos($sectionD, '**Environment variables**'));
    $secretsTable = substr($sectionD, strpos($sectionD, '**Environment secrets**'), strpos($sectionD, 'The workflow proves all of them present') - strpos($sectionD, '**Environment secrets**'));

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
    $guideVariables = substr($guide, strpos($guide, 'Environment variables (Settings'), strpos($guide, 'Environment secrets (Settings') - strpos($guide, 'Environment variables (Settings'));
    $guideSecrets = substr($guide, strpos($guide, 'Environment secrets (Settings'), strpos($guide, 'NO PREPARE_*') - strpos($guide, 'Environment secrets (Settings'));

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
