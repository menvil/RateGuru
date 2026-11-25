<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('users', function (Blueprint $table) {
            // When the member was last seen on the site, touched at most every few minutes (TrackMemberVisit).
            $table->dateTime('last_seen_at')->nullable();
            // When their previous visit ended: a gap of more than an hour since last_seen_at starts a new visit. The
            // feed counts a post in their language as fresh from here on — what came out since they were last here.
            $table->dateTime('previous_visit_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'previous_visit_at']);
        });
    }
};
