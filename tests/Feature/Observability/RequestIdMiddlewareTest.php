<?php

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

it('keeps an inbound request id made of letters, digits, dashes and dots', function () {
    $response = $this->withHeader('X-Request-Id', 'edge-01.trace-7f3a')->get(route('feed'));

    expect($response->headers->get('X-Request-Id'))->toBe('edge-01.trace-7f3a');
});

it('replaces an inbound request id that ends in a line break', function () {
    $response = $this->withHeader('X-Request-Id', "forged-id\n")->get(route('feed'));

    expect($response->headers->get('X-Request-Id'))
        ->not->toContain("\n")
        ->not->toBe('forged-id');
});
