<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            // Which social sign-in providers the admin has turned on, as
            // {"google": true, "facebook": false}. A provider missing from
            // it — or the whole column being null — is on. Kept apart from
            // feature_flags on purpose: applying a preset replaces the
            // feature flags and must never switch a way of signing in off.
            $table->json('sign_in_providers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn('sign_in_providers');
        });
    }
};
