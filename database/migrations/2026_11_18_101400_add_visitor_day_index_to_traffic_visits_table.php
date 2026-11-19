<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether a browser was here before a day: Analytics asks it of
        // every person of a period — who came back — and through the
        // browser's index by id each question read the browser's visits
        // themselves, minutes for a month of a busy site. With the day
        // beside the browser, a browser never seen before the day costs one
        // look at the index and none at the visits. Whether the page ran is
        // left out on purpose: as a third column PostgreSQL prices the
        // index too dear to use for this question. An index alone, so
        // SQLite adds it in place.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->index(['visitor_hash', 'network_day'], 'traffic_visits_visitor_day_index');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropIndex('traffic_visits_visitor_day_index');
        });
    }
};
