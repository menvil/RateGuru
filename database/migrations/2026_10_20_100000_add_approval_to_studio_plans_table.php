<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who approved a plan, and when. Written only by its approval; every
        // plan made before it, and every Draft, has neither. Accounts become
        // tombstones and are never deleted, so nothing may drop the reference
        // silently.
        if (DB::getDriverName() === 'sqlite') {
            // Added in place, reference and all: a foreign key added on its
            // own has SQLite rebuild the table, which drops the CHECKs that
            // hold the plan's status and mode to their closed sets.
            DB::statement('alter table "studio_plans" add column "approved_by_user_id" integer references "users" ("id") on delete restrict');
        } else {
            Schema::table('studio_plans', function (Blueprint $table) {
                $table->foreignId('approved_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->restrictOnDelete();
            });
        }

        Schema::table('studio_plans', function (Blueprint $table) {
            $table->dateTime('approved_at')->nullable()->after('approved_by_user_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Dropped in place too, for the same reason.
            DB::statement('alter table "studio_plans" drop column "approved_at"');
            DB::statement('alter table "studio_plans" drop column "approved_by_user_id"');

            return;
        }

        Schema::table('studio_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
        });
    }
};
