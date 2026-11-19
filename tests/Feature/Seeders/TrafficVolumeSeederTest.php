<?php

use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use Database\Seeders\TrafficVolumeSeeder;

it('inserts no visits outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');
    TrafficSource::factory()->create();

    // Called directly: db:seed itself stops at its own production prompt.
    (new TrafficVolumeSeeder)->run();

    expect(TrafficVisit::query()->exists())->toBeFalse();
});
