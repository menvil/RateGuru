<?php

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

it('resolves open report from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'resolved_by' => null,
        'resolved_at' => null,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->callTableAction('resolve', $report, data: [
            'note' => 'Reviewed and handled.',
        ])
        ->assertHasNoTableActionErrors();

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
    expect($report->resolution_note)->toBe('Reviewed and handled.');
});

it('hides resolve action for already resolved reports', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->resolved()->create();

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('resolve', $report);
});

it('ignores open report from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->callTableAction('ignore', $report, data: [
            'note' => 'No violation found.',
        ])
        ->assertHasNoTableActionErrors();

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Ignored);
    expect($report->resolved_by)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
    expect($report->resolution_note)->toBe('No violation found.');
});

it('hides ignore action for already resolved reports', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->resolved()->create();

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('ignore', $report);
});

it('hides reported post from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Violates content rules.',
        ])
        ->assertHasNoTableActionErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Hidden);
});

it('hides reported comment from report resource table action', function () {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->create(['status' => CommentStatus::Visible]);

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Abusive comment.',
        ])
        ->assertHasNoTableActionErrors();

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden);
});

it('does not auto-resolve report after hiding target', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->callTableAction('hideTarget', $report, data: ['reason' => null])
        ->assertHasNoTableActionErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('hides hideTarget action when target is missing', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Post::class,
        'target_id' => 9999, // non-existent
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('hideTarget', $report);
});

it('allows admin to ban reported post author from report resource', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['status' => UserStatus::Active]);
    $post = Post::factory()->for($author)->published()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->callTableAction('banTargetAuthor', $report, data: [
            'reason' => 'Repeated violations.',
        ])
        ->assertHasNoTableActionErrors();

    expect($author->fresh()->status)->toBe(UserStatus::Banned);
});

it('allows admin to ban reported comment author from report resource', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['status' => UserStatus::Active]);
    $comment = Comment::factory()->for($author)->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'target_type' => Comment::class,
        'target_id' => $comment->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->callTableAction('banTargetAuthor', $report, data: [
            'reason' => 'Abusive author.',
        ])
        ->assertHasNoTableActionErrors();

    expect($author->fresh()->status)->toBe(UserStatus::Banned);
});

it('hides ban target author action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $author = User::factory()->create();
    $post = Post::factory()->for($author)->published()->create();
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($moderator);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('banTargetAuthor', $report);
});

it('hides ban target author action when target author is admin', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $post = Post::factory()->for($otherAdmin)->published()->create();
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('banTargetAuthor', $report);
});

it('hides ban target author action when author is already banned', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['status' => UserStatus::Banned]);
    $post = Post::factory()->for($author)->published()->create();
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('banTargetAuthor', $report);
});

it('hides resolve action from normal users', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    // Normal users cannot reach the panel, but if the action is rendered for
    // any reason it must not be invokable.
    $this->actingAs($user);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('resolve', $report);
})->skip('Normal users are blocked at the panel layer; covered by access tests.');
