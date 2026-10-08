<?php

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;

/*
 * TEMPORARY diagnostics, never to be merged: why a same-tab navigation from the
 * feed to /login intermittently ends on Chromium's error page in CI, although
 * the server answered GET /login with a 200.
 */

/** Records "time METHOD path status" for every request the application handles. */
function diagnoseServedRequests(): ArrayObject
{
    $served = new ArrayObject;

    Event::listen(RequestHandled::class, function (RequestHandled $event) use ($served): void {
        $served[] = sprintf('%.3f %s %s %d', microtime(true), $event->request->method(), $event->request->getPathInfo(), $event->response->getStatusCode());
    });

    return $served;
}

function diagnoseReport(mixed $page, ArrayObject $served, float $started): string
{
    try {
        // The error page replaces the document a moment after the navigation fails.
        $seen = eventually(fn () => $page->page()->evaluate("JSON.stringify({ href: location.href, readyState: document.readyState, html: document.documentElement.outerHTML.slice(0, 300), nav: performance.getEntriesByType('navigation').map((n) => ({ name: n.name, type: n.type, status: n.responseStatus, transfer: n.transferSize, decoded: n.decodedBodySize, dur: Math.round(n.duration) })) })"), 2.0);
    } catch (Throwable $e) {
        $seen = 'evaluate failed: '.$e->getMessage();
    }

    return sprintf('started=%.3f now=%.3f playwright-url=%s page=%s served=%s', $started, microtime(true), $page->page()->url(), $seen, json_encode($served->getArrayCopy()));
}

it('navigates from the feed to /login with a single goto', function () {
    $served = diagnoseServedRequests();
    $page = visit(route('feed'));
    $page->script("() => setTimeout(() => { throw new Error('watched error') }, 0)");
    $page->script("() => { Promise.reject(new Error('watched rejection')); return true; }");
    eventually(fn () => expect($this->browserJavaScriptErrors)->toHaveCount(2));

    $started = microtime(true);

    try {
        $page->page()->goto(route('login'), ['timeout' => 15_000]);
    } catch (Throwable $e) {
        throw new RuntimeException('DIAG goto failed: '.$e->getMessage().' | '.diagnoseReport($page, $served, $started), 0, $e);
    }

    $this->browserJavaScriptErrors->exchangeArray([]);
})->repeat(3);

it('navigates from the feed to /login by setting location.href', function () {
    $served = diagnoseServedRequests();
    $page = visit(route('feed'));
    $page->script("() => setTimeout(() => { throw new Error('watched error') }, 0)");
    $page->script("() => { Promise.reject(new Error('watched rejection')); return true; }");
    eventually(fn () => expect($this->browserJavaScriptErrors)->toHaveCount(2));

    $started = microtime(true);
    navigatePageTo($page, '/login');

    try {
        waitForScript($page, "location.pathname + ' ' + document.readyState", '/login complete', 15.0);
    } catch (Throwable $e) {
        throw new RuntimeException('DIAG location.href failed: '.$e->getMessage().' | '.diagnoseReport($page, $served, $started), 0, $e);
    }

    $this->browserJavaScriptErrors->exchangeArray([]);
})->repeat(3);
