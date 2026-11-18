<?php

use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Support\Traffic\Blocking\BlockRule;
use Illuminate\Support\Facades\DB;

/**
 * The gentler block rule's columns, on every engine: the general share of a
 * source's average, the floor below which a site is bots whatever that
 * average, the days after a first click and the multiple of a target price,
 * each with its default — a settings row saved before reads half, 5 %,
 * three and twice — and a network source's own thresholds, empty until it
 * sets one; all of them taken off again by their migrations' down().
 */
function blockRuleThresholdsMigration(): object
{
    return require database_path('migrations/2026_11_18_100900_add_block_rule_thresholds_to_traffic_sources_and_settings.php');
}

function blockOpenFloorMigration(): object
{
    return require database_path('migrations/2026_11_18_101200_add_block_open_floor_to_traffic_sources_and_settings.php');
}

it('adds the general thresholds with their defaults, for a row saved before as well, and a source’s own empty, and takes them off again', function () {
    $source = TrafficSource::factory()->create();
    blockOpenFloorMigration()->down();
    blockRuleThresholdsMigration()->down();

    expect(DB::getSchemaBuilder()->hasColumns('traffic_settings', ['block_open_below_average']))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_open_floor'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_grace_days'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_target_multiple'))->toBeFalse()
        ->and(collect(BlockRule::KEYS)->filter(fn (string $column): bool => DB::getSchemaBuilder()->hasColumn('traffic_sources', $column))->all())->toBe([]);

    // A row saved before the columns came, with a threshold of its own.
    DB::table('traffic_settings')->insert(['id' => 1, 'block_open_below' => 40, 'created_at' => now(), 'updated_at' => now()]);

    blockRuleThresholdsMigration()->up();
    blockOpenFloorMigration()->up();

    expect(DB::getSchemaBuilder()->hasColumns('traffic_sources', BlockRule::KEYS))->toBeTrue()
        ->and(TrafficSetting::current()->only(['block_open_below', 'block_open_below_average', 'block_open_floor', 'block_grace_days', 'block_target_multiple']))
        ->toBe(['block_open_below' => 40, 'block_open_below_average' => 50, 'block_open_floor' => 5, 'block_grace_days' => 3, 'block_target_multiple' => '2.0'])
        ->and(array_filter($source->fresh()->only(BlockRule::KEYS), fn (mixed $value): bool => $value !== null))->toBe([]);
});

it('adds the floor at 5 for a row saved before, a source’s own empty, and takes only the floor off again', function () {
    $source = TrafficSource::factory()->create(['block_open_below' => 40]);
    DB::table('traffic_settings')->insert(['id' => 1, 'block_open_below' => 45, 'created_at' => now(), 'updated_at' => now()]);
    blockOpenFloorMigration()->down();

    expect(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_open_floor'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_sources', 'block_open_floor'))->toBeFalse()
        // The rest of the rule stays, with what was saved in it.
        ->and(DB::getSchemaBuilder()->hasColumns('traffic_sources', array_values(array_diff(BlockRule::KEYS, ['block_open_floor']))))->toBeTrue()
        ->and((int) DB::table('traffic_settings')->value('block_open_below'))->toBe(45)
        ->and((int) DB::table('traffic_sources')->where('id', $source->id)->value('block_open_below'))->toBe(40);

    blockOpenFloorMigration()->up();

    expect(TrafficSetting::current()->only(['block_open_below', 'block_open_floor']))->toBe(['block_open_below' => 45, 'block_open_floor' => 5])
        ->and($source->fresh()->only(['block_open_below', 'block_open_floor']))->toBe(['block_open_below' => 40, 'block_open_floor' => null]);
});

it('keeps a source’s own thresholds as set, amounts to their columns’ decimals', function () {
    $source = TrafficSource::factory()->create([
        'block_bot_clicks' => 100000, 'block_open_below' => 100, 'block_open_below_average' => 1, 'block_open_floor' => 100, 'block_min_spend' => '9999.99', 'block_min_clicks' => 1000000,
        'block_roi_below' => -100, 'block_grace_days' => 30, 'block_target_multiple' => '12.5', 'block_start_clicks' => 60, 'block_start_percent' => 100,
    ]);
    // A floor of 0, the general one turned off for this source, is kept as 0, not as none.
    $off = TrafficSource::factory()->create(['block_open_floor' => 0]);

    expect($source->fresh()->only(BlockRule::KEYS))->toBe([
        'block_bot_clicks' => 100000, 'block_open_below' => 100, 'block_open_below_average' => 1, 'block_open_floor' => 100, 'block_min_spend' => '9999.99', 'block_min_clicks' => 1000000,
        'block_roi_below' => -100, 'block_grace_days' => 30, 'block_target_multiple' => '12.5', 'block_start_clicks' => 60, 'block_start_percent' => 100,
    ])->and($off->fresh()->block_open_floor)->toBe(0)
        ->and(DB::table('traffic_settings')->insertGetId(['created_at' => now(), 'updated_at' => now()]))->toBeInt()
        ->and(TrafficSetting::query()->firstOrFail()->only(['block_open_below_average', 'block_open_floor', 'block_grace_days', 'block_target_multiple']))
        ->toBe(['block_open_below_average' => 50, 'block_open_floor' => 5, 'block_grace_days' => 3, 'block_target_multiple' => '2.0']);
});
