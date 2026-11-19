<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The block rule's floor (docs/architecture/traffic.md, "Blocking"):
        // below this share of its clicks opening the page a site is bots
        // whatever its source's average — a source that is nearly all bots
        // averages too little, and one with a single site can't fall below a
        // share of itself. Added with its default, so a row saved before
        // reads 5 at once; 0 turns the floor off.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('block_open_floor')->default(5)->after('block_open_below_average');
        });

        // A network source's own floor in place of the general one; empty keeps the general one.
        Schema::table('traffic_sources', function (Blueprint $table) {
            $table->unsignedTinyInteger('block_open_floor')->nullable()->after('block_open_below_average');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_sources', function (Blueprint $table) {
            $table->dropColumn('block_open_floor');
        });

        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->dropColumn('block_open_floor');
        });
    }
};
