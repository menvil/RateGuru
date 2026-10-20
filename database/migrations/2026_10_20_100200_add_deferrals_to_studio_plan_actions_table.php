<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many of an action's sends ended with its Persona at its posting
        // limit, the action sent back to wait: counted apart from `attempts`,
        // which keeps counting every send for the run's fence, so that a
        // backlog the limit spreads out is not given up as failed deliveries.
        if (DB::getDriverName() === 'sqlite') {
            // Added in place: a table SQLite rebuilds loses the CHECK that
            // holds the action's status to its closed set.
            DB::statement('alter table "studio_plan_actions" add column "deferrals" integer not null default \'0\'');

            return;
        }

        Schema::table('studio_plan_actions', function (Blueprint $table) {
            $table->unsignedInteger('deferrals')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Dropped in place too, for the same reason.
            DB::statement('alter table "studio_plan_actions" drop column "deferrals"');

            return;
        }

        Schema::table('studio_plan_actions', function (Blueprint $table) {
            $table->dropColumn('deferrals');
        });
    }
};
