<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many times a block has been queued for the network — blocked
        // again, pushed again, unblocked, or sent to the campaigns missing
        // it: a sync batch settles a block only while it is still the
        // request the batch read. Its state alone can't tell a block
        // unblocked and blocked again while the network was being called
        // from one nobody touched (docs/architecture/traffic.md, "Blocks").
        // A column with a constant default, added in place on every engine,
        // waited for briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_blocks', function (Blueprint $table) {
            $table->unsignedInteger('request_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('traffic_blocks', function (Blueprint $table) {
            $table->dropColumn('request_version');
        });
    }
};
