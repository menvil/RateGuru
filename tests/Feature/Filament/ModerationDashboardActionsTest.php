<?php

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Filament\Widgets\LatestReportsTable;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

it('quick approves pending post from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();

    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
        'status' => ReportStatus::Open,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->callTableAction('approvePost', $report);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
});

it('quick approve action does not render a reason form', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->pending()->create();
    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->assertTableActionExists(
            'approvePost',
            function ($action): bool {
                $schema = new ReflectionProperty($action, 'schema');
                $schema->setAccessible(true);

                return $schema->getValue($action) === null;
            },
            $report,
        );
});

it('shows quick approve only for pending post report targets', function () {
    $moderator = User::factory()->moderator()->create();
    $pendingPost = Post::factory()->pending()->create();
    $publishedPost = Post::factory()->published()->create();

    $pendingReport = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $pendingPost->id,
    ]);
    $publishedReport = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $publishedPost->id,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->assertTableActionVisible('approvePost', $pendingReport)
        ->assertTableActionHidden('approvePost', $publishedReport);
});

it('quick hides reported post from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
        'status' => ReportStatus::Open,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Hidden from dashboard.',
        ])
        ->assertHasNoTableActionErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('quick hides reported comment from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();
    $comment = Comment::factory()->create([
        'status' => CommentStatus::Visible,
    ]);

    $report = Report::factory()->create([
        'target_type' => Comment::class,
        'target_id' => $comment->id,
        'status' => ReportStatus::Open,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->callTableAction('hideTarget', $report, data: [
            'reason' => 'Hidden from dashboard.',
        ])
        ->assertHasNoTableActionErrors();

    expect($comment->fresh()->status)->toBe(CommentStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('shows quick hide only for hideable report targets', function () {
    $moderator = User::factory()->moderator()->create();
    $publishedPost = Post::factory()->published()->create();
    $hiddenPost = Post::factory()->hidden()->create();

    $publishedPostReport = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $publishedPost->id,
    ]);
    $hiddenPostReport = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $hiddenPost->id,
    ]);
    $missingTargetReport = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => 9999,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->assertTableActionVisible('hideTarget', $publishedPostReport)
        ->assertTableActionHidden('hideTarget', $hiddenPostReport)
        ->assertTableActionHidden('hideTarget', $missingTargetReport);
});

it('quick resolves open report from moderation dashboard latest reports table', function () {
    $moderator = User::factory()->moderator()->create();
    $post = Post::factory()->published()->create();

    $report = Report::factory()->create([
        'target_type' => Post::class,
        'target_id' => $post->id,
        'status' => ReportStatus::Open,
        'resolved_by' => null,
        'resolved_at' => null,
    ]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->callTableAction('resolveReport', $report, data: [
            'note' => 'Handled from dashboard.',
        ])
        ->assertHasNoTableActionErrors();

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolved_by)->toBe($moderator->id)
        ->and($report->resolved_at)->not->toBeNull()
        ->and($report->resolution_note)->toBe('Handled from dashboard.')
        ->and($post->fresh()->status)->toBe(PostStatus::Published);
});

it('shows quick resolve only for open reports', function () {
    $moderator = User::factory()->moderator()->create();

    $openReport = Report::factory()->create(['status' => ReportStatus::Open]);
    $resolvedReport = Report::factory()->resolved()->create();
    $ignoredReport = Report::factory()->create(['status' => ReportStatus::Ignored]);

    Livewire::actingAs($moderator)
        ->test(LatestReportsTable::class)
        ->assertTableActionVisible('resolveReport', $openReport)
        ->assertTableActionHidden('resolveReport', $resolvedReport)
        ->assertTableActionHidden('resolveReport', $ignoredReport);
});
