<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * The wait every browser test leans on, against a page that gives set answers,
 * so what it does about a script that throws does not depend on timing.
 */

/** A page answering each script with the next answer, then with the last one for good. */
function pageAnswering(mixed ...$answers): object
{
    return new class($answers)
    {
        public int $asked = 0;

        /** @param  array<int, mixed>  $answers */
        public function __construct(private readonly array $answers) {}

        public function script(string $expression): mixed
        {
            $answer = $this->answers[min($this->asked++, count($this->answers) - 1)];

            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer;
        }
    };
}

it('keeps waiting through a script reading what is not there yet', function (string $error) {
    $page = pageAnswering(new ExpectationFailedException($error), new ExpectationFailedException($error), false, true);

    waitForScript($page, 'Alpine.$data(el).ready', true, 2.0);

    expect($page->asked)->toBe(4);
})->with([
    'not loaded' => 'ReferenceError: Alpine is not defined',
    'not drawn' => "TypeError: Cannot read properties of null (reading 'textContent')",
]);

it('names the error when what the script reads never arrives', function () {
    $page = pageAnswering(new ExpectationFailedException("ReferenceError: Alpine is not defined\n    at eval"));

    expect(fn () => waitForScript($page, 'Alpine.version', true, 0.1))
        ->toThrow(ExpectationFailedException::class, 'ReferenceError: Alpine is not defined');

    expect($page->asked)->toBeGreaterThan(1);
});

it('does not take a script error for the value null', function () {
    $page = pageAnswering(new ExpectationFailedException('ReferenceError: Alpine is not defined'));

    expect(fn () => waitForScript($page, 'Alpine.version', null, 0.1))
        ->toThrow(ExpectationFailedException::class, 'ReferenceError');
});

it('does not wait through a script that cannot run at all', function () {
    $page = pageAnswering(new ExpectationFailedException('SyntaxError: Unexpected identifier'));

    expect(fn () => waitForScript($page, 'this is not javascript', true, 30.0))
        ->toThrow(ExpectationFailedException::class, 'SyntaxError');

    expect($page->asked)->toBe(1);
});

it('still fails with the last value when the state is never reached', function () {
    $page = pageAnswering(false);

    expect(fn () => waitForScript($page, 'window.ready', true, 0.1))
        ->toThrow(ExpectationFailedException::class, '[window.ready] did not become true within 0.1s');
});
