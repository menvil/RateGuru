<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a member first voted, commented or made a post on the visit
        // (RecordUserActivityAction), beside the counts of them: a journey
        // draws what they did at that moment, not at the first post they
        // opened — a guest who opens a post and signs up there to vote voted
        // after signing up. Empty on the visits before it, and on every visit
        // a member did nothing on. A nullable column alone, added in place on
        // every engine.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dateTime('acted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn('acted_at');
        });
    }
};
