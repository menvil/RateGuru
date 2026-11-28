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

        Schema::table('traffic_visits', function (Blueprint $table) {
            // What the landing page's script saw (RecordVisitPresenceAction): when the page was first on screen, when
            // the visitor first touched, clicked, typed or scrolled it, and what about the browser contradicts what it
            // says it is (PresenceChecks), as bits.
            $table->dateTime('seen_at')->nullable();
            $table->dateTime('engaged_at')->nullable();
            $table->unsignedInteger('quality_flags')->default(0);
        });

        Schema::table('traffic_visit_details', function (Blueprint $table) {
            // The facts the script sent, kept as long as the visit's details are, so the checks can be tuned again.
            $table->json('presence')->nullable();
        });

        Schema::table('traffic_settings', function (Blueprint $table) {
            // Off until the privacy page says so: only then may landing pages measure what visitors see and do.
            $table->boolean('presence_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->dropColumn('presence_enabled');
        });

        Schema::table('traffic_visit_details', function (Blueprint $table) {
            $table->dropColumn('presence');
        });

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn(['seen_at', 'engaged_at', 'quality_flags']);
        });
    }
};
