<?php

use App\Actions\Reports\ResolveReportAction;
use App\Enums\CommentStatus;
use App\Enums\ModerationActionType;
use App\Enums\PostStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Comment;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

/**
 * Acting on reports: Resolve and Ignore close one report — from its row, or
 * from its chain with a note, which then opens the next open report — with
 * Undo; Reopen opens a closed one again. Hide target and Ban author act on
 * what the report points to and leave the report open.
 */
beforeEach(function () {
    $this->moderator = User::factory()->moderator()->create();
    $this->actingAs($this->moderator);
});

it('resolves or ignores a report from its row, with Undo that opens it again', function (string $method, ReportStatus $closed, string $word) {
    $report = reportOn(Post::factory()->published()->create());

    $page = Livewire::test(ListReports::class)
        ->call($method, $report->id)
        ->assertDispatched('rg-admin-toast', message: 'Report #'.$report->id.' '.$word, action: ['label' => 'Undo', 'event' => 'reopen-report', 'params' => ['id' => $report->id]]);

    expect($report->fresh())->status->toBe($closed)->resolved_by->toBe($this->moderator->id);

    $page->dispatch('reopen-report', id: $report->id)
        ->assertDispatched('rg-admin-toast', message: 'Report #'.$report->id.' is open again')
        ->assertSet('report', $report->id);

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
})->with([
    'resolved' => ['resolve', ReportStatus::Resolved, 'resolved'],
    'ignored' => ['ignore', ReportStatus::Ignored, 'ignored'],
]);

it('closes the open report with its note, and opens the next open one', function () {
    $post = Post::factory()->published()->create();
    $first = reportOn($post, ['created_at' => now()->subHours(3)]);
    $second = reportOn($post, ['created_at' => now()->subHours(2)]);

    Livewire::test(ListReports::class)
        ->call('select', $first->id)
        ->set('note', '  Post hidden as spam. ')
        ->call('resolve')
        ->assertSet('report', $second->id)
        ->assertSet('note', '');

    expect($first->fresh())->status->toBe(ReportStatus::Resolved)->resolution_note->toBe('Post hidden as spam.');
});

it('steps from one report to the next and back, in the list\'s order', function () {
    $post = Post::factory()->published()->create();
    $first = reportOn($post, ['created_at' => now()->subHours(3)]);
    $second = reportOn($post, ['created_at' => now()->subHours(2)]);

    Livewire::test(ListReports::class)
        ->call('step', 1)
        ->assertSet('report', $first->id)
        ->call('step', 1)
        ->assertSet('report', $second->id)
        ->call('step', 1)
        ->assertSet('report', $second->id)
        ->call('step', -1)
        ->assertSet('report', $first->id)
        ->call('closePanel')
        ->assertSet('report', null);
});

it('reopens a closed report from its chain', function () {
    $report = reportOn(Post::factory()->published()->create(), ['status' => ReportStatus::Ignored]);

    Livewire::withQueryParams(['status' => 'ignored'])->test(ListReports::class)
        ->call('select', $report->id)
        ->assertSeeHtml('Reopen')
        ->call('reopen', $report->id);

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('says so, and changes nothing, when someone closed the report meanwhile', function () {
    $report = reportOn(Post::factory()->published()->create());
    $page = Livewire::test(ListReports::class);
    app(ResolveReportAction::class)->handle(User::factory()->moderator()->create(), $report, 'Elsewhere.');

    $page->call('ignore', $report->id)
        ->assertDispatched('rg-admin-toast', message: 'This changed meanwhile, so nothing was done. The list now shows it as it is.', tone: 'error');

    expect($report->fresh())->status->toBe(ReportStatus::Resolved)->resolution_note->toBe('Elsewhere.');
});

it('hides a reported post only with a reason, the report\'s own filled in, and leaves the report open', function () {
    $post = Post::factory()->published()->create();
    $report = reportOn($post, ['reason' => ReportReason::Spam]);
    reportOn($post);

    Livewire::test(ListReports::class)
        ->call('select', $report->id)
        ->call('askToHideTarget')
        ->assertSet('confirming', 'hide')
        ->assertSet('reason', 'Spam')
        ->assertSeeHtml('Hide the reported post?')
        ->set('reason', '')
        ->call('confirm')
        ->assertHasErrors(['reason' => 'required'])
        ->set('reason', 'Spam')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Post #'.$post->id.' hidden. 2 open reports remain on it.');

    expect($post->fresh()->status)->toBe(PostStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open)
        ->and(ModerationLog::query()->where('action', ModerationActionType::HidePost)->sole()->reason)->toBe('Spam');
});

it('hides a reported comment with an optional reason', function () {
    $comment = Comment::factory()->create();
    $report = reportOn($comment);

    Livewire::test(ListReports::class)
        ->call('select', $report->id)
        ->call('askToHideTarget')
        ->set('reason', '')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('lets an administrator ban the author of the reported content, leaving the report open', function () {
    $this->actingAs(User::factory()->admin()->create());
    $author = User::factory()->create(['username' => 'cheap_pet_meds']);
    $report = reportOn(Post::factory()->published()->for($author)->create());

    Livewire::test(ListReports::class)
        ->call('select', $report->id)
        ->call('askToBan')
        ->assertSet('confirming', 'ban')
        ->assertSeeHtml('Ban cheap_pet_meds?')
        ->assertSeeHtml('can no longer take part: no new posts, comments, votes, reports or follows.')
        ->call('confirm')
        ->assertDispatched('rg-admin-toast', message: 'cheap_pet_meds banned. Report #'.$report->id.' is still open.');

    expect($author->fresh()->status)->toBe(UserStatus::Banned)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('offers no ban to a moderator, nor of an administrator or someone already banned', function (string $who) {
    if ($who !== 'moderator') {
        $this->actingAs(User::factory()->admin()->create());
    }

    $author = match ($who) {
        'admin target' => User::factory()->admin()->create(),
        'banned' => User::factory()->banned()->create(),
        default => User::factory()->create(),
    };
    $report = reportOn(Post::factory()->published()->for($author)->create());

    Livewire::test(ListReports::class)
        ->call('select', $report->id)
        ->call('askToBan')
        ->assertSet('confirming', null);

    expect($author->fresh()->status)->toBe($who === 'banned' ? UserStatus::Banned : UserStatus::Active);
})->with(['moderator', 'admin target', 'banned']);

it('offers no Hide when the target is gone', function () {
    $report = Report::factory()->create(['target_type' => Post::class, 'target_id' => 999999, 'status' => ReportStatus::Open]);

    Livewire::test(ListReports::class)
        ->call('select', $report->id)
        ->call('askToHideTarget')
        ->assertSet('confirming', null);
});
