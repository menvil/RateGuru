<?php

use App\Enums\MediaAuditRunStatus;
use App\Enums\PostStatus;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Pages\StudioActionQueue;
use App\Filament\Pages\TranslationAiPage;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Personas\Pages\ListPersonas;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\StudioPlans\Pages\ListStudioPlans;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Comment;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Queries\Posts\ModerationPostsQuery;
use Livewire\Livewire;

/*
 * A list's address is whatever a link, an old bookmark or a forged request
 * puts there. The page reads it as something it offers — the first page,
 * the default tab — and never breaks on it.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('opens on the first page for a page number past anything a list has', function (string $page, string $number) {
    Livewire::withQueryParams(['page' => $number])->test($page)->assertOk()
        ->assertSet($page === TranslationAiPage::class ? 'callsPage' : 'pageNumber', 1);
})->with([
    ListPosts::class, ListComments::class, ListPersonas::class, ListStudioPlans::class,
    ListUsers::class, ListReports::class, StudioActionQueue::class, MediaDiagnosticsPage::class,
    TranslationAiPage::class,
])->with([
    'past what an integer holds' => '99999999999999999999999',
    'the largest integer' => (string) PHP_INT_MAX,
    'not a number' => 'two',
]);

it('reads a filter given as a list as no filter at all', function (string $page, array $query) {
    Livewire::withQueryParams($query)->test($page)->assertOk();
})->with([
    'posts status' => [ListPosts::class, ['status' => ['pending']]],
    'posts sort' => [ListPosts::class, ['sort' => ['newest']]],
    'comments status' => [ListComments::class, ['status' => ['hidden']]],
    'users status' => [ListUsers::class, ['status' => ['banned']]],
    'users role' => [ListUsers::class, ['role' => ['admin']]],
    'reports status' => [ListReports::class, ['status' => ['open']]],
    'reports type' => [ListReports::class, ['type' => ['post']]],
    'project settings tab' => [ProjectSettingsPage::class, ['tab' => ['identity']]],
]);

it('shows the last page there is when the one on screen empties, not an empty queue', function () {
    Post::factory()->count(ModerationPostsQuery::PER_PAGE + 1)->pending()->create();

    $page = Livewire::withQueryParams(['page' => 2])->test(ListPosts::class)->assertSet('pageNumber', 2);

    // The one post on page two is approved meanwhile.
    Post::query()->where('status', PostStatus::Pending)->latest('id')->first()->update(['status' => PostStatus::Published]);

    $page->call('$refresh')->assertSet('pageNumber', 1);
});

it('shows the last page of reports or people there is when the one asked for is past it', function (string $page, Closure $seed) {
    $seed();

    Livewire::withQueryParams(['page' => 50])->test($page)->assertOk()->assertSet('pageNumber', 1);
})->with([
    'reports' => [ListReports::class, fn () => Report::factory()->count(3)->create()],
    'people' => [ListUsers::class, fn () => User::factory()->count(3)->create()],
    'comments' => [ListComments::class, fn () => Comment::factory()->count(3)->create()],
    'media issues' => [MediaDiagnosticsPage::class, fn () => MediaAuditIssue::factory()->count(3)->create([
        'media_audit_run_id' => MediaAuditRun::factory()->create(['status' => MediaAuditRunStatus::Completed, 'completed_at' => now()])->id,
    ])],
]);
