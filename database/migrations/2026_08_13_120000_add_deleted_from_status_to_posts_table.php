<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Pre-deletion status captured on author delete so restore can
            // return the exact prior state. Plain string, no DB-native enum
            // (PostgreSQL/MariaDB/SQLite portability).
            $table->string('deleted_from_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('deleted_from_status');
        });
    }
};
