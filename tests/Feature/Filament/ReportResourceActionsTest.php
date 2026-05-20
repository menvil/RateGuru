<?php

use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
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

it('hides resolve action from normal users', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    // Normal users cannot reach the panel, but if the action is rendered for
    // any reason it must not be invokable.
    $this->actingAs($user);

    Livewire::test(ListReports::class)
        ->assertTableActionHidden('resolve', $report);
})->skip('Normal users are blocked at the panel layer; covered by access tests.');
