<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a new account first came back on one of the days after it
        // signed up and did something (RecordUserActivityAction), and the
        // accounts by the moment they signed up, as cohorts read them.
        Schema::table('account_attributions', function (Blueprint $table) {
            $table->dateTime('activated_at')->nullable();

            $table->index('signed_up_at', 'account_attributions_signed_up_index');
        });

        // The same moment on the visit the account was credited to, once the
        // account is confirmed (TrafficEvent::Activated): counted only, never
        // told to a network.
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dateTime('activated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_visits', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });

        Schema::table('account_attributions', function (Blueprint $table) {
            $table->dropIndex('account_attributions_signed_up_index');
            $table->dropColumn('activated_at');
        });
    }
};
