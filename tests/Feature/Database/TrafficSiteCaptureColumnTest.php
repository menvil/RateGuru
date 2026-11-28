<?php

use App\Models\TrafficSetting;
use Illuminate\Support\Facades\DB;

/**
 * Record site visits is a switch of the tracker's settings row, off on every
 * engine until the administrator turns it on — a row saved before it reads
 * off too — and its column comes off again with the migration's down().
 */
function siteCaptureMigration(): object
{
    return require database_path('migrations/2026_11_18_100400_add_site_capture_enabled_to_traffic_settings_table.php');
}

it('adds Record site visits off, for a row saved without it as well, and takes it off again', function () {
    DB::table('traffic_settings')->insert(['id' => 1, 'capture_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    expect(TrafficSetting::current())->capture_enabled->toBeTrue()->site_capture_enabled->toBeFalse()
        ->and(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => false, 'presence' => false]);

    siteCaptureMigration()->down();
    expect(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'site_capture_enabled'))->toBeFalse();

    siteCaptureMigration()->up();
    expect(DB::getSchemaBuilder()->hasColumn('traffic_settings', 'site_capture_enabled'))->toBeTrue()
        ->and((bool) DB::table('traffic_settings')->where('id', 1)->value('site_capture_enabled'))->toBeFalse()
        ->and((bool) DB::table('traffic_settings')->where('id', 1)->value('capture_enabled'))->toBeTrue();
});

it('reads the switches once into the cache, and afresh once the row is saved', function () {
    expect(TrafficSetting::captureFlags())->toBe(['capture' => false, 'site' => false, 'presence' => false]);

    // Kept by the cache: the row changed under it is not read again …
    DB::table('traffic_settings')->insert(['id' => 1, 'capture_enabled' => true, 'site_capture_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect(TrafficSetting::captureFlags())->toBe(['capture' => false, 'site' => false, 'presence' => false]);

    // … until the row is saved as a model.
    TrafficSetting::query()->find(1)->forceFill(['postbacks_enabled' => true])->save();
    expect(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => true, 'presence' => false]);
});
