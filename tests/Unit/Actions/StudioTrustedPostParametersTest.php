<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * A trip-wire for the trusted parameters only Content Studio's executor may
 * give: CreatePostData's stored image and dates (`imageAssetId`, `createdAt`,
 * `updatedAt`) and ApprovePostAction's publication date (`publishedAt`, its
 * fourth argument). Nothing a visitor or an administrator's screen sends may
 * reach them — not by name, not by position, not by spreading a request's
 * array into the data. The source is read token by token, its comments left
 * out, so a file that only mentions them in prose is not one that uses them.
 */
const STUDIO_TRUSTED_POST_PARAMETERS = ['imageAssetId', 'createdAt', 'updatedAt', 'publishedAt'];

const STUDIO_EXECUTOR = 'Actions/Studio/ExecuteStudioAction.php';

/**
 * Every PHP file under app/, keyed by its path relative to it, as its tokens
 * without comments or whitespace.
 *
 * @return array<string, list<PhpToken>>
 */
function appSourceTokens(): array
{
    $sources = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen(app_path()) + 1);
        $sources[$relative] = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file->getPathname())),
            fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]),
        ));
    }

    return $sources;
}

/**
 * The tokens of each call's argument list that follows `$opening`, the
 * parenthesis included — for `new CreatePostData(` or `->handle(`.
 *
 * @param  list<PhpToken>  $tokens
 * @param  Closure(list<PhpToken>, int): bool  $opens  whether the call starts at this index
 * @return list<list<PhpToken>>
 */
function callArguments(array $tokens, Closure $opens): array
{
    $calls = [];

    foreach ($tokens as $index => $token) {
        if (! $opens($tokens, $index)) {
            continue;
        }

        $depth = 0;
        $arguments = [];

        for ($at = $index; $at < count($tokens); $at++) {
            $arguments[] = $tokens[$at];
            $depth += match ($tokens[$at]->text) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };

            if ($depth === 0 && $tokens[$at]->text === ')') {
                break;
            }
        }

        $calls[] = $arguments;
    }

    return $calls;
}

/**
 * How many arguments a call's list holds, counting the commas at its own level.
 *
 * @param  list<PhpToken>  $arguments  from the opening parenthesis to the closing one
 */
function argumentCount(array $arguments): int
{
    if (count($arguments) <= 2) {
        return 0;
    }

    $depth = 0;
    $commas = 0;

    foreach ($arguments as $token) {
        $depth += match ($token->text) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };

        if ($depth === 1 && $token->text === ',') {
            $commas++;
        }
    }

    // A trailing comma closes the list rather than opening another argument.
    return $arguments[count($arguments) - 2]->text === ',' ? $commas : $commas + 1;
}

/**
 * Whether a token names the class, however it is written: `CreatePostData`,
 * `Posts\CreatePostData` or `\App\Data\Posts\CreatePostData`.
 */
function namesClass(?PhpToken $token, string $class): bool
{
    if ($token === null || ! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return false;
    }

    $segments = explode('\\', $token->text);

    return end($segments) === $class;
}

/**
 * Where each call to ApprovePostAction's `handle(` opens, however the file
 * holds the action: resolved there (`app(ApprovePostAction::class)->handle(`),
 * or under any name it types as one — `ApprovePostAction $approve` gives
 * `$this->approve->handle(` and `$approve->handle(`.
 *
 * @param  list<PhpToken>  $tokens
 * @return Closure(list<PhpToken>, int): bool
 */
function opensApproval(array $tokens): Closure
{
    $names = [];

    foreach ($tokens as $index => $token) {
        if (namesClass($token, 'ApprovePostAction') && ($tokens[$index + 1] ?? null)?->is(T_VARIABLE)) {
            $names[] = ltrim($tokens[$index + 1]->text, '$');
        }
    }

    return fn (array $all, int $at): bool => $all[$at]->text === '('
        && ($all[$at - 1]->text ?? null) === 'handle'
        && ($all[$at - 2] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
        && (
            // `…(ApprovePostAction::class)->handle(`
            (($all[$at - 3]->text ?? null) === ')' && ($all[$at - 4]->text ?? null) === 'class' && namesClass($all[$at - 6] ?? null, 'ApprovePostAction'))
            // `$this->approve->handle(`
            || (($all[$at - 3] ?? null)?->is(T_STRING) && in_array($all[$at - 3]->text, $names, true) && ($all[$at - 4] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]))
            // `$approve->handle(`
            || (($all[$at - 3] ?? null)?->is(T_VARIABLE) && in_array(ltrim($all[$at - 3]->text, '$'), $names, true))
        );
}

/** @param  list<PhpToken>  $arguments */
function namesTrustedParameter(array $arguments): bool
{
    foreach ($arguments as $index => $token) {
        if (in_array($token->text, STUDIO_TRUSTED_POST_PARAMETERS, true) && ($arguments[$index + 1]->text ?? null) === ':') {
            return true;
        }
    }

    return false;
}

/** @param  list<PhpToken>  $arguments */
function spreadsAnArray(array $arguments): bool
{
    return array_filter($arguments, fn (PhpToken $token): bool => $token->is(T_ELLIPSIS)) !== [];
}

it('lets only the executor and the upload form make a post\'s data, the form with none of the trusted fields and nothing spread into it', function () {
    $makers = [];

    foreach (appSourceTokens() as $path => $tokens) {
        $calls = callArguments($tokens, fn (array $all, int $at): bool => $all[$at]->text === '('
            && namesClass($all[$at - 1] ?? null, 'CreatePostData')
            && ($all[$at - 2]->text ?? null) === 'new');

        if ($calls === []) {
            continue;
        }

        $makers[] = $path;

        if ($path === STUDIO_EXECUTOR) {
            continue;
        }

        foreach ($calls as $arguments) {
            expect(namesTrustedParameter($arguments))->toBeFalse("{$path} gives CreatePostData a trusted field.")
                ->and(spreadsAnArray($arguments))->toBeFalse("{$path} spreads an array into CreatePostData, where a trusted field could ride along.")
                // Positionally, the trusted fields come after the eight a form fills.
                ->and(argumentCount($arguments))->toBeLessThanOrEqual(8, "{$path} gives CreatePostData a trusted field by position.");
        }
    }

    sort($makers);

    expect($makers)->toBe([STUDIO_EXECUTOR, 'Livewire/Feed/UploadPostForm.php']);
});

it('lets nothing but the executor give ApprovePostAction a publication date', function () {
    $approvers = [];

    foreach (appSourceTokens() as $path => $tokens) {
        if ($path === 'Actions/Moderation/ApprovePostAction.php'
            || array_filter($tokens, fn (PhpToken $token): bool => namesClass($token, 'ApprovePostAction')) === []) {
            continue;
        }

        $calls = callArguments($tokens, opensApproval($tokens));

        // A file that holds the action in a way this guard does not recognise
        // would escape it: it must be taught the new shape, not skip the file.
        expect($calls)->not->toBe([], "{$path} names ApprovePostAction, but no call to it is recognised there.");

        foreach ($calls as $arguments) {
            $approvers[] = $path;

            if ($path !== STUDIO_EXECUTOR) {
                expect(namesTrustedParameter($arguments))->toBeFalse("{$path} gives ApprovePostAction a publication date.")
                    ->and(spreadsAnArray($arguments))->toBeFalse("{$path} spreads an array into ApprovePostAction.")
                    ->and(argumentCount($arguments))->toBeLessThanOrEqual(3, "{$path} gives ApprovePostAction a publication date by position.");
            }
        }
    }

    expect(array_values(array_unique($approvers)))->toContain(STUDIO_EXECUTOR);
});

it('keeps every trusted name out of the screens a request reaches', function () {
    foreach (appSourceTokens() as $path => $tokens) {
        if (! preg_match('#^(Http|Livewire|Filament|View)/#', $path)) {
            continue;
        }

        expect(namesTrustedParameter($tokens))->toBeFalse("{$path} names a trusted post parameter.");
    }
});

it('catches what it is there to catch', function () {
    $tokens = fn (string $code): array => array_values(array_filter(
        PhpToken::tokenize("<?php {$code}"),
        fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]),
    ));
    $data = fn (string $code): array => callArguments($tokens($code), fn (array $all, int $at): bool => $all[$at]->text === '(' && ($all[$at - 1]->text ?? null) === 'CreatePostData')[0];

    expect(namesTrustedParameter($data('new CreatePostData(title: $t, createdAt: $at);')))->toBeTrue()
        ->and(namesTrustedParameter($data('new CreatePostData(title: $t, categoryId: $c);')))->toBeFalse()
        ->and(spreadsAnArray($data('new CreatePostData(...$request->validated());')))->toBeTrue()
        ->and(argumentCount($data('new CreatePostData($a, $b, $c, $d, $e, $f, $g, $h, $i);')))->toBe(9)
        ->and(argumentCount($data('new CreatePostData(title: fn ($x, $y) => [$x, $y],);')))->toBe(1)
        // A trusted name in a comment is no use of it.
        ->and(namesTrustedParameter($tokens('/* createdAt: now() */ $x = 1;')))->toBeFalse()
        // However the class is written.
        ->and(namesClass($tokens('new \\App\\Data\\Posts\\CreatePostData($t);')[2], 'CreatePostData'))->toBeTrue()
        ->and(namesClass($tokens('new Posts\\CreatePostData($t);')[2], 'CreatePostData'))->toBeTrue()
        ->and(namesClass($tokens('new CreatePostDataFactory($t);')[2], 'CreatePostData'))->toBeFalse();

    $approvals = fn (string $code): array => callArguments($tokens($code), opensApproval($tokens($code)));

    // The approval found however the action is held, and nothing else's handle(.
    expect($approvals('app(\\App\\Actions\\Moderation\\ApprovePostAction::class)->handle($m, $p, null, $at);'))->toHaveCount(1)
        ->and($approvals('function __construct(private ApprovePostAction $approve) {} $this->approve->handle($m, $p, null, $at);'))->toHaveCount(1)
        ->and($approvals('function run(ApprovePostAction $action) { $action->handle($m, $p, null, $at); }'))->toHaveCount(1)
        ->and(argumentCount($approvals('function run(ApprovePostAction $action) { $action->handle($m, $p, null, $at); }')[0]))->toBe(4)
        ->and($approvals('function run(ApprovePostAction $action, RejectPostAction $reject) { $reject->handle($m, $p, $r, $x); }'))->toBe([]);
});
