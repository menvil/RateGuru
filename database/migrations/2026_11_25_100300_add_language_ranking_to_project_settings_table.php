<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            // How the feed and the threads put the viewer's language first (App\Support\Languages\LanguageRankingSettings).
            // Null until an administrator saves it: ProjectSettingsManager reads the defaults under whatever it holds,
            // as it does the feature flags. A column of its own, so that applying a preset never resets it.
            $table->json('language_ranking')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn('language_ranking');
        });
    }
};
