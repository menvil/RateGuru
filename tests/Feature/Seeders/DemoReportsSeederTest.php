<?php

use App\Models\Report;
use Database\Seeders\DemoDatabaseSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * The demo's reports are ones the product could have made: nobody reports
 * what they wrote, and a report is settled by someone who moderates.
 */

it('seeds reports that someone else made and a moderator settled', function () {
    Storage::fake('public');
    $this->seed(DemoDatabaseSeeder::class);

    $reports = Report::query()->with(['reporter', 'target', 'resolver'])->get();

    expect($reports)->not->toBeEmpty();

    foreach ($reports as $report) {
        expect($report->target->user_id)->not->toBe($report->reporter_id);

        if ($report->resolved_by !== null) {
            expect(Gate::forUser($report->resolver)->allows('moderate-content'))->toBeTrue();
        }
    }
});
