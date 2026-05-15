<?php

use App\Enums\ReportReason;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

it('can create a report for a post with factory', function () {
    $report = Report::factory()->forPost()->create();

    expect($report)->toBeInstanceOf(Report::class);
    expect($report->exists)->toBeTrue();
    expect($report->reason)->toBeInstanceOf(ReportReason::class);
    expect($report->target_type)->toBe(Post::class);
    expect($report->reporter)->toBeInstanceOf(User::class);
    expect($report->status)->toBe('open');
});

it('can create a resolved report', function () {
    $report = Report::factory()->forPost()->resolved()->create();

    expect($report->status)->toBe('resolved');
    expect($report->resolved_by)->not->toBeNull();
    expect($report->resolved_at)->not->toBeNull();
});
