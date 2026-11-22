<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The events of an attempt by type: whether it saw its result (a
        // traffic period's figures ask it of every attempt), the sign-in form
        // a post showed it, an exit by where it went. With the type and step
        // beside the attempt the question is answered from the index alone,
        // where the attempt's own index fetched every event of the attempt to
        // look at its type. It serves everything the attempt's index did —
        // the foreign key, and the events of one attempt — so that one goes,
        // once this one is there: MariaDB keeps an index a foreign key uses
        // until another begins with the same column. Indexes alone, so
        // SQLite changes them in place.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('game_events', function (Blueprint $table) {
            $table->index(['attempt_id', 'type', 'step_type'], 'game_events_attempt_type_index');
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->dropIndex('game_events_attempt_index');
        });
    }

    public function down(): void
    {
        // The attempt's own index back before this one goes, for the same reason.
        Schema::table('game_events', function (Blueprint $table) {
            $table->index('attempt_id', 'game_events_attempt_index');
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->dropIndex('game_events_attempt_type_index');
        });
    }
};
