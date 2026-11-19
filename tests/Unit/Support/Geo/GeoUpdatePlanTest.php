<?php

use App\Enums\GeoEdition;
use App\Support\Geo\Update\GeoEditionSpec;
use App\Support\Geo\Update\GeoUpdatePlan;
use Carbon\CarbonImmutable;

function geoPlanSpec(?int $maxBuildAgeDays = 30): GeoEditionSpec
{
    return new GeoEditionSpec(GeoEdition::GeoLite2City, 'geolite2-city', 'GeoLite2-City.mmdb.gz', ['GeoLite2-City'], 1000, 10, 2000, $maxBuildAgeDays, 14, ['npm']);
}

it('has nothing to do until the next check', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');
    $state = ['next_check_at' => '2026-11-18T08:00:00+00:00', 'last_attempt_at' => '2026-11-17T08:00:00+00:00', 'installed' => ['build_epoch' => $now->subDays(3)->getTimestamp()]];

    $plan = GeoUpdatePlan::decide($state, geoPlanSpec(), $now, null, false, true);

    expect($plan->due)->toBeFalse()->and($plan->withdraw)->toBeFalse();
});

it('is due when the check comes, when forced, without a database, or when a refresh was asked for after the last attempt', function (array $state, ?string $requested, bool $force, bool $exists) {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');

    expect(GeoUpdatePlan::decide($state, geoPlanSpec(), $now, $requested ? CarbonImmutable::parse($requested, 'UTC') : null, $force, $exists)->due)->toBeTrue();
})->with([
    'the check came' => [['next_check_at' => '2026-11-17T10:00:00+00:00'], null, false, true],
    'never checked' => [[], null, false, true],
    'forced' => [['next_check_at' => '2026-11-18T10:00:00+00:00'], null, true, true],
    'no database' => [['next_check_at' => '2026-11-18T10:00:00+00:00'], null, false, false],
    'asked after the last attempt' => [['next_check_at' => '2026-11-18T10:00:00+00:00', 'last_attempt_at' => '2026-11-17T09:00:00+00:00'], '2026-11-17 09:30:00', false, true],
]);

it('does not run again for a refresh asked before the last attempt', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');
    $state = ['next_check_at' => '2026-11-18T10:00:00+00:00', 'last_attempt_at' => '2026-11-17T09:00:00+00:00'];

    expect(GeoUpdatePlan::decide($state, geoPlanSpec(), $now, CarbonImmutable::parse('2026-11-17 08:00:00', 'UTC'), false, true)->due)->toBeFalse();
});

it('withdraws a build past its licence age, and only an edition that has one', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');
    $state = ['next_check_at' => '2026-11-18T10:00:00+00:00', 'installed' => ['build_epoch' => $now->subDays(31)->getTimestamp()]];

    expect(GeoUpdatePlan::decide($state, geoPlanSpec(30), $now, null, false, true))
        ->due->toBeTrue()->withdraw->toBeTrue()
        ->and(GeoUpdatePlan::decide($state, geoPlanSpec(null), $now, null, false, true)->withdraw)->toBeFalse();
});

it('lets the previous database go once its week is over', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');

    expect(GeoUpdatePlan::decide(['previous' => ['kept_until' => '2026-11-17T09:00:00+00:00']], geoPlanSpec(), $now, null, false, true)->dropPrevious)->toBeTrue()
        ->and(GeoUpdatePlan::decide(['previous' => ['kept_until' => '2026-11-18T09:00:00+00:00']], geoPlanSpec(), $now, null, false, true)->dropPrevious)->toBeFalse();
});

it('waits longer after every failure in a row, up to the last step', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');

    expect(GeoUpdatePlan::retryAt($now, 1, [15, 60, 180, 360])->toIso8601String())->toBe('2026-11-17T10:15:00+00:00')
        ->and(GeoUpdatePlan::retryAt($now, 3, [15, 60, 180, 360])->toIso8601String())->toBe('2026-11-17T13:00:00+00:00')
        ->and(GeoUpdatePlan::retryAt($now, 9, [15, 60, 180, 360])->toIso8601String())->toBe('2026-11-17T16:00:00+00:00');
});

it('keeps to the backoff of a failed attempt with no database, rather than downloading again every minute', function () {
    $now = CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC');
    $failed = ['next_check_at' => '2026-11-17T11:00:00+00:00', 'last_attempt_at' => '2026-11-17T09:55:00+00:00', 'failures' => 2];

    expect(GeoUpdatePlan::decide($failed, geoPlanSpec(), $now, null, false, false)->due)->toBeFalse()
        ->and(GeoUpdatePlan::decide($failed, geoPlanSpec(), $now->addHour(), null, false, false)->due)->toBeTrue()
        ->and(GeoUpdatePlan::decide($failed, geoPlanSpec(), $now, null, true, false)->due)->toBeTrue();
});
