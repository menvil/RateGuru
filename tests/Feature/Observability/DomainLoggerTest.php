<?php

use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\Log;

it('logs domain event with structured context', function () {
    Log::spy();

    app(DomainLogger::class)->info('posts.created', [
        'post_id' => 123,
    ]);

    Log::shouldHaveReceived('info')
        ->with('posts.created', Mockery::on(fn ($context) => isset($context['post_id'])));
});

it('logs warning domain event', function () {
    Log::spy();

    app(DomainLogger::class)->warning('url_import.preview.failed', [
        'reason' => 'timeout',
    ]);

    Log::shouldHaveReceived('warning')
        ->with('url_import.preview.failed', Mockery::on(fn ($context) => isset($context['reason'])));
});

it('logs error domain event', function () {
    Log::spy();

    app(DomainLogger::class)->error('notifications.followed_author_posted.failed', [
        'follower_id' => 5,
    ]);

    Log::shouldHaveReceived('error')
        ->with('notifications.followed_author_posted.failed', Mockery::any());
});

it('tags security events with event_type', function () {
    Log::spy();

    app(DomainLogger::class)->security('security.unsafe_url_blocked', [
        'host' => 'localhost',
    ]);

    Log::shouldHaveReceived('warning')
        ->with('security.unsafe_url_blocked', Mockery::on(
            fn ($context) => ($context['event_type'] ?? null) === 'security'
        ));
});

it('merges base log context into domain events', function () {
    Log::spy();

    app(DomainLogger::class)->info('posts.created', ['post_id' => 1]);

    Log::shouldHaveReceived('info')
        ->with('posts.created', Mockery::on(fn ($context) => isset($context['request_id'])));
});

it('redacts sensitive data in domain event context', function () {
    Log::spy();

    app(DomainLogger::class)->info('posts.created', [
        'post_id' => 1,
        'password' => 'leaked',
    ]);

    Log::shouldHaveReceived('info')
        ->with('posts.created', Mockery::on(
            fn ($context) => ($context['password'] ?? null) === '[redacted]'
        ));
});
