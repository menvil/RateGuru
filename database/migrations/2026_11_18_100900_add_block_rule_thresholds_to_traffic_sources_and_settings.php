<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> a network source's own thresholds, each named as the general one it stands in for */
    private const array SOURCE_COLUMNS = [
        'block_bot_clicks',
        'block_open_below',
        'block_open_below_average',
        'block_min_spend',
        'block_min_clicks',
        'block_roi_below',
        'block_grace_days',
        'block_target_multiple',
        'block_start_clicks',
        'block_start_percent',
    ];

    public function up(): void
    {
        // A gentler block rule (docs/architecture/traffic.md, "Blocking"): a
        // site is likely bots only when it opens the page less often than the
        // general share and than a share of its own source's average; losing
        // waits so many days after a site's first click, and where its
        // campaign or its source has a target price of an account, until it
        // has spent so many times that target. Added with their defaults, so
        // a row saved before reads them at once: half the source's average,
        // three days, twice the target.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('block_open_below_average')->default(50)->after('block_open_below');
            $table->unsignedTinyInteger('block_grace_days')->default(3)->after('block_roi_below');
            $table->decimal('block_target_multiple', 4, 1)->default(2)->after('block_grace_days');
        });

        // A network source's own thresholds, any of which it may set in place
        // of the general one of the same name; empty keeps the general one.
        Schema::table('traffic_sources', function (Blueprint $table) {
            $table->unsignedInteger('block_bot_clicks')->nullable()->after('block_daily_cap');
            $table->unsignedTinyInteger('block_open_below')->nullable()->after('block_bot_clicks');
            $table->unsignedTinyInteger('block_open_below_average')->nullable()->after('block_open_below');
            $table->decimal('block_min_spend', 10, 2)->nullable()->after('block_open_below_average');
            $table->unsignedInteger('block_min_clicks')->nullable()->after('block_min_spend');
            $table->smallInteger('block_roi_below')->nullable()->after('block_min_clicks');
            $table->unsignedTinyInteger('block_grace_days')->nullable()->after('block_roi_below');
            $table->decimal('block_target_multiple', 4, 1)->nullable()->after('block_grace_days');
            $table->unsignedInteger('block_start_clicks')->nullable()->after('block_target_multiple');
            $table->unsignedTinyInteger('block_start_percent')->nullable()->after('block_start_clicks');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_sources', function (Blueprint $table) {
            $table->dropColumn(self::SOURCE_COLUMNS);
        });

        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->dropColumn(['block_open_below_average', 'block_grace_days', 'block_target_multiple']);
        });
    }
};
