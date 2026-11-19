<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The attempts played on a visit, found from the visit: Analytics
        // counts the attempts and the result shares of a period's visits,
        // across every game, by the visit each attempt was started on. An
        // index alone, so SQLite adds it in place and the table keeps its
        // CHECKs.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('game_attempts', function (Blueprint $table) {
            $table->index('traffic_visit_id', 'game_attempts_traffic_visit_index');
        });
    }

    public function down(): void
    {
        Schema::table('game_attempts', function (Blueprint $table) {
            $table->dropIndex('game_attempts_traffic_visit_index');
        });
    }
};
