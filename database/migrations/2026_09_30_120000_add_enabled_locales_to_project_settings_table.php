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
            // The installed languages this project offers its visitors, as a
            // list of codes. NULL — every existing row, and any project that
            // never narrowed it — means every installed language, so adding
            // the column changes nothing for a running installation. No column
            // default: MySQL and MariaDB forbid one on JSON columns.
            $table->json('enabled_locales')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn('enabled_locales');
        });
    }
};
