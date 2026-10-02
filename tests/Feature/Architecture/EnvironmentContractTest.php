<?php

use Illuminate\Support\Facades\File;

/**
 * infrastructure/scripts/verify-environment-contract and the shared contract in
 * `common` that deploy and configure both gate on.
 *
 * The problem it closes: a target's shared/.env is CANONICAL on the host. An
 * operator creates it once, backups carry it, a recovery restores it, and no
 * operation ever writes it. That is the right model and it has one consequence
 * — the host file can quietly fall behind the template a reviewed change added
 * a key to, and nothing notices until the application reads a key that is not
 * there, on the request path, after the deploy reported success.
 *
 * So the committed template is the declaration and this is the comparison. The
 * tests below are mostly about what it must NOT do: read a value, print a
 * value, or write anything at all.
 */
function envContractScratch(): string
{
    $dir = sys_get_temp_dir().'/env-contract-'.uniqid('', true).'-'.getmypid();

    @mkdir($dir, 0o700, true);

    return $dir;
}

function envContractCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Runs the real validator against a runtime file of our choosing, by pointing
 * the target's root at a scratch tree.
 *
 * @return array{0: int, 1: string}
 */
function envContractRun(string $scratch, string $target = 'staging-main', array $env = []): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];

    $process = proc_open(
        ['bash', base_path('infrastructure/scripts/verify-environment-contract'), '--target', $target],
        $descriptors,
        $pipes,
        null,
        array_merge([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
            'RATEGURU_TARGET_REGISTRY_FILE' => base_path('infrastructure/config/deployment-targets.json'),
            'RATEGURU_ENVCONTRACT_FS_ROOT' => $scratch,
        ], $env),
    );

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/** Writes a runtime .env at the target's canonical path inside the scratch tree. */
function envContractWrite(string $scratch, string $contents, string $root = '/home/www/rateguru/staging'): string
{
    $shared = $scratch.$root.'/shared';

    @mkdir($shared, 0o755, true);
    file_put_contents($shared.'/.env', $contents);

    return $shared.'/.env';
}

// The two secrets every test plants, so "no value is ever printed" is checked
// against values that would be unmistakable in any output.
const ENV_CONTRACT_SECRETS = ['base64:NEVER-PRINT-THIS-APP-KEY', 'NEVER-PRINT-THIS-DB-PASSWORD'];

function envContractRuntime(array $values = [], array $drop = [], array $repeat = []): string
{
    $contents = contractSatisfyingEnvironment(array_merge([
        'APP_KEY' => ENV_CONTRACT_SECRETS[0],
        'DB_PASSWORD' => ENV_CONTRACT_SECRETS[1],
    ], $values));

    foreach ($drop as $key) {
        $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$\R/m', '', $contents);
    }

    foreach ($repeat as $key) {
        $contents .= $key."=a-second-declaration\n";
    }

    return $contents;
}

/** The delimited gate deploy runs, so a test reads the call site and not the file. */
function deployGateSection(): string
{
    $source = File::get(base_path('infrastructure/scripts/deploy'));

    $start = mb_strpos($source, 'assert_candidate_environment_contract() {');
    $end = mb_strpos($source, '# --- deployment recovery');

    expect($start)->not->toBeFalse('deploy must define the candidate contract gate');
    expect($end)->toBeGreaterThan($start);

    return mb_substr($source, $start, $end - $start);
}

// =============================================================================
// The contract itself
// =============================================================================

it('passes when the runtime file declares every key the template does', function () {
    $scratch = envContractScratch();

    try {
        envContractWrite($scratch, envContractRuntime());

        [$exit, $output] = envContractRun($scratch);

        expect($exit)->toBe(0, $output);

        // Derived from the template this test actually compares against, not
        // written down: a count in a second file is a number that goes stale the
        // next time the contract gains a key.
        $declared = count(environmentTemplateKeys('infrastructure/templates/environment/staging.env.example'));

        expect($output)->toContain("all {$declared} declared key(s) present");
    } finally {
        envContractCleanup($scratch);
    }
});

it('fails and names the key when one the template declares is missing', function () {
    $scratch = envContractScratch();

    try {
        $path = envContractWrite($scratch, envContractRuntime(drop: ['SENTRY_DSN', 'REDIS_PREFIX']));

        [$exit, $output] = envContractRun($scratch);

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('ENVIRONMENT CONTRACT: BROKEN')
            ->toContain('MISSING')
            ->toContain('SENTRY_DSN')
            ->toContain('REDIS_PREFIX')
            // The file to edit, named, because the operator has to go and do it.
            ->toContain($path)
            ->toContain('this operation never writes it');
    } finally {
        envContractCleanup($scratch);
    }
});

it('fails on a key declared twice, because which value is in force is then unreadable', function () {
    // dotenv resolves one of them, and which one is not obvious from reading
    // the file — so the operator who edited it and the application can disagree
    // about the value in force. Checked for undeclared keys too.
    $scratch = envContractScratch();

    try {
        envContractWrite($scratch, envContractRuntime(repeat: ['APP_KEY']));

        [$exit, $output] = envContractRun($scratch);

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('DUPLICATE')
            ->toContain('APP_KEY')
            ->toContain('remove the rest');
    } finally {
        envContractCleanup($scratch);
    }
});

it('fails clearly when the runtime file does not exist at all', function () {
    $scratch = envContractScratch();

    try {
        [$exit, $output] = envContractRun($scratch);

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain('does not exist')
            ->toContain('created once by an operator')
            ->toContain('nothing was changed');
    } finally {
        envContractCleanup($scratch);
    }
});

it('allows an extra key the template does not declare, and says so', function () {
    // Refusing a deploy over a key an operator added, or one a retired feature
    // left behind, would be this check causing the outage it exists to prevent.
    $scratch = envContractScratch();

    try {
        envContractWrite($scratch, envContractRuntime()."LEGACY_LEFTOVER=1\n");

        [$exit, $output] = envContractRun($scratch);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('additional key(s)')->toContain('LEGACY_LEFTOVER');
    } finally {
        envContractCleanup($scratch);
    }
});

// =============================================================================
// What it must never do
// =============================================================================

it('never prints a value, on any path it can take', function () {
    // The single most important property here. Every branch is exercised
    // against a file whose secrets are unmistakable strings.
    $cases = [
        'satisfied' => envContractRuntime(),
        'missing' => envContractRuntime(drop: ['SENTRY_DSN']),
        'duplicate' => envContractRuntime(repeat: ['DB_PASSWORD']),
        'extra' => envContractRuntime()."SOMETHING_ELSE=also-secret-looking\n",
    ];

    foreach ($cases as $name => $contents) {
        $scratch = envContractScratch();

        try {
            envContractWrite($scratch, $contents);

            [, $output] = envContractRun($scratch);

            foreach (ENV_CONTRACT_SECRETS as $secret) {
                expect(str_contains($output, $secret))
                    ->toBeFalse("the {$name} path printed a value from the environment file");
            }
        } finally {
            envContractCleanup($scratch);
        }
    }
});

it('never writes the runtime file, whatever it finds', function () {
    // There is deliberately no --apply. A deploy that quietly added a missing
    // key would be inventing a value an operator is supposed to decide — most
    // often a credential — and would make the drift permanent and invisible.
    $scratch = envContractScratch();

    try {
        $path = envContractWrite($scratch, envContractRuntime(drop: ['SENTRY_DSN']));

        $before = file_get_contents($path);
        $mtimeBefore = filemtime($path);

        envContractRun($scratch);
        clearstatcache();

        expect(file_get_contents($path))->toBe($before);
        expect(filemtime($path))->toBe($mtimeBefore);
    } finally {
        envContractCleanup($scratch);
    }
});

it('implements no way to write an environment file', function (string $forbidden) {
    // Proved at the source as well as behaviourally: the behavioural test above
    // can only prove the paths it exercises.
    $source = executableSourceLines(File::get(base_path('infrastructure/scripts/verify-environment-contract')));

    expect(str_contains($source, $forbidden))
        ->toBeFalse("the environment contract validator must not be able to: {$forbidden}");
})->with(['--apply', '--fix', '>>', 'tee ', 'sed -i', 'install -m', 'mv -', 'rm -']);

it('reads the environment file and never sources or evaluates it', function () {
    // It is operator-authored root-owned secret material: sourcing it hands a
    // root shell to whoever last edited it.
    // The section is cut from the RAW file — the delimiters are comments, and
    // executableSourceLines strips those — and only then reduced to executable
    // lines, so prose about not sourcing cannot be mistaken for sourcing.
    $raw = File::get(base_path('infrastructure/scripts/common'));

    $begin = mb_strpos($raw, '# --- environment contract (begin) ---');
    $end = mb_strpos($raw, '# --- environment contract (end) ---');

    expect($begin)->not->toBeFalse();
    expect($end)->toBeGreaterThan($begin);

    $section = executableSourceLines(mb_substr($raw, $begin, $end - $begin));

    // Matched as a COMMAND at the start of a statement, not as a substring: a
    // local named own_source is not an invocation of source, and a test that
    // cannot tell the difference would be failed by correct code.
    foreach ([
        'source' => '/(^|[;&|]\s*|\bthen\s+|\bdo\s+)source\s/m',
        'eval' => '/(^|[;&|]\s*|\bthen\s+|\bdo\s+)eval\s/m',
        'the . builtin' => '/(^|[;&|]\s*)\.\s+\S/m',
    ] as $what => $pattern) {
        expect(preg_match($pattern, $section))
            ->toBe(0, "the environment contract must not use {$what} on the environment file");
    }

    // And it never emits the file's contents.
    foreach (['cat "${env_file}"', 'cat "$env_file"', 'printf \'%s\' "$(cat'] as $forbidden) {
        expect(str_contains($section, $forbidden))
            ->toBeFalse("the environment contract must not: {$forbidden}");
    }
});

// =============================================================================
// Where it is enforced
// =============================================================================

it('gates an ordinary deploy before any target mutation, from the candidate artifact', function () {
    // Position AND source, because either alone would be the wrong guarantee.
    //
    // The call site is found through its own delimiters, not by the first
    // mention of the function — the definition necessarily appears earlier in
    // the file and says nothing about when it runs.
    $source = File::get(base_path('infrastructure/scripts/deploy'));

    $call = mb_strpos($source, '# --- candidate environment contract (begin) ---');

    expect($call)->not->toBeFalse('deploy must gate on the candidate artifact');

    // After the target is locked and the artifact is proved safe to read.
    foreach ([
        'the deployment lock' => 'acquire_deployment_lock',
        'checksum verification' => 'verifying artifact checksum',
        'unsafe tar path rejection' => 'artifact contains an unsafe path',
    ] as $what => $needle) {
        $position = mb_strpos($source, $needle);

        expect($position)->not->toBeFalse("could not locate {$what}");
        expect($call)->toBeGreaterThan($position, "the contract is judged after {$what}");
    }

    // And before the first thing that changes the target at all.
    foreach ([
        'the deployment history row' => 'DEPLOYMENT_STARTED=true',
        'release extraction' => 'log "extracting ${RELEASE_ID}"',
        'the migration step' => 'running database migrations',
        'the current switch' => 'switching current symlink',
    ] as $what => $needle) {
        $position = mb_strpos($source, $needle);

        expect($position)->not->toBeFalse("could not locate {$what}");
        expect($call)->toBeLessThan($position, "the contract must be judged before {$what}");
    }
});

it('never lets an ordinary deploy decide compatibility from the installed template', function () {
    // The defect this whole change exists to close. install-target-operations is
    // not part of an application deployment, so the installed template describes
    // whatever was installed last — not what the artifact being deployed needs.
    $gate = deployGateSection();

    foreach ([
        'the installed template root' => 'ENVIRONMENT_TEMPLATE_INSTALLED_ROOT',
        'the operational template resolver' => 'environment_template_file',
    ] as $what => $forbidden) {
        expect(str_contains($gate, $forbidden))
            ->toBeFalse("an ordinary deploy must not consult {$what}");
    }

    // It reads the artifact instead.
    expect($gate)->toContain('artifact_environment_template_stage');
});

it('exempts controlled restore and recovery alignment from the current contract', function () {
    // A restore or a recovery puts an EXACT historical release beside historical
    // state. A backup's environment belongs to the source SHA it was taken from
    // and may legitimately predate keys that exist today; refusing that pair
    // would make a valid disaster recovery impossible.
    $gate = deployGateSection();

    expect($gate)->toContain('if controlled_mode; then');

    // The exemption is announced, not silent, and says where the contract is
    // enforced instead.
    expect($gate)
        ->toContain('deliberately NOT applied')
        ->toContain('the next ordinary deployment enforces the current contract');
});

it('skips the check explicitly on an artifact built before the feature existed', function () {
    // RateGuru must keep deploying artifacts built before this feature. Refusing
    // them for not carrying a declaration they could not have carried would make
    // old releases undeployable — the opposite of what a recovery needs.
    expect(deployGateSection())
        ->toContain('release predates artifact-owned environment contract — skipping environment key validation');
});

it('removes its staging directory on every path, including a refusal', function () {
    // The resolver refuses a broken artifact by failing, which would exit deploy
    // before any cleanup — so it is run in a subshell and the directory is
    // removed on each branch.
    $gate = deployGateSection();

    expect(substr_count($gate, 'rm -rf "${stage}"'))
        ->toBeGreaterThanOrEqual(3, 'the staging directory must be removed on the pass, refuse and broken-artifact paths');
});

it('gates configure before the database and the deploy key', function () {
    $source = File::get(base_path('infrastructure/scripts/configure-target'));

    // The call inside apply_gate specifically. The read-only report calls the
    // same contract earlier in the file so --check and --apply agree, so the
    // first occurrence is not the gate.
    $gateStart = mb_strpos($source, 'apply_gate() {');
    $gateEnd = mb_strpos($source, 'converge_material() {');

    expect($gateStart)->not->toBeFalse();
    expect($gateEnd)->toBeGreaterThan($gateStart);

    $gate = mb_substr($source, $gateStart, $gateEnd - $gateStart);

    expect(str_contains($gate, 'assert_environment_contract'))
        ->toBeTrue('configure-target must verify the environment contract inside apply_gate');

    // And before the children that depend on what the file contains: the
    // database installer reads its credentials out of it, so a key missing here
    // surfaces there as an unrelated-looking database failure.
    expect(mb_strpos($gate, 'assert_environment_contract'))
        ->toBeLessThan(mb_strpos($gate, 'DATABASE_BIN'), 'the contract is judged before the database preflight');

    // The read-only modes agree with apply rather than reporting a target as
    // fine that --apply would refuse.
    expect($source)->toContain('environment:canonical');
});

it('installs the standalone validator and its templates for host-side inspection', function () {
    // Installed for the STANDALONE check, not for the deployment decision: an
    // operator asking "does this host satisfy the contract its tooling declares?"
    // needs both locally, on a machine with no repository.
    //
    // An ordinary deploy deliberately does not consult these — see the test above
    // — because this installer is not part of an application deployment and so
    // what it installed may be older than the application being deployed.
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    expect($installer)
        ->toContain('SRC_VERIFY_ENV_CONTRACT=')
        ->toContain('DST_VERIFY_ENV_CONTRACT=')
        ->toContain('DST_ENV_TEMPLATES_ROOT=')
        ->toContain('ensure_env_templates_root')
        ->toContain('verify_env_templates_root');

    // Every template the registry declares is installed — the installer lists
    // them explicitly, so completeness is enforced here rather than assumed.
    $targets = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'];

    foreach ($targets as $id => $target) {
        $basename = basename($target['environment_template']);

        expect(str_contains($installer, $basename))
            ->toBeTrue("{$id} declares {$basename}, which install-target-operations does not install — the standalone validator could not judge that target on a host");
    }

    // And the installed location is the one `common` resolves.
    expect(File::get(base_path('infrastructure/scripts/common')))
        ->toContain('ENVIRONMENT_TEMPLATE_INSTALLED_ROOT="/home/www/rateguru/config/environment"');
});

it('is registered as a required CLI and ships executable', function () {
    expect(File::get(base_path('infrastructure/config/required-clis.txt')))
        ->toContain("verify-environment-contract\n");

    expect(is_executable(base_path('infrastructure/scripts/verify-environment-contract')))->toBeTrue();
});
