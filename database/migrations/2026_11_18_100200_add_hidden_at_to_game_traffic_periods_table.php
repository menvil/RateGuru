<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When an ended period was taken out of Growth → Experiments
        // (HideGameTrafficPeriodAction): its numbers stay where they are, and
        // who took it out is a log line, not a column.
        // Added in place on SQLite: a table SQLite rebuilds loses the CHECK on
        // its audience.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table "game_traffic_periods" add column "hidden_at" datetime');

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('game_traffic_periods', function (Blueprint $table) {
            $table->dateTime('hidden_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('alter table "game_traffic_periods" drop column "hidden_at"');

            return;
        }

        Schema::table('game_traffic_periods', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
