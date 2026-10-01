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
function completenessPolicy(): array
{
    return json_decode(
        File::get(base_path('infrastructure/config/environment-contract-policy.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
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
        $readers = completenessPolicy()['config_env_readers'][$relative] ?? [];

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

/** The callee name for a token that starts a call, or null when it is not one. */
function completenessCallName(array|string $token): ?string
{
    if (! is_array($token)) {
        return null;
    }

    if ($token[0] === T_STRING && $token[1] === 'env') {
        return 'env';
    }

    if ($token[0] === T_VARIABLE) {
        return $token[1];
    }

    return null;
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
    $policy = completenessPolicy()['not_in_deployment_contract'];

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
        'Add each to infrastructure/templates/environment/*.env.example if a target must supply it,',
        'or to infrastructure/config/environment-contract-policy.json with a category if it must not.',
    ]));
});

it('carries no stale exception for a variable config/ no longer reads', function () {
    // The other direction, which is what keeps the file honest rather than
    // ever-growing: an exception nobody needs is a decision nobody is reviewing.
    $inventory = completenessInventory()['keys'];
    $policy = completenessPolicy()['not_in_deployment_contract'];

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
    $policy = completenessPolicy()['not_in_deployment_contract'];

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
        'infrastructure/config/environment-contract-policy.json under config_env_readers.',
    ]));
});

it('declares a reader only for a file that has one', function () {
    // A declared reader is permission for a dynamic env(); permission for a file
    // that no longer needs it is permission nobody is looking at.
    $readers = completenessPolicy()['config_env_readers'];

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
                if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['env', 'getenv'], true)) {
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

    $recorded = completenessPolicy()['read_outside_config'];
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
    $policy = completenessPolicy();
    $categories = array_keys($policy['categories']);
    $used = array_values(array_unique(array_values($policy['not_in_deployment_contract'])));

    sort($categories);
    sort($used);

    expect($used)->toBe($categories, 'every category must be used, and every category used must be described');

    foreach ($policy['categories'] as $name => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(60, "category {$name} needs a reason, not a label");
    }
});

it('contains key names and reasons only, never a value', function () {
    // The file is committed and names settings; it must not become a place a
    // credential is recorded "just as an example".
    $raw = File::get(base_path('infrastructure/config/environment-contract-policy.json'));

    foreach (['not_in_deployment_contract' => null] as $block => $_) {
        foreach (completenessPolicy()[$block] as $key => $category) {
            expect($key)->toMatch('/^[A-Z][A-Z0-9_]*$/', "policy key is not a variable name: {$key}");
            expect($category)->toMatch('/^[a-z][a-z0-9-]*$/', "policy category is not a slug: {$category}");
        }
    }

    // No KEY=VALUE anywhere in the file, which is the shape a leaked value takes.
    expect($raw)->not->toMatch('/[A-Z][A-Z0-9_]*=\S/');
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
