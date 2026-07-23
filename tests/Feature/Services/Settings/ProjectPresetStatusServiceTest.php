<?php

use App\Models\ProjectSettings;
use App\Services\Settings\ProjectPresetStatusService;
use Illuminate\Support\Carbon;

it('reports when no installation preset has been applied', function () {
    ProjectSettings::factory()->create(['preset_applied_at' => null]);

    expect(app(ProjectPresetStatusService::class)->display())
        ->toBe('No installation preset has been applied.');
});

it('formats the installed preset label and timestamp', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => Carbon::parse('2026-07-22 10:00:00'),
    ]);

    expect(app(ProjectPresetStatusService::class)->display())
        ->toBe('Nature & travel photography · applied 2026-07-22 10:00:00');
});
