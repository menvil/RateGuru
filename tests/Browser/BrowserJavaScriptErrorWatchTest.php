<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The JavaScript error watch every Browser test runs under (tests/Pest.php):
 * proved here against errors raised on purpose, so that a watch which stopped
 * seeing them would fail this file rather than let every other one pass.
 *
 * Each test ends by letting go of the errors it raised: the watch's own
 * after-each assertion would otherwise, and rightly, fail it.
 */

/** @return list<string> "kind: message" for everything the watch has collected so far */
function watchedJavaScriptErrors(ArrayObject $errors): array
{
    return array_map(fn (array $error): string => "{$error['kind']}: {$error['message']}", array_values($errors->getArrayCopy()));
}

it('collects an error, a rejection and a console error, across a navigation', function () {
    $page = visit(route('feed'));
    $page->script("() => setTimeout(() => { throw new Error('watched error') }, 0)");
    $page->script("() => { Promise.reject(new Error('watched rejection')); return true; }");
    // The page navigates itself. The plugin's navigate() retries a page load
    // that takes longer than a second, and a retry interrupts the load it is
    // retrying; a navigation the page starts is never started twice.
    $page->script("() => { location.href = '/login'; return true; }");
    waitForScript($page, "location.pathname + ' ' + document.readyState", '/login complete');
    $page->script("() => { console.error('watched console error'); return true; }");

    // Each one exactly once, whatever the navigation did to a report in flight.
    eventually(function () {
        $collected = watchedJavaScriptErrors($this->browserJavaScriptErrors);

        expect($collected)->toHaveCount(3)
            ->toContain('error: Uncaught Error: watched error', 'console.error: watched console error')
            ->and(implode("\n", $collected))->toContain('unhandledrejection: Error: watched rejection');
    });

    $this->browserJavaScriptErrors->exchangeArray([]);
});

it('watches a page whose head tag has attributes', function () {
    Route::get('__browser-test/head-with-attributes', fn () => response(
        '<!doctype html><html><head prefix="og: https://ogp.me/ns#"><title>Attributes</title></head><body>Attributes</body></html>',
    )->header('Content-Type', 'text/html; charset=UTF-8'));

    $page = visit('/__browser-test/head-with-attributes');
    $page->script("() => setTimeout(() => { throw new Error('watched under an attributed head') }, 0)");

    eventually(fn () => expect(watchedJavaScriptErrors($this->browserJavaScriptErrors))
        ->toBe(['error: Uncaught Error: watched under an attributed head']));

    $this->browserJavaScriptErrors->exchangeArray([]);
});

it('sends a report again when the server does not take it the first time', function () {
    // The watch's route, answering its first report with a server error.
    $attempts = 0;
    $errors = $this->browserJavaScriptErrors;
    Route::post('__browser-test/javascript-errors', function (Request $request) use (&$attempts, $errors) {
        if (++$attempts === 1) {
            return response('', 500);
        }

        $errors[(string) $request->input('id')] = array_map('strval', $request->only(['kind', 'message', 'source', 'page']));

        return response()->noContent();
    });

    $page = visit(route('feed'));
    $page->script("() => setTimeout(() => { throw new Error('watched after a refusal') }, 0)");

    eventually(fn () => expect(watchedJavaScriptErrors($this->browserJavaScriptErrors))
        ->toBe(['error: Uncaught Error: watched after a refusal']));
    expect($attempts)->toBe(2, 'refused once, then taken');

    $this->browserJavaScriptErrors->exchangeArray([]);
});
