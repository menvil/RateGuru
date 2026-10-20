<?php

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
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

it('offers exactly the states a photo analysis attempt goes through, the first two still in progress', function () {
    expect(array_map(fn (StudioImageAnalysisStatus $status): string => $status->value, StudioImageAnalysisStatus::cases()))->toBe(['queued', 'running', 'completed', 'failed'])
        ->and(array_map(fn (StudioImageAnalysisStatus $status): bool => $status->isInProgress(), StudioImageAnalysisStatus::cases()))->toBe([true, true, false, false]);
});

it('says every reason a photo analysis failed in a sentence of its own, naming no secret', function () {
    $messages = array_map(fn (StudioImageAnalysisError $error): string => $error->message(), StudioImageAnalysisError::cases());

    expect(array_map(fn (StudioImageAnalysisError $error): string => $error->value, StudioImageAnalysisError::cases()))->toBe([
        'not_configured', 'image_missing', 'image_unavailable', 'authentication_failed', 'rate_limited', 'provider_unavailable',
        'provider_timeout', 'provider_rejected', 'provider_refused', 'invalid_provider_response', 'stale_image', 'plan_closed', 'unexpected_failure',
    ])
        ->and(array_unique($messages))->toHaveCount(count($messages))
        ->and(StudioImageAnalysisError::NotConfigured->message())->toBe('Photo analysis is not configured.');

    foreach ($messages as $message) {
        expect($message)->toEndWith('.')->not->toContain('API_KEY');
    }
});
