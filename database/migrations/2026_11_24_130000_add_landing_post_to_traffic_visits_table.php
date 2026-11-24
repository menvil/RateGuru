<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The post a session landed on (a post's own page), on each of its
        // visits, for the registration funnel's landing filter
        // (docs/architecture/traffic.md, "Registration steps"); kept as long
        // as the posts a visit opened are, then let go. No foreign key: a
        // post deleted keeps its number here, as the posts a visit opened
        // do. A nullable column, added in place on every engine, waited for
        // briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('landing_post_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn('landing_post_id');
        });
    }
};
