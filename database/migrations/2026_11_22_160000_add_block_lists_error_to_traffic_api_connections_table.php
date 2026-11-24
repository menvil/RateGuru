<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why the connection's last read of its campaigns' block lists failed
        // — a TrafficConnectorFailure — and the campaign, by the network's ID,
        // whose list it couldn't read, read at block_lists_read_at: the
        // source's Blocking pane says so until a read goes through (docs/
        // architecture/traffic.md, "Sync"). Empty on a connection whose lists
        // were read, or never were. Nullable columns alone, added in place on
        // every engine, waited for briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->string('block_lists_error', 40)->nullable();
            $table->string('block_lists_error_campaign', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_api_connections', function (Blueprint $table) {
            $table->dropColumn(['block_lists_error', 'block_lists_error_campaign']);
        });
    }
};
