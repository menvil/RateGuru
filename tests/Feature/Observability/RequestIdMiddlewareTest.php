<?php

it('adds request id response header', function () {
    $this->get(route('feed'))
        ->assertHeader('X-Request-Id');
});

it('uses incoming request id header when valid', function () {
    $this->withHeader('X-Request-Id', 'test-request-id-abc')
        ->get(route('feed'))
        ->assertHeader('X-Request-Id', 'test-request-id-abc');
});

it('generates new request id when incoming header is missing', function () {
    $response = $this->get(route('feed'));

    $response->assertHeader('X-Request-Id');
    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('replaces invalid request id header', function () {
    $response = $this->withHeader('X-Request-Id', str_repeat('x', 300))
        ->get(route('feed'));

    $response->assertHeader('X-Request-Id');
    $id = $response->headers->get('X-Request-Id');
    expect(strlen($id))->toBeLessThan(200);
    expect($id)->not->toBe(str_repeat('x', 300));
});
