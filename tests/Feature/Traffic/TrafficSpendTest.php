<?php

use App\Actions\Traffic\AllocateSpendDayAction;
use App\Actions\Traffic\EnterSpendAction;
use App\Actions\Traffic\ReplaceSpendSliceAction;
use App\Actions\Traffic\SaveTrafficCampaignAction;
use App\Actions\Traffic\SaveTrafficSourceAction;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignPrice;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficSpendImport;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Presets\SourceDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    recordTraffic();
    $this->admin = User::factory()->admin()->create();
});

it('counts a click’s spend as it comes in: the link’s price per thousand, or the campaign’s price on that day', function () {
    TrafficSource::factory()->create(['link_key' => 'pop', 'cost_unit' => 'cpm', 'spend_mode' => TrafficSpendMode::ClickCost]);

    clickOn('/?rg_src=pop&cid=c1&campaign=77&cost=0.9')->assertOk();
    $first = TrafficVisit::query()->sole();

    expect($first->spend)->toBe('0.00090000')
        ->and($first->spend_basis)->toBe(TrafficSpendBasis::ClickCost)
        ->and($first->spend_estimated)->toBeFalse();

    $campaign = TrafficCampaign::query()->sole();
    app(SaveTrafficCampaignAction::class)->handle($this->admin, $campaign, ['spend_mode' => 'price', 'bid_model' => 'click', 'bid_desktop' => '0.02', 'price_from' => '2026-11-17'], $campaign->lock_version);
    clickOn('/?rg_src=pop&cid=c2&campaign=77&cost=0.9', str_repeat('b', 40))->assertOk();

    expect(TrafficVisit::query()->latest('id')->first()->spend)->toBe('0.02000000')
        ->and(TrafficVisit::query()->latest('id')->first()->spend_basis)->toBe(TrafficSpendBasis::CampaignPrice)
        ->and($first->fresh()->spend)->toBe('0.00090000');
});

it('shares a day’s figure over its visits, a typed total before the network’s', function () {
    $source = TrafficSource::factory()->create(['spend_mode' => TrafficSpendMode::Api]);
    $visits = TrafficVisit::factory()->count(3)->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'spend_estimated' => true, 'spend_basis' => TrafficSpendBasis::Rate]);
    app(ReplaceSpendSliceAction::class)->handle($source->id, '2026-11-16', TrafficSpendOrigin::Api, [['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00']]);

    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($visits->map(fn ($visit) => $visit->fresh()->spend)->all())->toBe(['0.33333333', '0.33333333', '0.33333334'])
        ->and($visits[0]->fresh()->spend_basis)->toBe(TrafficSpendBasis::Api)
        ->and($visits[0]->fresh()->spend_estimated)->toBeFalse()
        ->and(TrafficSpendDay::query()->sole()->counted)->toBeTrue();

    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '0.60');
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($visits->map(fn ($visit) => $visit->fresh()->spend)->all())->toBe(['0.20000000', '0.20000000', '0.20000000'])
        ->and($visits[0]->fresh()->spend_basis)->toBe(TrafficSpendBasis::Manual)
        ->and(TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Api)->sole()->counted)->toBeFalse();
});

it('keeps spend no visit came for as spend without visits', function () {
    $source = TrafficSource::factory()->create();
    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-15', '4.00');

    $this->artisan('traffic:allocate-spend')->assertSuccessful();

    $fact = TrafficSpendDay::query()->sole();

    expect($fact->counted)->toBeTrue()
        ->and($fact->allocated_amount)->toBe('0.00000000')
        ->and($fact->allocated_at)->not->toBeNull();
});

it('leaves a day due when its figures change while it is being shared out', function () {
    $source = TrafficSource::factory()->create(['spend_mode' => TrafficSpendMode::Api]);
    $visits = TrafficVisit::factory()->count(2)->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'spend_estimated' => true, 'spend_basis' => TrafficSpendBasis::Rate]);
    app(ReplaceSpendSliceAction::class)->handle($source->id, '2026-11-16', TrafficSpendOrigin::Api, [['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00']]);
    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '9');
    $removed = false;
    // The typed total is removed as the first visits take it.
    DB::listen(function (QueryExecuted $query) use (&$removed, $source): void {
        if (! $removed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'traffic_visits')) {
            $removed = true;
            app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '');
        }
    });

    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($removed)->toBeTrue()
        ->and(TrafficSpendDay::query()->sole())->origin->toBe(TrafficSpendOrigin::Api)->allocated_at->toBeNull();

    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($visits->map(fn ($visit) => $visit->fresh()->spend)->all())->toBe(['0.50000000', '0.50000000'])
        ->and($visits[0]->fresh()->spend_basis)->toBe(TrafficSpendBasis::Api);
});

it('writes a day’s visits by the spend they take, not one by one', function () {
    $source = TrafficSource::factory()->create();
    $visits = TrafficVisit::factory()->count(30)->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'cost' => '0.001', 'spend' => '0', 'spend_basis' => TrafficSpendBasis::Rate]);
    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '1.00');
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        $writes += str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'traffic_visits') ? 1 : 0;
    });

    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($writes)->toBeLessThanOrEqual(2)
        ->and(TrafficMoney::stored(TrafficVisit::query()->whereKey($visits->pluck('id'))->pluck('spend')->reduce(fn ($sum, $spend) => $sum->plus(TrafficMoney::of($spend)), TrafficMoney::of('0'))))->toBe('1.00000000');
});

it('refuses a day that hasn’t come, and removes a typed total given empty', function () {
    $source = TrafficSource::factory()->create();

    expect(fn () => app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-20', '1'))->toThrow(ValidationException::class);

    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-15', '4.00');
    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-15', null);

    expect(TrafficSpendDay::query()->count())->toBe(0);
});

it('takes a spend file, leaving out the campaigns RateGuru never saw', function () {
    $source = TrafficSource::factory()->create();
    TrafficCampaign::factory()->create(['source_id' => $source->id, 'external_id' => '881204']);

    $import = app(EnterSpendAction::class)->file($this->admin, $source, 'november.csv', "day,amount,campaign\n2026-11-14,10,881204\n2026-11-14,2,999\n2026-11-15,3,\n");

    expect($import)->toBeInstanceOf(TrafficSpendImport::class)
        ->and($import->status)->toBe('done')
        ->and($import->fact_rows)->toBe(2)
        ->and($import->skipped_rows)->toBe(1)
        ->and($import->skipped_amount)->toBe('2.00000000')
        ->and($import->total)->toBe('13.00000000')
        ->and(TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Csv)->count())->toBe(2)
        ->and(fn () => app(EnterSpendAction::class)->file($this->admin, $source, 'bad.csv', "day,amount\nyesterday,1\n"))->toThrow(ValidationException::class);
});

it('replaces a day’s file figures with a file whose rows for that day are all left out', function () {
    $source = TrafficSource::factory()->create();
    TrafficCampaign::factory()->create(['source_id' => $source->id, 'external_id' => '881204']);
    app(EnterSpendAction::class)->file($this->admin, $source, 'first.csv', "day,amount,campaign\n2026-11-14,10,881204\n");

    $import = app(EnterSpendAction::class)->file($this->admin, $source, 'second.csv', "day,amount,campaign\n2026-11-14,2,999\n");

    expect(TrafficSpendDay::query()->where('origin', TrafficSpendOrigin::Csv)->where('network_day', '2026-11-14')->count())->toBe(0)
        ->and($import)->skipped_rows->toBe(1)->fact_rows->toBe(0)->day_from->toBe('2026-11-14')->day_to->toBe('2026-11-14');
});

it('keeps a banner’s fixed price as a cost for each day it ran', function () {
    $draft = SourceDefaults::forPlacement();
    $source = app(SaveTrafficSourceAction::class)->handle($this->admin, null, [...$draft['source'], 'name' => 'Cat blog', 'link_key' => 'catblog', 'fixed_amount' => '70', 'fixed_period' => 'week', 'fixed_from' => '2026-11-14'], []);

    expect(TrafficSpendDay::query()->where('source_id', $source->id)->orderBy('network_day')->pluck('amount', 'network_day')->all())->toBe([
        '2026-11-14' => '10.00000000', '2026-11-15' => '10.00000000', '2026-11-16' => '10.00000000', '2026-11-17' => '10.00000000',
    ]);
});

it('puts a day’s clicks back to their own prices when its typed total is removed', function () {
    $source = TrafficSource::factory()->create(['spend_mode' => TrafficSpendMode::Rate, 'rate_mobile' => '0.01', 'rate_unit' => 'click']);
    $visits = TrafficVisit::factory()->count(2)->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'device' => 'mobile', 'spend' => '0.01', 'spend_basis' => TrafficSpendBasis::Rate]);

    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '100');
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($visits[0]->fresh()->spend)->toBe('50.00000000');

    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', null);

    expect($visits->map(fn ($visit) => $visit->fresh()->spend)->all())->toBe(['0.01000000', '0.01000000'])
        ->and($visits[0]->fresh()->spend_basis)->toBe(TrafficSpendBasis::Rate);
});

it('counts the network’s figures only for a source that takes its spend from them', function () {
    $source = TrafficSource::factory()->create(['spend_mode' => TrafficSpendMode::ClickCost]);
    $visit = TrafficVisit::factory()->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'cost' => '0.002', 'spend' => '0.002', 'spend_basis' => TrafficSpendBasis::ClickCost]);
    app(ReplaceSpendSliceAction::class)->handle($source->id, '2026-11-16', TrafficSpendOrigin::Api, [['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '5.00']]);

    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    // The clicks' own prices stand; the API's figure is not spend without visits on top of them.
    expect($visit->fresh()->spend)->toBe('0.00200000')
        ->and(TrafficSpendDay::query()->sole()->counted)->toBeFalse()
        ->and(app(TrafficSpendQuery::class)->unallocated('2026-11-16', '2026-11-16'))->toBe([]);
});

it('shares the network’s figures over visits a removed typed total had priced, and leaves a campaign priced its own way alone', function () {
    $source = TrafficSource::factory()->create(['spend_mode' => TrafficSpendMode::Api]);
    $own = TrafficCampaign::factory()->create(['source_id' => $source->id, 'spend_mode' => TrafficSpendMode::Price]);
    TrafficCampaignPrice::query()->forceCreate(['campaign_id' => $own->id, 'device' => 'mobile', 'bid_model' => 'click', 'price' => '0.05', 'effective_from' => '2026-11-01 00:00:00', 'actor' => 'user']);
    $visits = TrafficVisit::factory()->count(2)->create(['source_id' => $source->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'spend_estimated' => true, 'spend_basis' => TrafficSpendBasis::Rate]);
    $priced = TrafficVisit::factory()->create(['source_id' => $source->id, 'campaign_id' => $own->id, 'network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'device' => 'mobile', 'spend' => '0.05', 'spend_basis' => TrafficSpendBasis::CampaignPrice]);

    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', '9');
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());
    app(ReplaceSpendSliceAction::class)->handle($source->id, '2026-11-16', TrafficSpendOrigin::Api, [
        ['campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00'],
        ['campaign_id' => $own->id, 'site_ref' => '', 'zone_ref' => '', 'amount' => '3.00'],
    ]);
    app(EnterSpendAction::class)->day($this->admin, $source, '2026-11-16', null);
    app(AllocateSpendDayAction::class)->handle($source, '2026-11-16', now()->toImmutable());

    expect($visits->map(fn ($visit) => $visit->fresh()->spend)->all())->toBe(['0.50000000', '0.50000000'])
        ->and($visits[0]->fresh()->spend_basis)->toBe(TrafficSpendBasis::Api)
        ->and($priced->fresh()->spend)->toBe('0.05000000')
        ->and(TrafficSpendDay::query()->where('campaign_id', $own->id)->sole()->counted)->toBeFalse();
});
