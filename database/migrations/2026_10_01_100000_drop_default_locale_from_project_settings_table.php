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
            // The default language is system policy now — English, from
            // config/locales.php — so a per-project default has nothing left
            // to decide.
            $table->dropColumn('default_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            // As the column was first created: every row back on English.
            $table->string('default_locale', 12)->default('en');
        });
    }
};
