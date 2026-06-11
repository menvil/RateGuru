<?php

use Illuminate\Support\Facades\Log;

it('shares request context with logger', function () {
    Log::spy();

    $this->get(route('feed'));

    Log::shouldHaveReceived('withContext')->atLeast()->once();
});

it('includes request id in shared log context', function () {
    Log::spy();

    $this->withHeader('X-Request-Id', 'test-log-ctx-id')
        ->get(route('feed'));

    Log::shouldHaveReceived('withContext')->withArgs(function ($context) {
        return isset($context['request_id']);
    })->atLeast()->once();
});
