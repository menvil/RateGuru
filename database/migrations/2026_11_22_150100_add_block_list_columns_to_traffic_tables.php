<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether a batch of the blocks log went part of the way — some of
        // its sites or campaigns took it, not all — where `ok` says it went
        // all the way; and when a connection last read its campaigns' block
        // lists, which the network sync does every six hours (docs/
        // architecture/traffic.md, "Blocking"). A batch logged before went
        // all the way or not at all; a connection never read its lists.
        // Columns with a default or nullable alone, added in place on every
        // engine.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_block_events', function (Blueprint $table) {
            $table->boolean('partial')->default(false);
        });

        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->dateTime('block_lists_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->dropColumn('block_lists_read_at');
        });

        Schema::table('traffic_block_events', function (Blueprint $table) {
            $table->dropColumn('partial');
        });
    }
};
