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
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficSpendImport;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Traffic\Presets\SourceDefaults;
use Carbon\CarbonImmutable;
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

it('keeps a banner’s fixed price as a cost for each day it ran', function () {
    $draft = SourceDefaults::forPlacement();
    $source = app(SaveTrafficSourceAction::class)->handle($this->admin, null, [...$draft['source'], 'name' => 'Cat blog', 'link_key' => 'catblog', 'fixed_amount' => '70', 'fixed_period' => 'week', 'fixed_from' => '2026-11-14'], []);

    expect(TrafficSpendDay::query()->where('source_id', $source->id)->orderBy('network_day')->pluck('amount', 'network_day')->all())->toBe([
        '2026-11-14' => '10.00000000', '2026-11-15' => '10.00000000', '2026-11-16' => '10.00000000', '2026-11-17' => '10.00000000',
    ]);
});
