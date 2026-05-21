<?php

use App\Actions\Reports\IgnoreReportAction;
use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotIgnoreReportException;
use App\Models\Report;
use App\Models\User;

it('allows moderator to ignore open report', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'resolved_by' => null,
        'resolved_at' => null,
    ]);

    app(IgnoreReportAction::class)->handle(
        moderator: $moderator,
        report: $report,
        note: 'Not actionable.'
    );

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Ignored);
    expect($report->resolved_by)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
    expect($report->resolution_note)->toBe('Not actionable.');
});

it('allows admin to ignore open report', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    app(IgnoreReportAction::class)->handle($admin, $report);

    expect($report->fresh()->status)->toBe(ReportStatus::Ignored);
});

it('rejects normal user attempting to ignore report', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    expect(fn () => app(IgnoreReportAction::class)->handle($user, $report))
        ->toThrow(CannotIgnoreReportException::class);

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('does not overwrite already resolved report', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->resolved()->create();
    $originalResolver = $report->resolved_by;

    app(IgnoreReportAction::class)->handle($moderator, $report, 'late ignore');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by)->toBe($originalResolver);
});

it('treats blank ignore note as null', function () {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    app(IgnoreReportAction::class)->handle($moderator, $report, '   ');

    expect($report->fresh()->resolution_note)->toBeNull();
});
