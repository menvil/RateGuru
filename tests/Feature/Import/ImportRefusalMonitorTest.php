<?php

use App\Exceptions\Import\ImportSiteRefusingException;
use App\Livewire\Feed\UploadPostForm;
use App\Models\User;
use App\Notifications\ImportSiteRefusingNotification;
use App\Support\Import\ImportRefusalMonitor;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Exceptions::fake();
    Notification::fake();
});

it('reports a site\'s refusal from the first link, each different link once, and tells every administrator once, no one else', function () {
    $admins = User::factory()->admin()->count(2)->create();
    $moderator = User::factory()->moderator()->create();
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    // The same link again — a retry, another author — counts once.
    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');

    Exceptions::assertReported(fn (ImportSiteRefusingException $exception): bool => $exception->getMessage() === 'instagram.com refused a link (HTTP 429); it may be blocking this server.');
    Exceptions::assertReportedCount(1);
    Notification::assertSentTo($admins, ImportSiteRefusingNotification::class, fn (ImportSiteRefusingNotification $notification, array $channels, User $admin): bool => $notification->toArray($admin)['message_params'] === ['site' => 'instagram.com']);
    Notification::assertNotSentTo($moderator, ImportSiteRefusingNotification::class);

    // Another link of the site within the same window: reported too, for
    // the error tracker to gather, but the administrators are told once.
    $monitor->record('https://instagram.com/p/two/', 'HTTP 403');

    Exceptions::assertReportedCount(2);
    Notification::assertCount(2);
});

it('waits for as many different links of one site as configured, should single refusals be too noisy', function () {
    config(['import.refusal_alert' => ['distinct_links' => 2, 'window_seconds' => 3600]]);
    User::factory()->admin()->create();
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    $monitor->record('https://x.com/a/status/1', 'HTTP 429');

    Exceptions::assertNothingReported();
    Notification::assertNothingSent();

    $monitor->record('https://www.instagram.com/p/two/', 'HTTP 429');

    Exceptions::assertReportedCount(1);
    Notification::assertCount(1);
});

it('says in the admin\'s notification which site', function () {
    $admin = User::factory()->admin()->create();

    expect(__('ui.notifications.messages.import_site_refusing', (new ImportSiteRefusingNotification('instagram.com'))->toArray($admin)['message_params']))
        ->toBe('instagram.com refused this server a file — it may be blocking downloads. Authors are asked to upload the file themselves.');
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
