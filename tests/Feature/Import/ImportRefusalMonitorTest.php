<?php

use App\Exceptions\Import\ImportSiteRefusingException;
use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use App\Support\Import\ImportRefusalMonitor;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Exceptions::fake();
});

it('reports a site\'s refusal from the first link, with the link, each different link once', function () {
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    // The same link again — a retry, another author — is reported once.
    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');

    Exceptions::assertReported(fn (ImportSiteRefusingException $exception): bool => $exception->getMessage() === 'instagram.com refused https://www.instagram.com/p/one/ (HTTP 429); it may be blocking this server.');
    Exceptions::assertReportedCount(1);

    // Another link of the site within the same window: reported too, for
    // the error tracker to gather into the same issue.
    $monitor->record('https://instagram.com/p/two/', 'HTTP 403');

    Exceptions::assertReportedCount(2);
});

it('reports the link without its query, fragment or credentials', function () {
    app(ImportRefusalMonitor::class)->record('https://user:secret@cdn.example.com:8443/v/clip.mp4?token=abc#t=3', 'HTTP 403');

    Exceptions::assertReported(fn (ImportSiteRefusingException $exception): bool => $exception->getMessage() === 'cdn.example.com refused https://cdn.example.com:8443/v/clip.mp4 (HTTP 403); it may be blocking this server.');
});

it('sends nothing to anyone\'s notifications: the error tracker is where a refusal is read', function () {
    Notification::fake();
    User::factory()->admin()->create();

    app(ImportRefusalMonitor::class)->record('https://www.instagram.com/p/one/', 'HTTP 429');

    Notification::assertNothingSent();
});

it('waits for as many different links of one site as configured, should single refusals be too noisy', function () {
    config(['import.refusal_alert' => ['distinct_links' => 2, 'window_seconds' => 3600]]);
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    $monitor->record('https://x.com/a/status/1', 'HTTP 429');

    Exceptions::assertNothingReported();

    $monitor->record('https://www.instagram.com/p/two/', 'HTTP 429');

    Exceptions::assertReportedCount(1);
});

it('tells the author a page the site refused is to be uploaded instead, and one the site says is not there that it cannot be imported, as no error of its own', function (int $status, string $message) {
    bindFakeHostResolver(['news.example.org' => ['93.184.216.40']]);
    Http::fake(['news.example.org/*' => Http::response('', $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A story')
        ->set('importedImageUrl', 'https://news.example.org/story')
        ->call('submit')
        ->assertSet('submitError', __($message));

    // The form reports nothing of its own: a refusal is the monitor's to report.
    Exceptions::assertNotReported(fn (Throwable $exception): bool => ! $exception instanceof ImportSiteRefusingException);
})->with([
    'too many requests' => [429, 'import.errors.site_refusing'],
    'forbidden' => [403, 'import.errors.site_refusing'],
    'not found' => [404, 'import.errors.unsupported'],
    'gone' => [410, 'import.errors.unsupported'],
]);
