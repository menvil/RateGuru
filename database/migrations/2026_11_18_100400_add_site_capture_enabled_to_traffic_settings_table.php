<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Record site visits: whether the feed, posts, profiles and the site's
        // own pages record a visit too, beside the games and paid clicks. It
        // counts only while Record visits is on, and is off until the
        // administrator turns it on.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->boolean('site_capture_enabled')->default(false)->after('capture_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->dropColumn('site_capture_enabled');
        });
    }
};
