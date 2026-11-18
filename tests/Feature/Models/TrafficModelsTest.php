<?php

use App\Enums\GeoEdition;
use App\Models\TrafficApiConnection;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use Illuminate\Support\Facades\DB;

it('keeps a network API\'s credentials encrypted, and out of every array of the connection', function () {
    $connection = TrafficApiConnection::factory()->create(['credentials' => ['api_key' => 'key-123456']]);
    $stored = (string) DB::table('traffic_api_connections')->where('id', $connection->id)->value('credentials');

    expect($stored)->not->toContain('key-123456')
        ->and($connection->fresh()->credentials)->toBe(['api_key' => 'key-123456'])
        ->and($connection->fresh()->toArray())->not->toHaveKey('credentials')
        ->and($connection->fresh()->toJson())->not->toContain('key-123456');
});

it('never changes a price set once written', function () {
    $set = TrafficPriceSet::current();

    expect(fn () => $set->forceFill(['account_price' => '0.6'])->save())->toThrow(LogicException::class)
        ->and(TrafficPriceSet::current()->account_price)->toBe('0.5000');
});

it('keeps each paid source’s own account price in its set, read back by source id', function () {
    $source = TrafficSource::factory()->create();
    $set = TrafficPriceSet::factory()->create(['source_account_prices' => [$source->id => '0.8000']])->fresh();

    expect($set->sourceAccountPrices())->toBe([$source->id => '0.8000'])
        ->and($set->worth()->accountPriceOf($source->id))->toBe('0.8000')
        ->and($set->worth()->accountPriceOf($source->id + 1))->toBe('0.5000')
        ->and($set->worth()->accountPriceOf(null))->toBe('0.5000')
        ->and(fn () => $set->forceFill(['source_account_prices' => null])->save())->toThrow(LogicException::class);
});

it('puts the newest price set in force', function () {
    $newer = TrafficPriceSet::factory()->create(['account_price' => '0.75']);

    expect(TrafficPriceSet::current()->id)->toBe($newer->id);
});

it('starts the tracker switched off, with the prototype\'s block rule and GeoLite2, before anybody saves its settings', function () {
    $settings = TrafficSetting::current();

    expect($settings->exists)->toBeFalse()
        ->and($settings->capture_enabled)->toBeFalse()
        ->and($settings->postbacks_enabled)->toBeFalse()
        ->and($settings->geo_edition)->toBe(GeoEdition::GeoLite2City)
        ->and([$settings->block_min_spend, $settings->block_min_clicks, $settings->block_roi_below, $settings->block_open_below, $settings->block_bot_clicks, $settings->block_start_clicks, $settings->block_start_percent])
        ->toBe(['2.00', 200, -40, 50, 30, 60, 60])
        // Gentler on its own: half the source's average, three days after a first click, twice a target price.
        ->and([$settings->block_open_below_average, $settings->block_grace_days, $settings->block_target_multiple])->toBe([50, 3, '2.0'])
        // Yet below 5% opened a site is bots whatever its source's average.
        ->and($settings->block_open_floor)->toBe(5)
        ->and(DB::table('traffic_settings')->count())->toBe(0);
});

it('writes a saved row with both switches off', function () {
    $settings = (new TrafficSetting)->forceFill(['id' => 1]);
    $settings->save();

    expect($settings->fresh()->capture_enabled)->toBeFalse()
        ->and($settings->fresh()->postbacks_enabled)->toBeFalse();
});
