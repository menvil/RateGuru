<?php

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The server every Browser test runs the application under, as tests/Pest.php
 * sets it up: see keepBodilessResponsesOffKeepAliveConnections().
 */

it('closes the connection after a response that cannot have a body, and only then', function (int $status, ?string $connection) {
    $response = response('', $status);

    event(new RequestHandled(Request::create('/'), $response));

    expect($response->headers->get('Connection'))->toBe($connection);
})->with([
    'No Content' => [204, 'close'],
    'Not Modified' => [304, 'close'],
    'OK' => [200, null],
    'Accepted' => [202, null],
]);

it('tells the browser it closed the connection after a 204', function () {
    Route::get('__browser-test/no-content', fn () => response()->noContent());

    $page = visit('/login');

    expect($page->script("async () => { const response = await fetch('/__browser-test/no-content'); return response.status + ' ' + response.headers.get('connection'); }"))
        ->toBe('204 close');
});
