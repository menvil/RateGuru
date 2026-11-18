<?php

use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use Database\Seeders\DemoAdminSeeder;
use Database\Seeders\TrafficDemoSeeder;

it('seeds traffic sources, campaigns and visits to look at, once', function () {
    $this->seed(DemoAdminSeeder::class);

    $this->seed(TrafficDemoSeeder::class);
    $this->seed(TrafficDemoSeeder::class);

    expect(TrafficSource::query()->count())->toBe(3)
        ->and(TrafficCampaign::query()->count())->toBe(3)
        ->and(TrafficVisit::query()->count())->toBe(900)
        ->and(TrafficChanceRun::query()->where('status', 'finished')->count())->toBe(1)
        ->and(TrafficSetting::current()->capture_enabled)->toBeTrue()
        ->and(TrafficSetting::current()->postbacks_enabled)->toBeFalse()
        // Every visit begins its own session, keyed as capture keys one.
        ->and(TrafficVisit::query()->whereColumn('entry_visit_id', 'id')->count())->toBe(900)
        ->and(TrafficVisit::query()->whereNull('source_key')->orWhereNull('landing_key')->count())->toBe(0)
        ->and(TrafficVisit::query()->whereNotNull('source_id')->where('source_key', 'not like', 'paid:%')->count())->toBe(0);
});
