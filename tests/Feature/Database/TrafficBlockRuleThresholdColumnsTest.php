<?php

use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Support\Traffic\Blocking\BlockRule;
use Illuminate\Support\Facades\DB;

/**
 * The gentler block rule's columns, on every engine: the general share of a
 * source's average, the days after a first click and the multiple of a
 * target price, each with its default — a settings row saved before reads
 * half, three and twice — and a network source's own thresholds, empty
 * until it sets one; all of them taken off again by the migration's down().
 */
function blockRuleThresholdsMigration(): object
{
    return require database_path('migrations/2026_11_18_100900_add_block_rule_thresholds_to_traffic_sources_and_settings.php');
}

it('adds the general thresholds with their defaults, for a row saved before as well, and a source’s own empty, and takes them off again', function () {
    $source = TrafficSource::factory()->create();
    blockRuleThresholdsMigration()->down();

    expect(DB::getSchemaBuilder()->hasColumns('traffic_settings', ['block_open_below_average']))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_grace_days'))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'block_target_multiple'))->toBeFalse()
        ->and(collect(BlockRule::KEYS)->filter(fn (string $column): bool => DB::getSchemaBuilder()->hasColumn('traffic_sources', $column))->all())->toBe([]);

    // A row saved before the columns came, with a threshold of its own.
    DB::table('traffic_settings')->insert(['id' => 1, 'block_open_below' => 40, 'created_at' => now(), 'updated_at' => now()]);

    blockRuleThresholdsMigration()->up();

    expect(DB::getSchemaBuilder()->hasColumns('traffic_sources', BlockRule::KEYS))->toBeTrue()
        ->and(TrafficSetting::current()->only(['block_open_below', 'block_open_below_average', 'block_grace_days', 'block_target_multiple']))
        ->toBe(['block_open_below' => 40, 'block_open_below_average' => 50, 'block_grace_days' => 3, 'block_target_multiple' => '2.0'])
        ->and(array_filter($source->fresh()->only(BlockRule::KEYS), fn (mixed $value): bool => $value !== null))->toBe([]);
});

it('keeps a source’s own thresholds as set, amounts to their columns’ decimals', function () {
    $source = TrafficSource::factory()->create([
        'block_bot_clicks' => 100000, 'block_open_below' => 100, 'block_open_below_average' => 1, 'block_min_spend' => '9999.99', 'block_min_clicks' => 1000000,
        'block_roi_below' => -100, 'block_grace_days' => 30, 'block_target_multiple' => '12.5', 'block_start_clicks' => 60, 'block_start_percent' => 100,
    ]);

    expect($source->fresh()->only(BlockRule::KEYS))->toBe([
        'block_bot_clicks' => 100000, 'block_open_below' => 100, 'block_open_below_average' => 1, 'block_min_spend' => '9999.99', 'block_min_clicks' => 1000000,
        'block_roi_below' => -100, 'block_grace_days' => 30, 'block_target_multiple' => '12.5', 'block_start_clicks' => 60, 'block_start_percent' => 100,
    ])->and(DB::table('traffic_settings')->insertGetId(['created_at' => now(), 'updated_at' => now()]))->toBeInt()
        ->and(TrafficSetting::query()->firstOrFail()->only(['block_open_below_average', 'block_grace_days', 'block_target_multiple']))
        ->toBe(['block_open_below_average' => 50, 'block_grace_days' => 3, 'block_target_multiple' => '2.0']);
});
