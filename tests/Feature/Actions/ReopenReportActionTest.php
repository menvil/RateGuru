<?php

use App\Actions\Reports\IgnoreReportAction;
use App\Actions\Reports\ReopenReportAction;
use App\Actions\Reports\ResolveReportAction;
use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotReopenReportException;
use App\Models\Report;
use App\Models\User;

/**
 * Reopening a report a moderator closed: it is open again as it came in,
 * without the decision that closed it.
 */
it('opens a resolved or ignored report again, without who closed it, when or why', function (string $close) {
    $moderator = User::factory()->moderator()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);
    app($close)->handle($moderator, $report, 'Handled.');

    app(ReopenReportAction::class)->handle($moderator, $report);

    expect($report->fresh())
        ->status->toBe(ReportStatus::Open)
        ->resolved_by->toBeNull()
        ->resolved_at->toBeNull()
        ->resolution_note->toBeNull();
})->with([
    'resolved' => [ResolveReportAction::class],
    'ignored' => [IgnoreReportAction::class],
]);

it('leaves an open report as it is', function () {
    $report = Report::factory()->create(['status' => ReportStatus::Open]);
    $before = $report->updated_at;

    app(ReopenReportAction::class)->handle(User::factory()->moderator()->create(), $report);

    expect($report->fresh()->status)->toBe(ReportStatus::Open)
        ->and($report->fresh()->updated_at?->equalTo($before))->toBeTrue();
});

it('refuses anyone who may not process reports', function (string $who) {
    $user = $who === 'member' ? User::factory()->create() : User::factory()->moderator()->banned()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Resolved]);

    app(ReopenReportAction::class)->handle($user, $report);
})->throws(CannotReopenReportException::class)->with(['member', 'a banned moderator']);
