<?php

use App\Actions\Reports\ResolveReportAction;
use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotResolveReportException;
use App\Models\Report;
use App\Models\User;

it('allows moderator to resolve report', function () {
    $moderator = User::factory()->moderator()->create();

    $report = Report::factory()->create([
        'status' => ReportStatus::Open,
        'resolved_by' => null,
        'resolved_at' => null,
    ]);

    app(ResolveReportAction::class)->handle(
        moderator: $moderator,
        report: $report,
        note: 'Reviewed and handled.'
    );

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Resolved);
    expect($report->resolved_by)->toBe($moderator->id);
    expect($report->resolved_at)->not->toBeNull();
    expect($report->resolution_note)->toBe('Reviewed and handled.');
});

it('does not allow normal user to resolve report', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    try {
        app(ResolveReportAction::class)->handle($user, $report);
        $this->fail('Expected CannotResolveReportException was not thrown.');
    } catch (CannotResolveReportException $e) {
        // expected
    }

    expect($report->fresh()->status)->not->toBe(ReportStatus::Resolved);
});
