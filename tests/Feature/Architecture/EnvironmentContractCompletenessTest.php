<?php

use Illuminate\Support\Facades\File;

/**
 * The second half of the environment contract, and a different problem from the
 * first.
 *
 * PR #1178 protects a HOST from an incomplete `.env`: the candidate artifact's
 * template is compared against the live file before a deploy touches anything.
 * That is only as good as the template. It says nothing about a template that
 * has quietly forgotten a variable the application actually reads.
 *
 * We found that for real: a staging deploy was correctly refused because the
 * runtime `.env` had no MEDIA_PUBLIC_DISK — and the template had not been
 * carrying it either, so nothing before the refusal had noticed.
 *
 * So this closes the inventory. Every environment variable config/*.php reads
 * must be ACCOUNTED FOR: either declared by the deployment target templates, or
 * explicitly recorded as outside the deployed contract with a reason. Adding an
 * env() to config/ and deciding nothing is what fails here.
 *
 *     config/*.php  ->  target template  or  explicit policy  ->  CI
 */
function completenessContract(): array
{
    return json_decode(
        File::get(base_path('infrastructure/config/environment-contract.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/** Every schema entry, indexed by key — so "exactly one record per key" is checkable. */
function completenessEntries(): array
{
    $entries = [];

    foreach (completenessContract()['keys'] as $entry) {
        $entries[$entry['key']][] = $entry;
    }

    return $entries;
}

/** KEY => category, for the keys the contract classifies as excluded. */
function completenessExcluded(): array
{
    $excluded = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (($entry['classification'] ?? null) === 'excluded') {
            $excluded[$entry['key']] = $entry['category'] ?? '';
        }
    }

    return $excluded;
}

function completenessConfigFiles(): array
{
    return glob(base_path('config/*.php')) ?: [];
}

/**
 * Every environment variable config/*.php names as a LITERAL, and every place it
 * reads one without naming it.
 *
 * Scanned with the PHP tokenizer rather than a regular expression, for a reason
 * that bit the first attempt: `config/sentry.php` and `config/nightwatch.php`
 * both explain env() in their header comments, and a regex happily inventories
 * prose. The tokenizer cannot see a comment at all.
 *
 * @return array{keys: array<string, list<string>>, dynamic: list<string>}
 */
function completenessInventory(): array
{
    $keys = [];
    $dynamic = [];

    foreach (completenessConfigFiles() as $path) {
        $relative = 'config/'.basename($path);
        $tokens = token_get_all(File::get($path));

        // Local closures that read env() on a caller's behalf. Declared in the
        // policy file; their bodies are the only place a non-literal env() may
        // live, and their own call sites still name the key as a literal.
        $readers = completenessContract()['config_env_readers'][$relative] ?? [];

        // The token ranges those readers occupy. A non-literal env() is allowed
        // inside one of them and nowhere else, which is what keeps a dynamic
        // read from being a way around the inventory.
        $readerRanges = completenessReaderRanges($tokens, $readers);

        foreach ($tokens as $index => $token) {
            $name = completenessCallName($token);

            if ($name === null) {
                continue;
            }

            $argument = completenessFirstArgument($tokens, $index);

            // A literal first argument: either env('KEY') or one of this file's
            // declared readers invoked as $reader('KEY').
            if ($name === 'env' || in_array(ltrim($name, '$'), $readers, true)) {
                if ($argument !== null) {
                    $keys[$argument][] = $relative;

                    continue;
                }
            }

            if ($name !== 'env' || $argument !== null) {
                continue;
            }

            if (completenessWithinRange($index, $readerRanges)) {
                continue;
            }

            $line = is_array($token) ? $token[2] : 0;
            $dynamic[] = $relative.':'.$line.' env() called without a literal key';
        }
    }

    foreach ($keys as $key => $files) {
        $keys[$key] = array_values(array_unique($files));
    }

    ksort($keys);

    return ['keys' => $keys, 'dynamic' => array_values(array_unique($dynamic))];
}

/**
 * The callee name for a token that starts a call, or null when it is not one.
 *
 * Both `env('KEY')` and `\env('KEY')` have to be recognised. The second is the
 * same function — PHP resolves an unqualified call to the global one anyway — but
 * it tokenizes as T_NAME_FULLY_QUALIFIED rather than T_STRING, so a reader that
 * only knew T_STRING would let one leading backslash walk a key straight past the
 * inventory. The separator is stripped before comparing.
 */
function completenessCallName(array|string $token): ?string
{
    if (! is_array($token)) {
        return null;
    }

    if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
        return ltrim($token[1], '\\') === 'env' ? 'env' : null;
    }

    if ($token[0] === T_VARIABLE) {
        return $token[1];
    }

    return null;
}

/** Does this token call the named global function, qualified or not? */
function completenessCallsFunction(array|string $token, string $name): bool
{
    return is_array($token)
        && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
        && ltrim($token[1], '\\') === $name;
}

/**
 * The literal first argument of the call starting at $index, or null when the
 * token is not a call at all or its first argument is not a literal string.
 */
function completenessFirstArgument(array $tokens, int $index): ?string
{
    $position = $index + 1;
    $count = count($tokens);

    while ($position < $count && is_array($tokens[$position]) && in_array($tokens[$position][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $position++;
    }

    if ($position >= $count || $tokens[$position] !== '(') {
        return null;
    }

    $position++;

    while ($position < $count && is_array($tokens[$position]) && in_array($tokens[$position][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $position++;
    }

    if ($position >= $count || ! is_array($tokens[$position]) || $tokens[$position][0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    $literal = trim($tokens[$position][1], '\'"');

    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $literal) === 1 ? $literal : null;
}

/**
 * The token-index ranges the named closures occupy, found by brace matching from
 * each declaration to its own close.
 *
 * @return list<array{0: int, 1: int}>
 */
function completenessReaderRanges(array $tokens, array $readers): array
{
    $ranges = [];
    $count = count($tokens);

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }

        if (! in_array(ltrim($token[1], '$'), $readers, true)) {
            continue;
        }

        // Only a DECLARATION opens a range; a call site does not.
        $position = $index + 1;

        while ($position < $count && is_array($tokens[$position]) && $tokens[$position][0] === T_WHITESPACE) {
            $position++;
        }

        if ($position >= $count || $tokens[$position] !== '=') {
            continue;
        }

        $depth = 0;
        $opened = false;

        for ($scan = $position; $scan < $count; $scan++) {
            if ($tokens[$scan] === '{') {
                $depth++;
                $opened = true;

                continue;
            }

            if ($tokens[$scan] === '}') {
                $depth--;

                if ($opened && $depth === 0) {
                    $ranges[] = [$index, $scan];

                    break;
                }
            }
        }
    }

    return $ranges;
}

/** @param  list<array{0: int, 1: int}>  $ranges */
function completenessWithinRange(int $index, array $ranges): bool
{
    foreach ($ranges as [$start, $end]) {
        if ($index >= $start && $index <= $end) {
            return true;
        }
    }

    return false;
}

/** The keys the deployment target templates declare — the authoritative contract. */
function completenessContractKeys(): array
{
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    $keys = [];

    foreach ($registry['targets'] as $target) {
        foreach (environmentTemplateKeys($target['environment_template']) as $key) {
            $keys[$key] = true;
        }
    }

    return $keys;
}

// =============================================================================
// The inventory is closed
// =============================================================================

it('accounts for every environment variable config/*.php reads', function () {
    // The rule, in one place: a key is either in the deployed contract or it is
    // explicitly recorded as not being in it. Deciding nothing is the failure.
    $inventory = completenessInventory()['keys'];
    $contract = completenessContractKeys();
    $policy = completenessExcluded();

    expect($inventory)->not->toBeEmpty('the inventory found no env() at all — the scanner is broken, not config/');

    $unaccounted = [];

    foreach ($inventory as $key => $files) {
        if (isset($contract[$key]) || array_key_exists($key, $policy)) {
            continue;
        }

        $unaccounted[$key] = implode(', ', $files);
    }

    expect($unaccounted)->toBe([], implode("\n", [
        'These environment variables are read by config/ and nobody has decided whether a deployed target must supply them:',
        ...array_map(fn ($files, $key): string => "  {$key}  ({$files})", $unaccounted, array_keys($unaccounted)),
        '',
        'Add each ONCE to infrastructure/config/environment-contract.json, classified:',
        '',
        '  a deployed target must supply it ->  "classification": "target", with a',
        '      "section" that exists and the value it should render with, then run',
        '      infrastructure/scripts/render-environment-templates --write',
        '',
        '  it is not part of a target\'s contract ->  "classification": "excluded",',
        '      with a "category" from exclusion_categories and a "reason" saying why',
        '',
        'Do NOT edit infrastructure/templates/environment/*.env.example: those files',
        'are generated from the contract, and a hand-edit fails CI on the next render.',
    ]));
});

it('carries no stale exception for a variable config/ no longer reads', function () {
    // The other direction, which is what keeps the file honest rather than
    // ever-growing: an exception nobody needs is a decision nobody is reviewing.
    $inventory = completenessInventory()['keys'];
    $policy = completenessExcluded();

    $stale = array_values(array_filter(
        array_keys($policy),
        fn (string $key): bool => ! isset($inventory[$key]),
    ));

    expect($stale)->toBe([], 'no config/*.php reads these, so their exceptions should be removed: '.implode(' ', $stale));
});

it('refuses a variable that is both in the contract and exempted from it', function () {
    // Two answers to one question is not a stricter rule, it is an unreadable
    // one: the next reader cannot tell which was intended.
    $contract = completenessContractKeys();
    $policy = completenessExcluded();

    $both = array_values(array_filter(array_keys($policy), fn (string $key): bool => isset($contract[$key])));

    expect($both)->toBe([], 'these are declared by a target template AND exempted: '.implode(' ', $both));
});

it('reads every environment variable by a literal name', function () {
    // A dynamic env() escapes the inventory entirely, which would make every
    // check above decorative. The two files that legitimately read through a
    // local closure declare those closures in the policy; the keys are still
    // literals at their call sites.
    $dynamic = completenessInventory()['dynamic'];

    expect($dynamic)->toBe([], implode("\n", [
        'config/ reads an environment variable without naming it, so the inventory cannot see it:',
        ...array_map(fn (string $site): string => '  '.$site, $dynamic),
        '',
        'Name the key as a literal, or declare the reading closure in',
        'infrastructure/config/environment-contract.json under config_env_readers.',
    ]));
});

it('declares a reader only for a file that has one', function () {
    // A declared reader is permission for a dynamic env(); permission for a file
    // that no longer needs it is permission nobody is looking at.
    $readers = completenessContract()['config_env_readers'];

    foreach ($readers as $relative => $names) {
        if ($relative === '$schema_note') {
            continue;
        }

        expect(File::exists(base_path($relative)))->toBeTrue("{$relative} is declared as an env reader but does not exist");

        $source = File::get(base_path($relative));

        foreach ($names as $name) {
            expect(str_contains($source, "\${$name} = static function"))
                ->toBeTrue("{$relative} does not declare the reader \${$name}");
        }
    }
});

it('accounts for every environment variable read outside config/, too', function () {
    // Laravel's rule is that env() belongs in config/ only, and the audit found
    // three exceptions. None is read while serving a request — each is an input to
    // a command somebody runs deliberately — so none is part of a target's .env
    // contract. They are recorded anyway, because the invariant this file exists
    // for is that there is no third, silent category.
    $found = [];

    foreach (['app', 'database', 'routes', 'bootstrap'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all(File::get($file->getPathname())) as $index => $token) {
                if (! completenessCallsFunction($token, 'env') && ! completenessCallsFunction($token, 'getenv')) {
                    continue;
                }

                $tokens = token_get_all(File::get($file->getPathname()));
                $key = completenessFirstArgument($tokens, $index);

                if ($key !== null) {
                    $found[$key] = true;
                }
            }
        }
    }

    $recorded = completenessContract()['read_outside_config'];
    unset($recorded['$schema_note']);

    $unaccounted = array_values(array_diff(array_keys($found), array_keys($recorded)));
    $stale = array_values(array_diff(array_keys($recorded), array_keys($found)));

    expect($unaccounted)->toBe([], 'read outside config/ and unaccounted for: '.implode(' ', $unaccounted));
    expect($stale)->toBe([], 'recorded as read outside config/ but no longer read: '.implode(' ', $stale));
});

// =============================================================================
// The policy file itself
// =============================================================================

it('records a reason for every category, and uses every category it records', function () {
    $policy = completenessContract();
    $categories = array_keys($policy['exclusion_categories']);
    $used = array_values(array_unique(array_values(completenessExcluded())));

    sort($categories);
    sort($used);

    expect($used)->toBe($categories, 'every category must be used, and every category used must be described');

    foreach ($policy['exclusion_categories'] as $name => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(60, "category {$name} needs a reason, not a label");
    }
});

it('names every excluded key as a variable and every category as a slug', function () {
    foreach (completenessExcluded() as $key => $category) {
        expect($key)->toMatch('/^[A-Z][A-Z0-9_]*$/', "contract key is not a variable name: {$key}");
        expect($category)->toMatch('/^[a-z][a-z0-9-]*$/', "exclusion category is not a slug: {$category}");
    }
});

it('leaves every sensitive value blank, so no secret enters the repository', function () {
    // The contract IS the source of rendered values now, which makes this the
    // claim that matters: a key carrying a credential is declared and left empty,
    // and the operator supplies it on the host. An honest default would be a
    // committed secret.
    $sensitive = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (($entry['sensitive'] ?? false) !== true) {
            continue;
        }

        $sensitive[] = $entry['key'];

        expect($entry['value'])->toBe('', "{$entry['key']} is marked sensitive and must render blank");
    }

    // Not a vacuous pass: the obvious credentials must actually be marked.
    foreach (['APP_KEY', 'DB_PASSWORD', 'SENTRY_DSN', 'NIGHTWATCH_TOKEN', 'MAIL_PASSWORD',
        'GOOGLE_CLIENT_SECRET', 'FACEBOOK_CLIENT_SECRET'] as $key) {
        expect(in_array($key, $sensitive, true))->toBeTrue("{$key} must be marked sensitive in the contract");
    }

    // And the rendered templates carry no value for any of them.
    foreach (['staging', 'tits-guru'] as $target) {
        $rendered = File::get(base_path("infrastructure/templates/environment/{$target}.env.example"));

        foreach ($sensitive as $key) {
            expect(str_contains($rendered, "\n{$key}=\n"))
                ->toBeTrue("{$target} renders a value for the sensitive {$key}");
        }
    }
});

it('does not promote a provider Laravel merely supports into the deployed contract', function (string $key) {
    // The temptation this guards against: Laravel's generic config references a
    // dozen providers, and "completeness" could be read as a reason to tell an
    // operator to supply credentials for every one of them. Our topology uses
    // none of these, so requiring them would invent work and invite a real
    // secret to be pasted into a target that never calls it.
    $contract = completenessContractKeys();

    expect(isset($contract[$key]))->toBeFalse("{$key} is not used by this deployment topology and must not be a required target key");
})->with([
    'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET',
    'POSTMARK_API_KEY', 'RESEND_API_KEY',
    'SLACK_BOT_USER_OAUTH_TOKEN', 'LOG_SLACK_WEBHOOK_URL',
    'MEMCACHED_HOST', 'MEMCACHED_PASSWORD',
    'SQS_QUEUE', 'BEANSTALKD_QUEUE', 'DYNAMODB_CACHE_TABLE',
    'APP_FAKER_LOCALE',
]);

// =============================================================================
// What this PR added, pinned so it cannot quietly fall back out
// =============================================================================

it('declares the media lifecycle and locking settings in both target templates', function (string $key) {
    // The locks especially: variant generation and the purge each take one so two
    // workers cannot derive or delete the same object at once. A blank TTL is not
    // a longer lock, it is an unreadable one.
    foreach (['staging', 'tits-guru'] as $target) {
        expect(in_array($key, environmentTemplateKeys("infrastructure/templates/environment/{$target}.env.example"), true))
            ->toBeTrue("{$target} must declare {$key}");
    }
})->with([
    'MEDIA_VARIANT_LOCK_WAIT_SECONDS', 'MEDIA_VARIANT_LOCK_TTL_SECONDS',
    'MEDIA_PURGE_GRACE_DAYS', 'MEDIA_ORPHAN_GRACE_HOURS',
    'MEDIA_PURGE_LOCK_TTL_SECONDS', 'MEDIA_AUDIT_LOCK_TTL_SECONDS',
    'MEDIA_AUDIT_RUN_RETENTION',
]);

it('declares the content lifecycle and contact settings in both target templates', function (string $key) {
    foreach (['staging', 'tits-guru'] as $target) {
        expect(in_array($key, environmentTemplateKeys("infrastructure/templates/environment/{$target}.env.example"), true))
            ->toBeTrue("{$target} must declare {$key}");
    }
})->with([
    'POST_AUTHOR_DELETE_RETENTION_DAYS',
    'COMMENT_AUTHOR_DELETE_RETENTION_DAYS',
    'MODERATION_CONTENT_RETENTION_DAYS',
    'MAIL_CONTACT_TO',
]);

it('states the public media disk explicitly for every target', function (string $target) {
    // The key whose absence refused a real staging deploy. A blank value here
    // would be the same defect wearing the key name.
    expect(File::get(base_path("infrastructure/templates/environment/{$target}.env.example")))
        ->toContain("\nMEDIA_PUBLIC_DISK=public\n");
})->with(['staging', 'tits-guru']);

it('keeps the local .env.example coherent with config/media.php', function (string $key) {
    // Not identical to the target templates — .env.example legitimately carries
    // local, build and provider settings a deployed target has no use for — but
    // it must not omit a setting the application reads.
    expect(File::get(base_path('.env.example')))->toContain("\n{$key}=");
})->with([
    'MEDIA_VARIANT_LOCK_WAIT_SECONDS',
    'MEDIA_VARIANT_LOCK_TTL_SECONDS',
    'MEDIA_PURGE_LOCK_TTL_SECONDS',
]);

// =============================================================================
// One source of truth, and the templates generated from it
// =============================================================================
//
// For as long as each target's template was hand-maintained, "every target
// declares the same keys" was a rule somebody had to remember: add a key to
// staging, remember production, remember the next brand. A rule enforced by
// memory is a rule that fails the week it matters.
//
// So the key list, the sections, the descriptions, the values and the
// classification of every key live in ONE reviewed file, and the templates are
// GENERATED. The invariant below is stronger than the parity it replaces: both
// committed templates are the output of the contract, so they agree by
// construction rather than by anybody checking.

function completenessRenderer(): string
{
    return base_path('infrastructure/scripts/render-environment-templates');
}

/** @return array{0: int, 1: string} */
function completenessRender(string $mode): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];

    $process = proc_open(['bash', completenessRenderer(), $mode], $descriptors, $pipes, base_path(), [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ]);

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

it('has exactly one schema record per environment variable', function () {
    // "Exactly one" is the property that makes every other check here mean
    // something: two records for a key is two answers to one question.
    $duplicated = [];

    foreach (completenessEntries() as $key => $records) {
        if (count($records) > 1) {
            $duplicated[] = $key.' ('.count($records).' records)';
        }
    }

    expect($duplicated)->toBe([], 'these keys are declared more than once in the contract: '.implode(' ', $duplicated));
});

it('classifies every schema record as exactly target or excluded', function () {
    $wrong = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (! in_array($entry['classification'] ?? null, ['target', 'excluded'], true)) {
            $wrong[] = ($entry['key'] ?? '?').' => '.var_export($entry['classification'] ?? null, true);
        }
    }

    expect($wrong)->toBe([], 'a key must be target or excluded, nothing else: '.implode(' ', $wrong));
});

it('gives every excluded key a category and a reason', function () {
    // An exception without a reason is an exception nobody can review, which is
    // the same as not having decided.
    $categories = completenessContract()['exclusion_categories'];
    $bad = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (($entry['classification'] ?? null) !== 'excluded') {
            continue;
        }

        $category = $entry['category'] ?? '';
        $reason = $entry['reason'] ?? '';

        if ($category === '' || ! array_key_exists($category, $categories) || mb_strlen($reason) < 60) {
            $bad[] = $entry['key'];
        }
    }

    expect($bad)->toBe([], 'these excluded keys lack a known category or a reason: '.implode(' ', $bad));
});

it('can render every target key for every registered deployment target', function () {
    // A target key whose value cannot be produced for some registered target is a
    // contract that renders for staging and fails the day a brand is added.
    $targets = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'];
    $unrenderable = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (($entry['classification'] ?? null) !== 'target') {
            continue;
        }

        $value = $entry['value'] ?? null;

        foreach ($targets as $id => $target) {
            if (is_string($value)) {
                continue;
            }

            if (is_array($value) && isset($value['from'])) {
                if (in_array($value['from'], ['target_id', 'environment_class'], true)) {
                    continue;
                }

                $unrenderable[] = $entry['key'].' (unknown source '.$value['from'].')';

                continue;
            }

            if (is_array($value) && isset($value['by_environment_class'])) {
                if (array_key_exists($target['environment_class'], $value['by_environment_class'])) {
                    continue;
                }

                $unrenderable[] = $entry['key'].' (no value for '.$id.'\'s class '.$target['environment_class'].')';

                continue;
            }

            $unrenderable[] = $entry['key'].' (unsupported value shape)';
        }
    }

    expect(array_values(array_unique($unrenderable)))->toBe([], implode(' | ', array_unique($unrenderable)));
});

it('has committed templates that are exactly the contract rendered', function () {
    // The invariant that replaces hand-kept parity: a developer changes the
    // contract once, and both templates follow. CI is what makes that true.
    [$exit, $output] = completenessRender('--check');

    expect($exit)->toBe(0, $output);
    expect($output)->toContain('every committed target template matches');
});

it('renders the same bytes twice', function () {
    // A renderer whose output depends on hash order, locale or the clock cannot be
    // checked against a committed file at all.
    //
    // This is the one test that invokes --write, so it restores the files itself
    // rather than trusting the run to be idempotent: proving idempotence is the
    // point, and a test that leaves the working tree changed when its subject is
    // broken is a test that breaks the next one too.
    $targets = ['staging', 'tits-guru'];
    $before = [];

    foreach ($targets as $target) {
        $path = base_path("infrastructure/templates/environment/{$target}.env.example");
        $before[$path] = File::get($path);
    }

    try {
        [$exit, $output] = completenessRender('--write');
        expect($exit)->toBe(0, $output);

        foreach ($before as $path => $content) {
            expect(File::get($path))->toBe($content, basename($path).' changed when re-rendered from an unchanged contract');
        }
    } finally {
        foreach ($before as $path => $content) {
            file_put_contents($path, $content);
        }
    }
});

it('writes nothing at all in check mode', function () {
    // --check is run in CI, where a renderer that quietly fixed the thing it is
    // asked to judge would make the judgement meaningless.
    $watched = [];

    foreach (['staging', 'tits-guru'] as $target) {
        $path = base_path("infrastructure/templates/environment/{$target}.env.example");
        $watched[$path] = [File::get($path), filemtime($path)];
    }

    completenessRender('--check');
    clearstatcache();

    foreach ($watched as $path => [$content, $mtime]) {
        expect(File::get($path))->toBe($content);
        expect(filemtime($path))->toBe($mtime, basename($path).' was touched by --check');
    }
});

it('can only ever write a repository template', function () {
    // The hard boundary, and it has to be asserted as a PROPERTY rather than as
    // banned words: this script legitimately explains the boundary in its usage
    // text, and legitimately emits a template whose own header names the host
    // path. Neither is the script acting on one.
    //
    // What matters is where it redirects output. Every write goes to one of two
    // variables: the registry-derived destination, or a file inside the private
    // staging directory --check renders into. A target's shared/.env is canonical
    // on its host and operator-owned; a generator able to write one would be a way
    // for tooling to invent a credential nobody chose.
    $source = executableSourceLines(File::get(completenessRenderer()));

    // Strip the usage heredoc: it is prose, not code.
    $code = (string) preg_replace("/cat <<'USAGE'.*?\nUSAGE/s", '', $source);

    preg_match_all('/>\s*"([^"]+)"/', $code, $matches);

    expect($matches[1])->not->toBeEmpty('the renderer writes nothing at all — the scan is wrong');

    // Stronger than it was, and for a reason that came out of making --write
    // atomic: nothing is redirected at a committed file any more. Every render
    // goes to the private staging copy, and the only thing that touches a
    // committed template is one install(1) whose target is the registry-derived
    // destination.
    expect(array_values(array_unique($matches[1])))
        ->toBe(['${rendered}'], 'the renderer must only ever redirect into its own staging copy');

    expect($code)->toContain('install -m 0644 "${source}" "${target}"');

    // The destination is composed from the repository root plus the registry's own
    // declaration, and that declaration is PROVED to name a file directly inside
    // the template directory — reconstructed from its own basename and required to
    // equal what was declared, which no path with a segment in the middle can
    // survive. A glob here would not be a boundary: `*` spans `/../`.
    expect($code)
        ->toContain('destination="${REPO_ROOT}/${declared}"')
        ->toContain('assert_destination_within_templates "${declared}" "${target_id}"')
        ->toContain('"${declared}" == "infrastructure/templates/environment/${base}"');

    // It takes no path from the caller at all.
    expect($code)->not->toContain('--env-file');
    expect($code)->not->toContain('--output');

    // And it is not installed on a host, so it cannot be reached there.
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toContain('render-environment-templates');

    expect(File::get(base_path('infrastructure/config/required-clis.txt')))
        ->not->toContain('render-environment-templates');
});

it('derives a target-specific value from the registry rather than repeating it', function (string $key, string $source) {
    // The registry is where a target is defined. Writing its id or class into the
    // contract as a literal per target would be a second definition, and the two
    // would disagree the first time one was edited.
    $entry = collect(completenessContract()['keys'])->firstWhere('key', $key);

    expect($entry)->not->toBeNull();
    expect($entry['value'])->toBe(['from' => $source]);
})->with([
    ['APP_DEPLOYMENT_TARGET', 'target_id'],
    ['APP_ENV', 'environment_class'],
    ['SENTRY_ENVIRONMENT', 'environment_class'],
]);

it('renders the environment-specific values each target actually needs', function () {
    // The few settings that genuinely differ, proved against the rendered files
    // rather than against the contract that produced them.
    $staging = File::get(base_path('infrastructure/templates/environment/staging.env.example'));
    $production = File::get(base_path('infrastructure/templates/environment/tits-guru.env.example'));

    expect($staging)
        ->toContain("\nAPP_DEPLOYMENT_TARGET=staging-main\n")
        ->toContain("\nAPP_ENV=staging\n")
        ->toContain("\nSENTRY_ENVIRONMENT=staging\n")
        ->toContain("\nMAIL_HOST=127.0.0.1\n")
        ->toContain("\nSENTRY_TRACES_SAMPLE_RATE=1.0\n");

    expect($production)
        ->toContain("\nAPP_DEPLOYMENT_TARGET=tits-guru\n")
        ->toContain("\nAPP_ENV=production\n")
        ->toContain("\nSENTRY_ENVIRONMENT=production\n")
        ->toContain("\nMAIL_HOST=\n")
        ->toContain("\nSENTRY_TRACES_SAMPLE_RATE=0.10\n");
});

it('says in the generated files that they are generated', function (string $target) {
    expect(File::get(base_path("infrastructure/templates/environment/{$target}.env.example")))
        ->toContain('# GENERATED FROM infrastructure/config/environment-contract.json')
        ->toContain('# DO NOT EDIT THIS FILE DIRECTLY');
})->with(['staging', 'tits-guru']);

it('documents the first-party settings a local developer needs', function (string $key) {
    // .env.example is deliberately NOT generated: it serves local development and
    // legitimately carries local, build and provider settings a deployed target
    // has no use for. What it must not do is omit a first-party setting.
    expect(File::get(base_path('.env.example')))->toContain("\n{$key}=");
})->with([
    'MEDIA_VARIANT_LOCK_WAIT_SECONDS', 'MEDIA_VARIANT_LOCK_TTL_SECONDS', 'MEDIA_PURGE_LOCK_TTL_SECONDS',
    'UPLOAD_IMAGE_MAX_KB', 'UPLOAD_IMAGE_MAX_PIXELS', 'UPLOAD_IMAGE_WEBP_QUALITY',
    'IMPORT_FROM_URL_ENABLED',
    'RATE_LIMIT_UPLOAD_ATTEMPTS', 'RATE_LIMIT_COMMENT_ATTEMPTS',
    'RATE_LIMIT_REPORT_ATTEMPTS', 'RATE_LIMIT_VOTE_ATTEMPTS',
]);

// =============================================================================
// Closing the ways around the inventory
// =============================================================================

it('forbids getenv() in application config, so there is one way in', function () {
    // The inventory understands env() and the registered wrappers. getenv() would
    // reach the same variables and be invisible to it — so rather than teaching
    // the scanner a second mechanism, config/ is held to one. Laravel's own
    // convention is env() anyway, and one way in is what makes the inventory
    // closed.
    $offenders = [];

    foreach (completenessConfigFiles() as $path) {
        foreach (token_get_all(File::get($path)) as $token) {
            // Qualified or not: `\getenv()` is the same function, and a reader
            // that only knew the bare form would let one backslash past.
            if (completenessCallsFunction($token, 'getenv')) {
                $offenders[] = 'config/'.basename($path).':'.$token[2];
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'getenv() in application config bypasses the environment inventory:',
        ...array_map(fn (string $site): string => '  '.$site, $offenders),
        '',
        'Use env(), or one of the wrappers registered in',
        'infrastructure/config/environment-contract.json under config_env_readers.',
    ]));
});

it('requires a secret-like name to be marked sensitive and rendered blank', function () {
    // sensitive: true is a human judgement, and the contract is now the source of
    // rendered values — so a key whose NAME says credential must not depend on
    // somebody remembering. The indicators are deliberately conservative: an
    // OAuth client ID is a public identifier and is not caught by them, while
    // anything named password, secret, token, key, credential or dsn is.
    $indicators = ['PASSWORD', 'PASSWD', 'SECRET', 'TOKEN', 'PRIVATE_KEY', 'CREDENTIAL', 'DSN', 'API_KEY', 'ACCESS_KEY'];

    $unmarked = [];
    $valued = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (($entry['classification'] ?? null) !== 'target') {
            continue;
        }

        $looksSecret = false;

        foreach ($indicators as $indicator) {
            if (str_contains($entry['key'], $indicator)) {
                $looksSecret = true;

                break;
            }
        }

        // APP_KEY and the like: a bare _KEY suffix counts, but CLIENT_ID does not.
        if (! $looksSecret && preg_match('/(^|_)KEY$/', $entry['key']) === 1) {
            $looksSecret = true;
        }

        if (! $looksSecret) {
            continue;
        }

        if (($entry['sensitive'] ?? false) !== true) {
            $unmarked[] = $entry['key'];
        }

        if (($entry['value'] ?? null) !== '') {
            $valued[] = $entry['key'];
        }
    }

    expect($unmarked)->toBe([], 'these names indicate a credential and must carry "sensitive": true — '.implode(' ', $unmarked));
    expect($valued)->toBe([], 'these credentials must render blank, never with a committed value — '.implode(' ', $valued));
});

it('does not treat a public identifier as a secret', function () {
    // The guard above must stay conservative in the other direction too: marking a
    // public value sensitive would force it blank and break the target it belongs
    // to. An OAuth client id is published to every browser that starts a login.
    foreach (['GOOGLE_CLIENT_ID', 'FACEBOOK_CLIENT_ID'] as $key) {
        $entry = collect(completenessContract()['keys'])->firstWhere('key', $key);

        expect($entry)->not->toBeNull();
        expect($entry['sensitive'] ?? false)->toBeFalse("{$key} is a public identifier, not a secret");
    }
});

// =============================================================================
// Schema integrity
// =============================================================================

it('names every section once, and every target key belongs to one that exists', function () {
    $contract = completenessContract();
    $names = array_column($contract['sections'], 'name');

    expect($names)->toBe(array_values(array_unique($names)), 'section names must be unique');

    $orphans = [];

    foreach ($contract['keys'] as $entry) {
        if (($entry['classification'] ?? null) !== 'target') {
            continue;
        }

        if (! in_array($entry['section'] ?? '', $names, true)) {
            $orphans[] = $entry['key'].' => '.($entry['section'] ?? '(none)');
        }
    }

    expect($orphans)->toBe([], 'these target keys name a section that does not exist: '.implode(' ', $orphans));
});

it('uses a valid environment variable name for every target key', function () {
    $invalid = [];

    foreach (completenessContract()['keys'] as $entry) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $entry['key'] ?? '') !== 1) {
            $invalid[] = var_export($entry['key'] ?? null, true);
        }
    }

    expect($invalid)->toBe([], 'not usable as environment variable names: '.implode(' ', $invalid));
});

it('gives every registered target exactly one template, and no two share it', function () {
    // The renderer writes one file per target. Two targets naming one file would
    // have the second overwrite the first, and the target that lost would be
    // deployed against a contract describing somebody else.
    $targets = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true)['targets'];

    $declared = [];

    foreach ($targets as $id => $target) {
        expect($target['environment_template'] ?? null)->not->toBeNull("{$id} declares no environment_template");
        $declared[] = $target['environment_template'];
    }

    expect($declared)->toBe(array_values(array_unique($declared)), 'two targets share an environment template');

    // Enforced by the registry's own validator, not only here.
    expect(File::get(base_path('infrastructure/scripts/targets')))
        ->toContain('.environment_template|environment template');
});

it('validates the registry before it is willing to render anything', function () {
    // A glob is not a boundary: `infrastructure/templates/environment/*.env.example`
    // is matched by `.../environment/../../escaped.env.example`, because `*` spans
    // `/../`. CI would catch an invalid registry, but --write can be run locally
    // first — so the renderer proves it itself, in both modes, before writing.
    $source = executableSourceLines(File::get(completenessRenderer()));

    expect($source)
        ->toContain('"${TARGETS_CLI}" validate --file "${REGISTRY_FILE}"')
        ->toContain('assert_destination_within_templates');

    // The destination is reconstructed from its own basename and required to equal
    // what was declared, which nothing with a path segment in the middle survives.
    expect($source)->toContain('"${declared}" == "infrastructure/templates/environment/${base}"');
});

it('recognises an environment read in every form PHP accepts', function (string $call, string $expected) {
    // A leading namespace separator is the same function and a different token:
    // `env()` is T_STRING, `\env()` is T_NAME_FULLY_QUALIFIED. A reader that knew
    // only the first would let one backslash walk a key straight past the
    // inventory, which is the whole point of having one.
    $source = "<?php\nreturn ['probe' => {$call}];\n";
    $tokens = token_get_all($source);

    $found = null;

    foreach ($tokens as $index => $token) {
        if (completenessCallName($token) === 'env') {
            $found = completenessFirstArgument($tokens, $index);

            break;
        }
    }

    expect($found)->toBe($expected, "{$call} was not inventoried");
})->with([
    "env('SINGLE_QUOTED')" => ["env('SINGLE_QUOTED')", 'SINGLE_QUOTED'],
    'env("DOUBLE_QUOTED")' => ['env("DOUBLE_QUOTED")', 'DOUBLE_QUOTED'],
    "\\env('QUALIFIED_SINGLE')" => ["\\env('QUALIFIED_SINGLE')", 'QUALIFIED_SINGLE'],
    '\\env("QUALIFIED_DOUBLE")' => ['\\env("QUALIFIED_DOUBLE")', 'QUALIFIED_DOUBLE'],
]);

it('rejects getenv in every form PHP accepts', function (string $call) {
    // Same normalization on the prohibition side: one backslash must not turn a
    // forbidden call into an invisible one.
    $tokens = token_get_all("<?php\nreturn ['probe' => {$call}];\n");

    $detected = false;

    foreach ($tokens as $token) {
        if (completenessCallsFunction($token, 'getenv')) {
            $detected = true;

            break;
        }
    }

    expect($detected)->toBeTrue("{$call} would bypass the getenv prohibition");
})->with([
    "getenv('BARE')" => ["getenv('BARE')"],
    '\\getenv("QUALIFIED")' => ['\\getenv("QUALIFIED")'],
]);

it('leaves every committed template untouched when a render fails', function () {
    // --write used to redirect straight at the committed file, which truncates it
    // before the renderer has produced a byte. A render that then failed left the
    // repository holding a half-written template — and the operator who ran
    // --write to fix something had broken the thing they were fixing.
    //
    // So every target renders into a private file first and the committed files are
    // replaced only once ALL of them succeeded.
    $templates = [];

    foreach (['staging', 'tits-guru'] as $target) {
        $path = base_path("infrastructure/templates/environment/{$target}.env.example");
        $templates[$path] = [File::get($path), filemtime($path), fileperms($path)];
    }

    $contractPath = base_path('infrastructure/config/environment-contract.json');
    $contract = File::get($contractPath);

    try {
        // A value shape the renderer cannot evaluate: it fails mid-run, after the
        // first target would have been written under the old model.
        $broken = json_decode($contract, true, 512, JSON_THROW_ON_ERROR);

        foreach ($broken['keys'] as $index => $entry) {
            if (($entry['classification'] ?? null) === 'target') {
                $broken['keys'][$index]['value'] = ['by_something_nobody_implemented' => ['x' => 'y']];

                break;
            }
        }

        file_put_contents($contractPath, json_encode($broken, JSON_PRETTY_PRINT));

        [$exit, $output] = completenessRender('--write');

        expect($exit)->not->toBe(0, 'a render that cannot evaluate the contract must fail');
        expect($output)->toContain('no committed template was changed');

        clearstatcache();

        foreach ($templates as $path => [$content, $mtime, $perms]) {
            expect(File::get($path))->toBe($content, basename($path).' was modified by a failed render');
            expect(filemtime($path))->toBe($mtime, basename($path).' was touched by a failed render');
            expect(fileperms($path))->toBe($perms, basename($path).' lost its mode');
        }
    } finally {
        file_put_contents($contractPath, $contract);

        foreach ($templates as $path => [$content]) {
            file_put_contents($path, $content);
        }
    }
});

it('installs a rendered template as 0644', function () {
    // The mode is set as the file lands, by install(1), rather than fixed
    // afterwards — so there is no window where a generated template exists with
    // the wrong one.
    expect(executableSourceLines(File::get(completenessRenderer())))
        ->toContain('install -m 0644 "${source}" "${target}"');

    foreach (['staging', 'tits-guru'] as $target) {
        $path = base_path("infrastructure/templates/environment/{$target}.env.example");

        expect(substr(sprintf('%o', fileperms($path)), -3))->toBe('644', basename($path).' must be 0644');
    }
});
