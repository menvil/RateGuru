<?php

use App\Enums\CommentStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Reports in Admin v2: who may open the screen, what each tab lists and how
 * each row reads, and the open report's chain — the report, its target, the
 * target's author — and how a closed report says it was closed.
 */
it('opens for administrators and moderators, and for nobody else', function (string $role, int $status) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'moderator' => User::factory()->moderator()->create(),
        default => User::factory()->create(),
    };

    $this->actingAs($user)->get(ReportResource::getUrl('index'))->assertStatus($status);
})->with([
    'an administrator' => ['admin', 200],
    'a moderator' => ['moderator', 200],
    'a member' => ['user', 403],
]);

it('sits third in the Moderation section, with the open reports as its count', function () {
    reportOn(Post::factory()->published()->create());
    reportOn(Post::factory()->published()->create(), ['status' => ReportStatus::Resolved]);

    expect(ReportResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION)
        ->and(ReportResource::getNavigationSort())->toBe(30)
        ->and(ReportResource::getNavigationBadge())->toBe('1')
        ->and(ReportResource::getNavigationBadgeColor())->toBe('warning')
        ->and(array_keys(ReportResource::getPages()))->toBe(['index']);
});

// One request: Filament reads the page's scope, the top bar's keys included,
// from the request the page was first rendered for.
it('draws the screen in Admin v2, with the keys that step through reports in the top bar', function () {
    $this->actingAs(User::factory()->moderator()->create());
    reportOn(Post::factory()->published()->create(['title' => 'Free kittens, DM for price']));

    expect($this->get(ReportResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-reports-title" class="rg-admin-page-header__title">Reports</h1>')
        ->toContain('Free kittens, DM for price')
        ->toContain('Next / previous report')
        ->not->toContain('fi-ta-table');
});

it('opens on the open reports, oldest first, counting every tab and the targets they point to', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $post = Post::factory()->published()->create();
    $older = reportOn($post, ['created_at' => now()->subHours(3)]);
    $newer = reportOn($post, ['created_at' => now()->subHour()]);
    reportOn(Comment::factory()->create(), ['status' => ReportStatus::Ignored]);

    $page = Livewire::test(ListReports::class)->assertSet('status', 'open');

    expect(array_column($page->viewData('rows'), 'id'))->toBe([$older->id, $newer->id])
        ->and(array_column($page->viewData('tabs'), 'count', 'id'))->toBe(['open' => '2', 'resolved' => '0', 'ignored' => '1', 'all' => '3'])
        ->and($page->viewData('stats'))->toMatchArray(['open' => '2', 'targets' => '1', 'oldest' => '3 h 0 min'])
        ->and(listedRow($page, $older))->toMatchArray(['kind' => 'Post', 'meta' => '#'.$post->id.' · Published · 2 open reports', 'open' => true]);
});

it('narrows the list to a kind of target and searches it on the server', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $onPost = reportOn(Post::factory()->published()->create(['title' => 'Free kittens']));
    $onComment = reportOn(Comment::factory()->create(['body' => 'Visit pet-deals24.top']));

    $page = Livewire::test(ListReports::class);

    expect(array_column($page->set('type', 'comment')->viewData('rows'), 'id'))->toBe([$onComment->id])
        ->and(array_column($page->set('type', 'all')->set('q', 'kittens')->viewData('rows'), 'id'))->toBe([$onPost->id]);

    $page->set('q', 'nothing like it')->assertSeeHtml('No reports here');
    $page->set('q', '')->set('type', 'nonsense')->assertSet('type', 'all');
});

it('says so when no report is open', function () {
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::test(ListReports::class)->assertSeeHtml('No open reports');
});

it('opens a report\'s chain: the report, the post it points to and the post\'s author', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $author = User::factory()->create(['username' => 'cheap_pet_meds', 'trust_level' => 0]);
    $post = Post::factory()->published()->for($author)->create(['title' => 'Free kittens, DM for price']);
    $report = reportOn($post, ['reason' => ReportReason::Spam, 'message' => 'Sales aren’t allowed.', 'reporter_id' => User::factory()->create(['username' => 'georgi.d'])->id]);
    reportOn($post);

    $page = Livewire::test(ListReports::class)->call('select', $report->id)->assertSet('report', $report->id);
    $detail = $page->viewData('detail');

    expect($detail)->toMatchArray([
        'reason' => 'Reported as spam',
        'message' => 'Sales aren’t allowed.',
        'siblings' => '2 open reports point to this post. Each one is closed separately.',
        'open' => true,
        'closed' => null,
    ])
        ->and($detail['reporter'])->toBe(['name' => 'georgi.d', 'initials' => 'GE'])
        ->and($detail['target'])->toMatchArray(['title' => 'Post #'.$post->id, 'preview' => 'Free kittens, DM for price', 'url' => route('posts.show', $post), 'canHide' => true, 'hiddenNotice' => false])
        ->and($detail['author'])->toMatchArray(['name' => 'cheap_pet_meds', 'trust' => 'Not trusted', 'ban' => null, 'url' => UserResource::getUrl('edit', ['record' => $author])])
        ->and($detail['author']['stats'])->toBe('1 post · 2 reports on their content');

    $page->assertSeeHtml('Report #'.$report->id)->assertSeeHtml('Resolution note');
});

it('says the target is hidden while its report stays open, and offers no Hide then', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $report = reportOn(Comment::factory()->create(['status' => CommentStatus::Hidden]));

    $detail = Livewire::test(ListReports::class)->call('select', $report->id)->viewData('detail');

    expect($detail['target'])->toMatchArray(['kind' => 'Comment', 'canHide' => false, 'hiddenNotice' => true]);
});

it('shows a reported person as the last link, and a missing target as unavailable', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $person = User::factory()->create(['username' => 'spam_pawz', 'status' => UserStatus::Limited]);
    $onPerson = reportOn($person);
    $missing = Report::factory()->create(['target_type' => Post::class, 'target_id' => 999999]);

    $page = Livewire::test(ListReports::class);
    $detail = $page->call('select', $onPerson->id)->viewData('detail');

    expect($detail['target'])->toMatchArray(['kind' => 'Person', 'title' => 'Person · spam_pawz', 'canHide' => false, 'url' => route('profile.show', 'spam_pawz')])
        ->and($detail['author'])->toMatchArray(['name' => 'spam_pawz', 'ban' => 'Administrators only'])
        ->and($detail['author']['status'])->toMatchArray(['label' => 'Limited', 'tone' => 'warning']);

    $detail = $page->call('select', $missing->id)->viewData('detail');

    expect($detail['target'])->toMatchArray(['preview' => 'Target unavailable', 'canHide' => false])
        ->and($detail['author'])->toBeNull();
});

it('says who closed a report, when and why', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $resolver = User::factory()->moderator()->create(['name' => 'Daria Kovaleva']);
    $report = reportOn(Post::factory()->published()->create(), ['status' => ReportStatus::Resolved, 'resolved_by' => $resolver->id, 'resolved_at' => now(), 'resolution_note' => 'Post rejected in moderation.']);

    $detail = Livewire::withQueryParams(['status' => 'resolved', 'report' => $report->id])->test(ListReports::class)->viewData('detail');

    expect($detail['closed']['title'])->toStartWith('Resolved by Daria Kovaleva · Today')
        ->and($detail['closed']['note'])->toBe('Post rejected in moderation.');
});

it('keeps the confirmation on the server, and opens no report that is not there', function () {
    $this->actingAs(User::factory()->moderator()->create());

    Livewire::withQueryParams(['report' => 999999])->test(ListReports::class)->assertSet('report', null);
    Livewire::test(ListReports::class)->set('confirming', 'ban');
})->throws(CannotUpdateLockedPropertyException::class);
