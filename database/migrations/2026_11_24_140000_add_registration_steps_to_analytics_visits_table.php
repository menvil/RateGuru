<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A visit's registration steps on its row (traffic_visits'
        // "Registration steps"): a flag each, what opened the dialog, every
        // reason a sign-up was refused and the post the session landed on,
        // what the registration funnel and its landing filter read. Rows of
        // visits from before compare equal with the defaults, so nothing is
        // rewritten for them. Columns with constant defaults, added in place
        // on every engine, waited for briefly, never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->boolean('auth_opened')->default(false);
            $table->string('auth_trigger', 16)->nullable();
            $table->boolean('register_form')->default(false);
            $table->boolean('social_started')->default(false);
            $table->boolean('signup_submitted')->default(false);
            $table->boolean('signup_refused')->default(false);
            $table->unsignedSmallInteger('signup_refusals')->default(0);
            $table->unsignedBigInteger('landing_post_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_visits', function (Blueprint $table) {
            $table->dropColumn(['auth_opened', 'auth_trigger', 'register_form', 'social_started', 'signup_submitted', 'signup_refused', 'signup_refusals', 'landing_post_id']);
        });
    }
};
