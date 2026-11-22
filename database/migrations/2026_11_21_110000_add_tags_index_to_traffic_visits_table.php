<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The visits that came by a set of five campaign tags: Analytics →
        // Tracking links asks of every link listed whether any visit ever
        // came through it, and the editor whether a link is locked. Through
        // the campaign's index alone each question read every visit of the
        // campaign before it found the first; with all five tags in one
        // index it is one look. An index alone, so SQLite adds it in place.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->index(['utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term'], 'traffic_visits_tags_index');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropIndex('traffic_visits_tags_index');
        });
    }
};
