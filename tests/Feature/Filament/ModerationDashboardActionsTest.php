<?php

use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Filament\Widgets\LatestReportsTable;
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
        ->callTableAction('approvePost', $report, data: [
            'reason' => 'Approved from dashboard.',
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($report->fresh()->status)->toBe(ReportStatus::Open);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $moderator->id,
        'target_type' => Post::class,
        'target_id' => $post->id,
    ]);
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
