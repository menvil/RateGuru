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
    config(['import.refusal_alert' => ['distinct_links' => 3, 'window_seconds' => 3600]]);
    Exceptions::fake();
    Notification::fake();
});

it('reports a site refusing many different links once, and tells every administrator, no one else', function () {
    $admins = User::factory()->admin()->count(2)->create();
    $moderator = User::factory()->moderator()->create();
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    // The same link again — a retry, another author — counts once.
    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    $monitor->record('https://instagram.com/p/two/', 'HTTP 429');

    Exceptions::assertNothingReported();
    Notification::assertNothingSent();

    $monitor->record('https://www.instagram.com/p/three/', 'HTTP 403');
    // Past the threshold within the same window: told once, not again.
    $monitor->record('https://www.instagram.com/p/four/', 'HTTP 403');

    Exceptions::assertReported(fn (ImportSiteRefusingException $exception): bool => str_starts_with($exception->getMessage(), 'instagram.com refused 3 different links'));
    Exceptions::assertReportedCount(1);
    Notification::assertSentTo($admins, ImportSiteRefusingNotification::class, fn (ImportSiteRefusingNotification $notification, array $channels, User $admin): bool => $notification->toArray($admin)['message_params'] === ['site' => 'instagram.com', 'count' => 3]);
    Notification::assertNotSentTo($moderator, ImportSiteRefusingNotification::class);
    Notification::assertCount(2);
});

it('counts each site apart', function () {
    User::factory()->admin()->create();
    $monitor = app(ImportRefusalMonitor::class);

    $monitor->record('https://www.instagram.com/p/one/', 'HTTP 429');
    $monitor->record('https://x.com/a/status/1', 'HTTP 429');
    $monitor->record('https://www.youtube.com/watch?v=abc', 'HTTP 429');

    Exceptions::assertNothingReported();
    Notification::assertNothingSent();
});

it('says in the admin\'s notification which site and how many links', function () {
    $admin = User::factory()->admin()->create();

    expect(__('ui.notifications.messages.import_site_refusing', (new ImportSiteRefusingNotification('instagram.com', 5))->toArray($admin)['message_params']))
        ->toBe('instagram.com seems to be blocking downloads: it refused 5 different links lately. Authors are asked to upload the file themselves.');
});

it('tells the author a page the site refused is to be uploaded instead, and one the site says is not there that it cannot be imported — reporting neither', function (int $status, string $message) {
    bindFakeHostResolver(['news.example.org' => ['93.184.216.40']]);
    Http::fake(['news.example.org/*' => Http::response('', $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A story')
        ->set('importedImageUrl', 'https://news.example.org/story')
        ->call('submit')
        ->assertSet('submitError', __($message));

    Exceptions::assertNothingReported();
})->with([
    'too many requests' => [429, 'import.errors.site_refusing'],
    'forbidden' => [403, 'import.errors.site_refusing'],
    'not found' => [404, 'import.errors.unsupported'],
    'gone' => [410, 'import.errors.unsupported'],
]);
