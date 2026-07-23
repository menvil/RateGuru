<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Services\Rating\LegacyDefaultRatingConfigurationSynchronizer;

it('rolls back legacy option changes when deactivating the group fails', function () {
    $source = RatingGroup::factory()->create(['key' => 'source']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_a']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_b']);
    $exception = null;

    RatingGroup::updating(function (): void {
        throw new RuntimeException('Simulated rating group failure.');
    });

    try {
        app(LegacyDefaultRatingConfigurationSynchronizer::class)->synchronize();
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        RatingGroup::flushEventListeners();
    }

    expect($exception)->not->toBeNull()
        ->and($source->fresh()->is_active)->toBeTrue()
        ->and($source->options()->active()->count())->toBe(2);
});
