<?php

use Illuminate\Support\Facades\File;

/*
 * The Browser suite waits for states, never for time.
 *
 * A fixed pause is a guess at how long a transition, a debounce or a round trip
 * to the server takes: too short and the test fails on a busy runner, too long
 * and every run pays the whole guess. The suite once held 287 of them, about a
 * third of its running time. It now waits with waitForScript() and eventually(),
 * and the only pause left is proveNothingHappensFor(), which proves an absence
 * and has to say which.
 */

/**
 * Every fixed pause in $source: a ->wait() or ->waitForKey() call, or a sleep
 * function. Read from PHP's own tokens, so comments and strings do not count.
 *
 * @return list<string> "line N: what"
 */
function browserFixedPauses(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $pauses = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }

        $before = $tokens[$index - 1] ?? null;
        $name = strtolower(ltrim($token[1], '\\'));
        $method = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
        $declaredOrStatic = is_array($before) && in_array($before[0], [T_FUNCTION, T_DOUBLE_COLON], true);

        if ($method && in_array($name, ['wait', 'waitforkey'], true)) {
            $pauses[] = "line {$token[2]}: ->{$token[1]}()";
        }

        if (! $method && ! $declaredOrStatic && in_array($name, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until', 'browsertestpause', 'amp\\delay'], true)) {
            $pauses[] = "line {$token[2]}: {$token[1]}()";
        }
    }

    return $pauses;
}

it('finds a fixed pause in code, and only in code', function () {
    expect(browserFixedPauses(<<<'PHP'
        <?php
        $page->click('Save')->wait(0.5);
        $page?->wait(1);
        $page->wait();
        usleep(25_000);
        \sleep(1);
        browserTestPause(0.3);
        \Amp\delay(0.1);
        PHP))->toBe([
        'line 2: ->wait()',
        'line 3: ->wait()',
        'line 4: ->wait()',
        'line 5: usleep()',
        'line 6: \sleep()',
        'line 7: browserTestPause()',
        'line 8: \Amp\delay()',
    ]);

    expect(browserFixedPauses(<<<'PHP'
        <?php
        // $page->wait(0.5) used to be here.
        /** ->wait(1) */
        $page->script("el.wait(1); sleep(2)");
        waitForScript($page, 'document.readyState', 'complete');
        eventually(fn () => expect(true)->toBeTrue());
        proveNothingHappensFor($page, 0.3, 'no request to Livewire');
        $page->waitForEvent('load');
        PHP))->toBe([]);
});

it('keeps fixed pauses out of the Browser suite', function () {
    $pauses = collect(File::allFiles(base_path('tests/Browser')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->flatMap(fn ($file): array => array_map(
            fn (string $pause): string => $file->getRelativePathname().' '.$pause,
            browserFixedPauses($file->getContents()),
        ))
        ->values()
        ->all();

    expect($pauses)->toBe([], 'wait for the state the next step needs with waitForScript() or eventually(); a pause that proves something does not happen is proveNothingHappensFor()');
});
