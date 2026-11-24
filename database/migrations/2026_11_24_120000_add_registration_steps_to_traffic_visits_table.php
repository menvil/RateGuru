<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The registration steps a visit reaches between the sign-in dialog
        // and an account (docs/architecture/traffic.md, "Registration
        // steps"): the dialog opened and what opened it (AuthTrigger), the
        // registration form seen, Google or Facebook started and which, the
        // email form sent, and a sign-up refused with every reason met
        // (SignupRefusal, a bit each). Recorded from the deploy on, nothing
        // filled in for the visits before. Nullable columns and a constant
        // default, added in place on every engine, waited for briefly,
        // never behind a long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dateTime('auth_opened_at')->nullable();
            $table->string('auth_trigger', 16)->nullable();
            $table->dateTime('register_form_at')->nullable();
            $table->dateTime('social_started_at')->nullable();
            $table->string('social_provider', 16)->nullable();
            $table->dateTime('signup_submitted_at')->nullable();
            $table->dateTime('signup_refused_at')->nullable();
            $table->unsignedSmallInteger('signup_refusals')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn([
                'auth_opened_at', 'auth_trigger', 'register_form_at', 'social_started_at', 'social_provider',
                'signup_submitted_at', 'signup_refused_at', 'signup_refusals',
            ]);
        });
    }
};
