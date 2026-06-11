<?php

use App\Support\Observability\LogEventName;

it('validates domain log event names', function () {
    expect(LogEventName::isValid('posts.created'))->toBeTrue();
    expect(LogEventName::isValid('url_import.preview.failed'))->toBeTrue();
    expect(LogEventName::isValid('User saved post'))->toBeFalse();
});

it('accepts valid dot-separated names', function () {
    expect(LogEventName::isValid('saved_posts.saved'))->toBeTrue();
    expect(LogEventName::isValid('follows.followed'))->toBeTrue();
    expect(LogEventName::isValid('notifications.followed_author_posted.sent'))->toBeTrue();
    expect(LogEventName::isValid('security.unsafe_url_blocked'))->toBeTrue();
    expect(LogEventName::isValid('profile.avatar.updated'))->toBeTrue();
});

it('rejects names with spaces', function () {
    expect(LogEventName::isValid('posts created'))->toBeFalse();
    expect(LogEventName::isValid('post.action name'))->toBeFalse();
});

it('rejects names without a dot', function () {
    expect(LogEventName::isValid('posts'))->toBeFalse();
});

it('rejects uppercase names', function () {
    expect(LogEventName::isValid('Posts.Created'))->toBeFalse();
    expect(LogEventName::isValid('POSTS.CREATED'))->toBeFalse();
});

it('rejects empty names', function () {
    expect(LogEventName::isValid(''))->toBeFalse();
});

it('domain logger throws on invalid event name', function () {
    expect(fn () => app(\App\Support\Observability\DomainLogger::class)->info('Invalid Event Name', []))
        ->toThrow(\App\Exceptions\Observability\InvalidLogEventNameException::class);
});
