<?php

use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Services\Rating\LegacyDefaultRatingConfigurationSynchronizer;

it('rolls back legacy option changes when deactivating the group fails', function () {
    $source = RatingGroup::factory()->create(['key' => 'source']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_a']);
    RatingOption::factory()->for($source, 'group')->create(['key' => 'source_b']);
    $exception = null;
    $originalDispatcher = RatingGroup::getEventDispatcher()
        ?? throw new RuntimeException('The model event dispatcher is unavailable.');

    RatingGroup::setEventDispatcher(clone $originalDispatcher);
    RatingGroup::updating(static function (): void {
        throw new RuntimeException('Simulated rating group failure.');
    });

    try {
        app(LegacyDefaultRatingConfigurationSynchronizer::class)->synchronize();
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        RatingGroup::setEventDispatcher($originalDispatcher);
    }

    $refreshedSource = $source->fresh();

    expect($exception)->not->toBeNull()
        ->and($refreshedSource->is_active)->toBeTrue()
        ->and($refreshedSource->options()->count())->toBe(2)
        ->and($refreshedSource->options()->active()->count())->toBe(2)
        ->and($refreshedSource->options()->whereNotNull('archived_at')->exists())->toBeFalse()
        ->and($refreshedSource->options()->orderBy('key')->pluck('key')->all())
        ->toBe(['source_a', 'source_b']);
});
