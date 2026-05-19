<?php

use App\Actions\Reports\ReportContentAction;
use App\Enums\ReportReason;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

it('allows user to report post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    $report = app(ReportContentAction::class)->handle(
        user: $user,
        content: $post,
        reason: ReportReason::Spam,
        message: 'This looks like spam.'
    );

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->exists)->toBeTrue();
    expect($report->reporter_id)->toBe($user->id);
    expect($report->target_type)->toBe(Post::class);
    expect($report->target_id)->toBe($post->id);
    expect($report->reason)->toBe(ReportReason::Spam);
    expect($report->message)->toBe('This looks like spam.');
});
