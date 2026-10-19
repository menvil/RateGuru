<?php

use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;

it('offers exactly the states a plan goes through, Draft first', function () {
    expect(array_map(fn (StudioPlanStatus $status): string => $status->value, StudioPlanStatus::cases()))->toBe(['draft', 'approved', 'running', 'completed', 'cancelled'])
        ->and(array_map(fn (StudioPlanStatus $status): string => $status->label(), StudioPlanStatus::cases()))->toBe(['Draft', 'Approved', 'Running', 'Completed', 'Cancelled']);
});

it('offers exactly two publication modes, Now first', function () {
    expect(array_map(fn (StudioPlanPublicationMode $mode): string => $mode->value, StudioPlanPublicationMode::cases()))->toBe(['now', 'at_date'])
        ->and(array_map(fn (StudioPlanPublicationMode $mode): string => $mode->label(), StudioPlanPublicationMode::cases()))->toBe(['Now', 'At a date']);
});

it('reads no other value as a state or a mode', function () {
    expect(StudioPlanStatus::tryFrom('published'))->toBeNull()
        ->and(StudioPlanStatus::tryFrom('Draft'))->toBeNull()
        ->and(StudioPlanPublicationMode::tryFrom('later'))->toBeNull();
});
