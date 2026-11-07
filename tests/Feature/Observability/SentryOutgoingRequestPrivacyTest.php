<?php

use App\Support\Observability\SentryPrivacy;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Tracing\Span;

/*
 * An outgoing request reaches Sentry without its query string or fragment:
 * the application fetches links people paste, and a presigned link's
 * signature, or a share token, lives there. An exception reaches it without
 * the value a unique violation names.
 */

it('records an outgoing request\'s breadcrumb without its query string or fragment', function () {
    // As the SDK's HTTP client integration records one.
    $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, [
        'url' => 'https://img.example/photo.jpg',
        'http.request.method' => 'GET',
        'http.query' => 'sig=SECRET-SIGNATURE',
        'http.fragment' => 'token=SECRET-FRAGMENT',
    ]);

    expect(SentryPrivacy::beforeBreadcrumb($breadcrumb)->getMetadata())->toBe([
        'url' => 'https://img.example/photo.jpg',
        'http.request.method' => 'GET',
        'http.query' => '[Filtered]',
        'http.fragment' => '[Filtered]',
    ]);
});

it('passes every other breadcrumb through as it is', function () {
    $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'db.sql.query', 'select 1', ['connectionName' => 'pgsql']);

    expect(SentryPrivacy::beforeBreadcrumb($breadcrumb))->toBe($breadcrumb);
});

it('sends a transaction\'s request spans without the address beyond the path', function () {
    $span = new Span;
    $span->setData(['http.query' => 'sig=SECRET', 'http.fragment' => 'token=SECRET', 'url.full' => 'https://img.example/a.jpg?sig=SECRET', 'http.request.method' => 'GET']);
    $event = Event::createTransaction();
    $event->setSpans([$span]);

    $sent = SentryPrivacy::beforeSendTransaction($event);

    expect($sent->getSpans()[0]->getData())->toBe([
        'http.query' => '[Filtered]', 'http.fragment' => '[Filtered]', 'url.full' => '[Filtered]', 'http.request.method' => 'GET',
    ]);
});

it('sends an exception without the value a unique violation names, keeping its column', function (string $message, string $value) {
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException($message))]);

    $sent = SentryPrivacy::beforeSend($event)->getExceptions()[0]->getValue();

    expect($sent)->not->toContain($value)
        ->and($sent)->toContain(str_contains($message, 'Key (')
            ? 'Key (email)=([Filtered]) already exists.'
            : "Duplicate entry '[Filtered]' for key 'users.users_email_unique'");
})->with([
    'postgres' => ['SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique" DETAIL: Key (email)=(ada@example.invalid) already exists.', 'ada@example.invalid'],
    'mysql' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ada@example.invalid' for key 'users.users_email_unique'", 'ada@example.invalid'],
]);

it('is the callback the Sentry client is built with', function () {
    expect(config('sentry.before_send'))->toBe([SentryPrivacy::class, 'beforeSend'])
        ->and(config('sentry.before_breadcrumb'))->toBe([SentryPrivacy::class, 'beforeBreadcrumb'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryPrivacy::class, 'beforeSendTransaction']);
});
