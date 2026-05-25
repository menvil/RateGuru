<?php

use App\Actions\Reports\ReportContentAction;
use App\Enums\ReportReason;
use App\Exceptions\Reports\CannotReportContentException;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

it('blocks excessive reports from same user', function () {
    config()->set('rate_limits.report.max_attempts', 2);
    config()->set('rate_limits.report.decay_seconds', 600);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();
    $third = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($user, $first, ReportReason::Spam);
    app(ReportContentAction::class)->handle($user, $second, ReportReason::Spam);

    app(ReportContentAction::class)->handle($user, $third, ReportReason::Spam);
})->throws(CannotReportContentException::class, 'You are reporting too quickly. Please try again later.');

it('does not create report when report rate limit is exceeded', function () {
    config()->set('rate_limits.report.max_attempts', 1);
    config()->set('rate_limits.report.decay_seconds', 600);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create(['reports_count' => 0]);

    app(ReportContentAction::class)->handle($user, $first, ReportReason::Spam);

    try {
        app(ReportContentAction::class)->handle($user, $second, ReportReason::Spam);
    } catch (CannotReportContentException) {
        // Expected.
    }

    expect(Report::query()->where('reporter_id', $user->id)->count())->toBe(1);
    expect($second->fresh()->reports_count)->toBe(0);
});

it('does not block another user when first user hits report limit', function () {
    config()->set('rate_limits.report.max_attempts', 1);
    config()->set('rate_limits.report.decay_seconds', 600);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    app(ReportContentAction::class)->handle($firstUser, $first, ReportReason::Spam);

    $thrown = false;

    try {
        app(ReportContentAction::class)->handle($firstUser, $second, ReportReason::Spam);
    } catch (CannotReportContentException) {
        $thrown = true;
    }

    $this->assertTrue($thrown, 'Expected first user to be rate limited.');

    $report = app(ReportContentAction::class)->handle($secondUser, $second, ReportReason::Spam);

    expect($report->reporter_id)->toBe($secondUser->id);
});
