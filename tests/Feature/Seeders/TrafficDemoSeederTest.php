<?php

use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use Database\Seeders\DemoAdminSeeder;
use Database\Seeders\TrafficDemoSeeder;
use Illuminate\Validation\ValidationException;

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

it('seeds nothing and leaves the traffic settings alone outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');
    User::factory()->admin()->create(['email' => 'admin@rateguru.test']);

    // Called directly: db:seed itself stops at its own production prompt.
    (new TrafficDemoSeeder)->run();

    expect(TrafficSource::query()->exists())->toBeFalse()
        ->and(TrafficSetting::query()->exists())->toBeFalse();
});

it('seeds all of the demo or none of it, so a run that failed can be run again', function () {
    $this->seed(DemoAdminSeeder::class);
    $taken = TrafficSource::factory()->placement()->create(['link_key' => 'catblog']);

    expect(fn () => $this->seed(TrafficDemoSeeder::class))->toThrow(ValidationException::class);
    expect(TrafficSource::query()->where('link_key', 'pop')->exists())->toBeFalse()
        ->and(TrafficVisit::query()->exists())->toBeFalse()
        ->and(TrafficSetting::query()->exists())->toBeFalse();

    $taken->forceFill(['link_key' => 'oldblog'])->save();
    $this->seed(TrafficDemoSeeder::class);

    expect(TrafficSource::query()->whereIn('name', ['PopAds main', 'ExoClick tests', 'Cat blog sidebar'])->count())->toBe(3)
        ->and(TrafficVisit::query()->count())->toBe(900);
});
